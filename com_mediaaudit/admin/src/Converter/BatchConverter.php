<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Converter;

use Gss\Component\Mediaaudit\Administrator\Report\ReportRepository;
use Gss\Component\Mediaaudit\Administrator\Service\NormalizedPath;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Converts the images of the site a batch at a time: so many images, for so long, and the rest
 * with the next batch.
 */
final class BatchConverter
{
    /** Days before an image that failed is tried again */
    private const RETRY_DAYS = 7;

    /** @var callable|null */
    private $convertible;

    /**
     * @param  callable|null  $convertible  function (): array. What SourceRewriter::getConvertible()
     *                                      returns: asked for when it is needed, because finding the
     *                                      extractors of the plugins takes a while.
     */
    public function __construct(private DatabaseInterface $db, private ImageConverter $converter, ?callable $convertible = null)
    {
        $this->convertible = $convertible;
    }

    /**
     * The images to convert, the largest first: they are what there is the most to save of.
     *
     * Without ids, the images that content refers to in a way that is rewritten, and that were not
     * tried yet as they are now. Files that a component made of another image are left out: their
     * names are what the component finds them by.
     *
     * @param   int[]  $ids  Only these files of the index, referred to or not.
     *
     * @return  list<array{id: int, path: string, size: int}>
     */
    public function candidates(string $format, int $limit = 0, array $ids = []): array
    {
        $db    = $this->db;
        $ids   = array_values(array_filter(array_map('intval', $ids)));
        $types = [];

        foreach (TargetNamer::SOURCES as $extension) {
            $types[] = 'LOWER(' . $db->quoteName('f.path') . ') LIKE ' . $db->quote('%.' . $extension, false);
        }

        $query = $db->getQuery(true)
            ->select($db->quoteName(['f.id', 'f.path', 'f.size']))
            ->from($db->quoteName('#__mediaaudit_files', 'f'))
            ->join(
                'LEFT',
                $db->quoteName('#__mediaaudit_conversions', 'c'),
                $db->quoteName('c.source_hash') . ' = ' . $db->quoteName('f.path_hash') . ' AND ' . $db->quoteName('c.format') . ' = ' . $db->quote($format)
            )
            ->where($db->quoteName('f.status') . ' = ' . $db->quote('active'))
            ->where('(' . implode(' OR ', $types) . ')')
            ->where('NOT ' . ReportRepository::isDerived($db, 'f'))
            ->order($db->quoteName('f.size') . ' DESC, ' . $db->quoteName('f.id') . ' ASC');

        if ($ids) {
            $query->where($db->quoteName('f.id') . ' IN (' . implode(',', $ids) . ')');
        } else {
            $query
                ->where(
                    $this->convertible === null
                        ? 'EXISTS (SELECT 1 FROM ' . $db->quoteName('#__mediaaudit_refs', 'r')
                            . ' WHERE ' . $db->quoteName('r.path_hash') . ' = ' . $db->quoteName('f.path_hash')
                            . ' AND ' . $db->quoteName('r.is_external') . ' = 0)'
                        : ReportRepository::isRewritten($db, 'f', ($this->convertible)())
                )
                ->where(
                    '(' . $db->quoteName('c.id') . ' IS NULL'
                    // The image is another one than the one that was tried
                    . ' OR (' . $db->quoteName('f.sha1') . ' IS NOT NULL AND ' . $db->quoteName('c.source_sha1') . ' <> ' . $db->quoteName('f.sha1') . ')'
                    . ' OR (' . $db->quoteName('c.status') . ' = ' . $db->quote(ImageConverter::FAILED)
                    . ' AND ' . $db->quoteName('c.converted_at') . ' < ' . $db->quote(gmdate('Y-m-d H:i:s', time() - self::RETRY_DAYS * 86400)) . '))'
                );
        }

        $images = [];

        foreach ($db->setQuery($query, 0, max(0, $limit))->loadObjectList() as $row) {
            if (TargetNamer::isConvertible($row->path)) {
                $images[] = ['id' => (int) $row->id, 'path' => $row->path, 'size' => (int) $row->size];
            }
        }

        return $images;
    }

    /**
     * How many images wait for a batch.
     */
    public function count(string $format): int
    {
        return \count($this->candidates($format));
    }

    /**
     * @param   int            $limit       The most images of the batch. 0 for no limit.
     * @param   float          $maxSeconds  Stop when the batch has taken this long. 0 for no limit.
     * @param   int[]          $ids         Only these files of the index.
     * @param   callable|null  $progress    function (int $done, int $total, string $path, ?array $result): void,
     *                                      before an image without a result, after it with.
     *
     * @return  array{images: int, converted: int, reused: int, not_smaller: int, failed: int, bytes_before: int, bytes_after: int, left: int, seconds: float, targets: array<string, array{source: string, target: string}>}
     *          The targets are the converted files of the batch, by the hash of the path of the image.
     */
    public function run(
        string $format,
        int $quality,
        int $minSaving = 10,
        int $limit = 0,
        float $maxSeconds = 0.0,
        array $ids = [],
        int $userId = 0,
        int $maxPixels = 40000000,
        bool $dryRun = false,
        ?callable $progress = null
    ): array {
        $start  = microtime(true);
        $all    = $this->candidates($format, 0, $ids);
        $images = $limit > 0 ? \array_slice($all, 0, $limit) : $all;
        $stats  = [
            'images' => \count($images), 'converted' => 0, 'reused' => 0, 'not_smaller' => 0, 'failed' => 0,
            'bytes_before' => 0, 'bytes_after' => 0, 'left' => \count($all), 'seconds' => 0.0, 'targets' => [],
        ];

        if ($dryRun) {
            return $stats;
        }

        $done = 0;

        foreach ($images as $image) {
            if ($maxSeconds > 0 && microtime(true) - $start >= $maxSeconds) {
                break;
            }

            if ($progress !== null) {
                $progress($done, \count($images), $image['path'], null);
            }

            $result = $this->converter->convert($image['path'], $format, $quality, $minSaving, $userId, $maxPixels);

            $done++;
            $stats[$result['status']]++;

            if ($result['status'] === ImageConverter::CONVERTED) {
                $stats['reused']       += $result['reused'] ? 1 : 0;
                $stats['bytes_before'] += $result['source_size'];
                $stats['bytes_after']  += $result['target_size'];

                $stats['targets'][NormalizedPath::hashOf($image['path'])] = ['source' => $image['path'], 'target' => $result['target']];
            }

            if ($progress !== null) {
                $progress($done, \count($images), $image['path'], $result);
            }
        }

        $stats['left']    = \count($all) - $done;
        $stats['seconds'] = round(microtime(true) - $start, 2);

        return $stats;
    }
}
