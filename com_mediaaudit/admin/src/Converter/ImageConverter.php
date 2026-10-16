<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Converter;

use Gss\Component\Mediaaudit\Administrator\Scanner\FileScanner;
use Gss\Component\Mediaaudit\Administrator\Service\CaseSensitiveFilesystem;
use Gss\Component\Mediaaudit\Administrator\Service\FilePlacer;
use Gss\Component\Mediaaudit\Administrator\Service\NormalizedPath;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Writes images as AVIF or WebP, next to the image, and keeps what it did in
 * #__mediaaudit_conversions.
 *
 * The image itself stays where it is and as it is. Nothing is written over, nothing is deleted
 * below images/: the new file is written to the folder for temporary files first, and is only
 * moved next to the image when it is smaller by enough.
 */
final class ImageConverter
{
    public const CONVERTED   = 'converted';
    public const NOT_SMALLER = 'not_smaller';
    public const FAILED      = 'failed';

    private CaseSensitiveFilesystem $disk;

    public function __construct(
        private DatabaseInterface $db,
        private string $root,
        private string $tmp,
        private Encoder $encoder,
        private ?FileScanner $files = null
    ) {
        $this->root = rtrim($root, '/');
        $this->tmp  = rtrim($tmp, '/');
        $this->disk = new CaseSensitiveFilesystem($this->root);
    }

    public function getEncoder(): Encoder
    {
        return $this->encoder;
    }

    /**
     * @param   string  $path       Of the image, relative to the root of the site.
     * @param   string  $format     "avif" or "webp".
     * @param   int     $quality    1 to 100.
     * @param   int     $minSaving  Percent that the new file has to be smaller by.
     * @param   int     $userId     Who asked for it.
     * @param   int     $maxPixels  Larger images are left alone.
     *
     * @return  array{status: string, source: string, target: string|null, source_size: int, target_size: int, error: string, reused: bool}
     *          Reused when the image was converted before and is as it was then.
     */
    public function convert(string $path, string $format, int $quality, int $minSaving = 10, int $userId = 0, int $maxPixels = 40000000): array
    {
        $result = ['status' => self::FAILED, 'source' => $path, 'target' => null, 'source_size' => 0, 'target_size' => 0, 'error' => '', 'reused' => false];

        if (!\in_array($format, TargetNamer::FORMATS, true)) {
            throw new \InvalidArgumentException('Unknown format: ' . $format);
        }

        // Not through a link: what it leads to can be any file of the server
        if (!TargetNamer::isConvertible($path) || !$this->disk->isFile($path) || is_link($this->root . '/' . $path) || !$this->disk->isInside($path, FileScanner::FOLDER)) {
            $result['error'] = 'Not an image of this site that can be converted.';

            return $result;
        }

        $file   = $this->root . '/' . $path;
        $sha1   = (string) sha1_file($file);
        $hash   = NormalizedPath::hashOf($path);
        $before = $this->row($hash, $format);

        $result['source_size'] = (int) filesize($file);

        if ($before !== null && $before->source_sha1 === $sha1) {
            if ($before->status === self::CONVERTED && $before->target_path !== null && $this->isOurs($before->target_path, (int) $before->target_size)) {
                return ['status' => self::CONVERTED, 'target' => $before->target_path, 'target_size' => (int) $before->target_size, 'reused' => true] + $result;
            }

            if ($before->status === self::NOT_SMALLER && (int) $before->quality === $quality) {
                return ['status' => self::NOT_SMALLER, 'target_size' => (int) $before->target_size, 'reused' => true] + $result;
            }
        }

        $part    = $this->tmp . '/mediaaudit-' . $hash . '-' . bin2hex(random_bytes(4)) . '.' . $format;
        $encoder = '';

        try {
            $encoder = $this->encoder->encode($file, $part, $format, $quality, $maxPixels);

            $result['target_size'] = (int) filesize($part);

            if ($result['target_size'] > $result['source_size'] * (100 - max(0, min(99, $minSaving))) / 100) {
                $result['status'] = self::NOT_SMALLER;
            } else {
                $result['target'] = $this->place($part, $path, $format, $sha1);
                $result['status'] = self::CONVERTED;
            }
        } catch (\Throwable $e) {
            $result['error'] = mb_substr(mb_scrub($e->getMessage(), 'UTF-8'), 0, 255, 'UTF-8');
        } finally {
            // In the folder for temporary files: nothing of the site
            if (is_file($part)) {
                @unlink($part);
            }
        }

        $this->remember($before, $hash, $sha1, $format, $quality, $encoder, $userId, $result);

        if ($result['target'] !== null && $this->files !== null) {
            $this->files->add([$result['target']]);
        }

        return $result;
    }

