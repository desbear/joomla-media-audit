<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Scanner;

use Gss\Component\Mediaaudit\Administrator\Service\NormalizedPath;
use Gss\Component\Mediaaudit\Administrator\Service\PathNormalizer;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Keeps #__mediaaudit_files in step with the images on disk. Reads the disk, never writes to it.
 */
final class FileScanner
{
    public const FOLDER            = 'images';
    public const QUARANTINE_FOLDER = 'images/_mediaaudit_quarantine';

    private const BATCH = 200;

    private string $root;

    public function __construct(private DatabaseInterface $db, string $root)
    {
        $this->root = rtrim($root, '/');
    }

    /**
     * @param   bool  $hash  Compute the SHA-1 of new and of changed files. Slow on large sites.
     *
     * @return  array{found: int, added: int, changed: int, unchanged: int, removed: int, hashed: int, unreadable: int}
     */
    public function scan(bool $hash = true): array
    {
        $stats = ['found' => 0, 'added' => 0, 'changed' => 0, 'unchanged' => 0, 'removed' => 0, 'hashed' => 0, 'unreadable' => 0];
        $known = $this->loadIndex();
        $seen  = [];
        $new   = [];
        $now   = gmdate('Y-m-d H:i:s');

        foreach ($this->files() as $path => $file) {
            $size  = @filesize($file);
            $mtime = @filemtime($file);

            if ($size === false || $mtime === false) {
                $stats['unreadable']++;

                continue;
            }

            $stats['found']++;

            $key         = NormalizedPath::hashOf($path);
            $mtime       = gmdate('Y-m-d H:i:s', $mtime);
            $seen[$key]  = true;
            $row         = $known[$key] ?? null;

            if ($row === null) {
                $new[] = [$path, $key, $size, $mtime, $this->hash($file, $hash, $stats)];

                if (\count($new) >= self::BATCH) {
                    $this->insert($new, $now);
                    $new = [];
                }

                $stats['added']++;

                continue;
            }

            $same = (int) $row->size === $size && $row->mtime === $mtime;

            if ($same && $row->status === 'active' && ($row->sha1 !== null || !$hash)) {
                $stats['unchanged']++;

                continue;
            }

            // Changed, or never hashed, or back at the place it was quarantined from
            $sha1 = $same && $row->sha1 !== null ? $row->sha1 : $this->hash($file, $hash, $stats);

            $this->update((int) $row->id, $size, $mtime, $sha1);

            $stats[$same ? 'unchanged' : 'changed']++;
        }

        if ($new) {
            $this->insert($new, $now);
        }

        $stats['removed'] = $this->forget($known, $seen);

        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__mediaaudit_files'))
            ->set($this->db->quoteName('scanned_at') . ' = ' . $this->db->quote($now))
            ->where($this->db->quoteName('status') . ' = ' . $this->db->quote('active'));
        $this->db->setQuery($query)->execute();

        return $stats;
    }

    /**
     * Adds files to the index without going through the folders: what was just stored, or what a
     * reference points to and the last scan did not see yet.
     *
     * @param   string[]  $paths  Relative to the root, normalized.
     *
     * @return  int  How many were added or updated.
     */
    public function add(array $paths, bool $hash = true): int
    {
        $stats = ['hashed' => 0];
        $now   = gmdate('Y-m-d H:i:s');
        $count = 0;

        foreach (array_unique($paths) as $path) {
            $file = $this->root . '/' . $path;

            if (!self::covers($path) || !is_file($file)) {
                continue;
            }

            $key   = NormalizedPath::hashOf($path);
            $query = $this->db->getQuery(true)
                ->select($this->db->quoteName('id'))
                ->from($this->db->quoteName('#__mediaaudit_files'))
                ->where($this->db->quoteName('path_hash') . ' = ' . $this->db->quote($key));

            $id    = (int) $this->db->setQuery($query)->loadResult();
            $size  = (int) filesize($file);
            $mtime = gmdate('Y-m-d H:i:s', (int) filemtime($file));
            $sha1  = $this->hash($file, $hash, $stats);

            if ($id) {
                $this->update($id, $size, $mtime, $sha1);
            } else {
                $this->insert([[$path, $key, $size, $mtime, $sha1]], $now);
            }

            $count++;
        }

        return $count;
    }

    /**
     * Whether the scanner would index the path: an image below images/, outside the quarantine.
     */
    public static function covers(string $path): bool
    {
        return str_starts_with($path, self::FOLDER . '/')
            && !str_starts_with($path, self::QUARANTINE_FOLDER . '/')
            && PathNormalizer::looksLikeImage($path);
    }