    /**
     * The images that have a converted file on disk.
     *
     * @param   string|null  $format  Only of this format.
     * @param   string[]     $hashes  Only of the images with these hashes of their paths.
     *
     * @return  array<string, array{source: string, target: string}>  By the hash of the path of the image
     */
    public function getTargets(?string $format = null, array $hashes = []): array
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['source_hash', 'source_path', 'target_path', 'target_size']))
            ->from($db->quoteName('#__mediaaudit_conversions'))
            ->where($db->quoteName('status') . ' = ' . $db->quote(self::CONVERTED))
            ->where($db->quoteName('target_path') . ' IS NOT NULL')
            // The last one wins when an image was converted to both formats
            ->order($db->quoteName('converted_at') . ' ASC, ' . $db->quoteName('id') . ' ASC');

        if ($format !== null) {
            $query->where($db->quoteName('format') . ' = ' . $db->quote($format));
        }

        if ($hashes) {
            $query->where($db->quoteName('source_hash') . ' IN (' . implode(',', array_map([$db, 'quote'], array_unique($hashes))) . ')');
        }

        $targets = [];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            if ($this->isOurs($row->target_path, (int) $row->target_size)) {
                $targets[$row->source_hash] = ['source' => $row->source_path, 'target' => $row->target_path];
            }
        }

        return $targets;
    }

    /**
     * Whether the file at the path is the one that was written there. When that one was moved away
     * and another one took its name, content must not be made to load it: whoever can upload a file
     * could choose what other people's articles show.
     */
    private function isOurs(string $path, int $size): bool
    {
        $file = $this->root . '/' . $path;

        return is_file($file) && !is_link($file) && (int) filesize($file) === $size;
    }

    /**
     * Moves the new file next to the image, under the first name that is free.
     */
    private function place(string $part, string $source, string $format, string $sha1): string
    {
        foreach (TargetNamer::candidates($source, $format, $sha1) as $target) {
            $file = $this->root . '/' . $target;

            // Also true for a name that differs by case only, where the disk does not tell them apart
            if (file_exists($file) || is_link($file)) {
                continue;
            }

            if (!FilePlacer::place($part, $file)) {
                // Taken between the look and the write: the next name
                if (file_exists($file) || is_link($file)) {
                    continue;
                }

                throw new \RuntimeException('Could not write to ' . \dirname($target) . '.');
            }

            @chmod($file, 0644);

            return $target;
        }

        throw new \RuntimeException('Every name for the new file is taken.');
    }

    private function row(string $hash, string $format): ?object
    {
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__mediaaudit_conversions'))
            ->where($this->db->quoteName('source_hash') . ' = ' . $this->db->quote($hash))
            ->where($this->db->quoteName('format') . ' = ' . $this->db->quote($format));

        return $this->db->setQuery($query)->loadObject() ?: null;
    }

    /**
     * @param  array{status: string, source: string, target: string|null, source_size: int, target_size: int, error: string}  $result
     */
    private function remember(?object $before, string $hash, string $sha1, string $format, int $quality, string $encoder, int $userId, array $result): void
    {
        $row = (object) [
            'source_path'  => $result['source'],
            'source_hash'  => $hash,
            'source_sha1'  => $sha1,
            'source_size'  => $result['source_size'],
            'target_path'  => $result['target'],
            'target_hash'  => $result['target'] === null ? null : NormalizedPath::hashOf($result['target']),
            'target_size'  => $result['target_size'],
            'format'       => $format,
            'quality'      => $quality,
            'encoder'      => $encoder,
            'status'       => $result['status'],
            'error'        => $result['error'],
            'converted_at' => gmdate('Y-m-d H:i:s'),
            'converted_by' => $userId,
        ];

        if ($before !== null) {
            $row->id = (int) $before->id;
            $this->db->updateObject('#__mediaaudit_conversions', $row, 'id', true);

            return;
        }

        $this->db->insertObject('#__mediaaudit_conversions', $row);
    }
}