    /**
     * @return  \Generator<string, string>  Path relative to the root => absolute path
     */
    private function files(): \Generator
    {
        $folder = $this->root . '/' . self::FOLDER;

        if (!is_dir($folder)) {
            return;
        }

        $quarantine = $this->root . '/' . self::QUARANTINE_FOLDER;

        $filter = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO),
            static function (\SplFileInfo $current) use ($quarantine): bool {
                $name = $current->getFilename();

                // Hidden files and folders: .htaccess, .tmp, .DS_Store
                if ($name === '' || $name[0] === '.') {
                    return false;
                }

                if ($current->isDir()) {
                    return !$current->isLink() && $current->getPathname() !== $quarantine;
                }

                return $current->isFile() && strtolower($name) !== 'index.html' && PathNormalizer::looksLikeImage($name);
            }
        );

        $offset = \strlen($this->root) + 1;

        try {
            foreach (new \RecursiveIteratorIterator($filter, \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD) as $file) {
                $path = PathNormalizer::composeUnicode(str_replace('\\', '/', substr($file->getPathname(), $offset)));

                // Longer than the column, or a name that is no text: left out rather than indexed by half,
                // and rather than stopping the scan. What is not in the index is never moved
                if (mb_check_encoding($path, 'UTF-8') && mb_strlen($path, 'UTF-8') <= 1024) {
                    yield $path => $file->getPathname();
                }
            }
        } catch (\UnexpectedValueException $e) {
            // A folder that went away while it was read
        }
    }

    /**
     * @return  array<string, object>  By path_hash
     */
    private function loadIndex(): array
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName(['id', 'path_hash', 'size', 'mtime', 'sha1', 'status']))
            ->from($this->db->quoteName('#__mediaaudit_files'));

        return $this->db->setQuery($query)->loadObjectList('path_hash');
    }

    private function hash(string $file, bool $hash, array &$stats): ?string
    {
        if (!$hash) {
            return null;
        }

        $sha1 = @sha1_file($file);

        if ($sha1 === false) {
            return null;
        }

        $stats['hashed']++;

        return $sha1;
    }

    /**
     * @param  list<array{string, string, int, string, string|null}>  $rows
     */
    private function insert(array $rows, string $now): void
    {
        $query = $this->db->getQuery(true)
            ->insert($this->db->quoteName('#__mediaaudit_files'))
            ->columns($this->db->quoteName(['path', 'path_hash', 'path_ci_hash', 'size', 'mtime', 'sha1', 'status', 'scanned_at']));

        foreach ($rows as [$path, $key, $size, $mtime, $sha1]) {
            $query->values(implode(',', [
                $this->db->quote($path),
                $this->db->quote($key),
                $this->db->quote(NormalizedPath::caseInsensitiveHashOf($path)),
                (int) $size,
                $this->db->quote($mtime),
                $sha1 === null ? 'NULL' : $this->db->quote($sha1),
                $this->db->quote('active'),
                $this->db->quote($now),
            ]));
        }

        $this->db->setQuery($query)->execute();
    }

    private function update(int $id, int $size, string $mtime, ?string $sha1): void
    {
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__mediaaudit_files'))
            ->set($this->db->quoteName('size') . ' = ' . $size)
            ->set($this->db->quoteName('mtime') . ' = ' . $this->db->quote($mtime))
            ->set($this->db->quoteName('sha1') . ' = ' . ($sha1 === null ? 'NULL' : $this->db->quote($sha1)))
            ->set($this->db->quoteName('status') . ' = ' . $this->db->quote('active'))
            ->where($this->db->quoteName('id') . ' = ' . $id);

        $this->db->setQuery($query)->execute();
    }

    /**
     * Drops the rows of files that are gone. Rows of quarantined files stay: the file is in the
     * quarantine, and its row is what the report of the quarantine is made of.
     */
    private function forget(array $known, array $seen): int
    {
        $gone = [];

        foreach ($known as $key => $row) {
            if (!isset($seen[$key]) && $row->status === 'active') {
                $gone[] = (int) $row->id;
            }
        }

        foreach (array_chunk($gone, 500) as $ids) {
            $query = $this->db->getQuery(true)
                ->delete($this->db->quoteName('#__mediaaudit_files'))
                ->where($this->db->quoteName('id') . ' IN (' . implode(',', $ids) . ')');

            $this->db->setQuery($query)->execute();
        }

        return \count($gone);
    }
}
