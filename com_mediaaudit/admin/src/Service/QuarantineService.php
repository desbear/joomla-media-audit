<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Service;

use Gss\Component\Mediaaudit\Administrator\Report\ScanHistory;
use Gss\Component\Mediaaudit\Administrator\Scanner\FileScanner;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Moves images out of the way, and back. And, for the files that have been out of the way long
 * enough, deletes them for good.
 *
 * A file goes to images/_mediaaudit_quarantine/, where the site does not serve it, and stays there
 * until a person puts it back or deletes it. Deleting is the one thing here that cannot be undone,
 * and delete() is the one place that does it: only files inside the quarantine, only after the days
 * that the options ask for, and only when no content refers to where the file was.
 */
final class QuarantineService
{
    public const REASON_NOT_AN_IMAGE = 'not_an_image';
    public const REASON_NOT_FOUND    = 'not_found';
    public const REASON_REFERENCED   = 'referenced';
    public const REASON_SCAN_TOO_OLD = 'scan_too_old';
    public const REASON_TAKEN        = 'taken';
    public const REASON_GONE         = 'gone';
    public const REASON_RESTORED     = 'restored';
    public const REASON_FAILED       = 'failed';
    public const REASON_TOO_RECENT   = 'too_recent';
    public const REASON_DELETED      = 'deleted';
    public const REASON_CHANGED      = 'changed';
    public const REASON_OTHER_CASE   = 'other_case';
    public const REASON_NOT_HELD     = 'not_in_quarantine';

    private string $root;

    /**
     * @param  int  $maxScanAge  Hours. A file is only moved when the last scan is not older: the
     *                           references that protect a file are the ones of the last scan. 0 to
     *                           move whatever the age of the scan is.
     */
    public function __construct(
        private DatabaseInterface $db,
        string $root,
        private ScanHistory $history,
        private int $maxScanAge = 24,
        private ?ActionLogger $logger = null,
        private int $deleteAfterDays = 30
    ) {
        // Never at once, whatever the options say
        $this->deleteAfterDays = max(1, $deleteAfterDays);

        $this->root = rtrim($root, '/');
    }

    /**
     * @param   string[]  $paths     Relative to the root of the site.
     * @param   int       $userId    Who asked for it.
     * @param   string    $username  For the log of the actions.
     *
     * @return  array{moved: list<array{id: int, path: string, quarantine_path: string}>, refused: list<array{path: string, reason: string, detail: string}>}
     */
    public function move(array $paths, int $userId, string $note = '', string $username = ''): array
    {
        $result = ['moved' => [], 'refused' => []];
        $paths  = array_values(array_unique(array_map([PathNormalizer::class, 'composeUnicode'], array_map('strval', $paths))));

        if ($paths && !$this->isScanFresh()) {
            foreach ($paths as $path) {
                $result['refused'][] = ['path' => $path, 'reason' => self::REASON_SCAN_TOO_OLD, 'detail' => (string) $this->maxScanAge];
            }

            return $result;
        }

        $filesystem = new CaseSensitiveFilesystem($this->root);
        $folder     = FileScanner::QUARANTINE_FOLDER . '/' . gmdate('Ymd');

        foreach ($paths as $path) {
            if (!FileScanner::covers($path) || preg_match('#(^|/)\.\.?(/|$)#', $path)) {
                $result['refused'][] = ['path' => $path, 'reason' => self::REASON_NOT_AN_IMAGE, 'detail' => ''];

                continue;
            }

            if (!$filesystem->isFile($path) || !$filesystem->isInside($path, FileScanner::FOLDER)) {
                $result['refused'][] = ['path' => $path, 'reason' => self::REASON_NOT_FOUND, 'detail' => ''];

                continue;
            }

            // Looked up now, not taken from the list that the person saw
            $references = $this->countReferences($path);

            if ($references > 0) {
                $result['refused'][] = ['path' => $path, 'reason' => self::REASON_REFERENCED, 'detail' => (string) $references];

                continue;
            }

            $target = $this->freeName($folder . '/' . $path);

            try {
                $this->prepare(\dirname($this->root . '/' . $target));

                $sha1 = @sha1_file($this->root . '/' . $path) ?: null;

                if (!@rename($this->root . '/' . $path, $this->root . '/' . $target)) {
                    throw new \RuntimeException('rename');
                }
            } catch (\Throwable $e) {
                $result['refused'][] = ['path' => $path, 'reason' => self::REASON_FAILED, 'detail' => $e->getMessage()];

                continue;
            }

            $record = (object) [
                'original_path'   => $path,
                'quarantine_path' => $target,
                'sha1'            => $sha1,
                'moved_by'        => $userId,
                'moved_at'        => gmdate('Y-m-d H:i:s'),
                'note'            => $note,
            ];

            $this->db->insertObject('#__mediaaudit_quarantine', $record, 'id');
            $this->setStatus($path, 'quarantined');

            $result['moved'][] = ['id' => (int) $record->id, 'path' => $path, 'quarantine_path' => $target];

            if ($this->logger !== null) {
                $this->logger->log(
                    'COM_MEDIAAUDIT_ACTION_LOG_QUARANTINED',
                    ['action' => 'quarantine', 'id' => (int) $record->id, 'path' => $path, 'quarantine_path' => $target],
                    $userId,
                    $username
                );
            }
        }

        return $result;
    }

    /**
     * @param   int[]  $ids     Of the records of the quarantine.
     * @param   int    $userId  Who asked for it.
     *
     * @return  array{restored: list<array{id: int, path: string}>, refused: list<array{path: string, reason: string, detail: string}>}
     */
    public function restore(array $ids, int $userId, string $username = ''): array
    {
        $result = ['restored' => [], 'refused' => []];
        $ids    = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if (!$ids) {
            return $result;
        }

        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__mediaaudit_quarantine'))
            ->where($this->db->quoteName('id') . ' IN (' . implode(',', $ids) . ')')
            ->order($this->db->quoteName('id') . ' ASC');

        foreach ($this->db->setQuery($query)->loadObjectList() as $record) {
            $path = $record->original_path;

            if ($record->deleted_at !== null) {
                $result['refused'][] = ['path' => $path, 'reason' => self::REASON_DELETED, 'detail' => $record->deleted_at];

                continue;
            }

            if ($record->restored_at !== null) {
                $result['refused'][] = ['path' => $path, 'reason' => self::REASON_RESTORED, 'detail' => $record->restored_at];

                continue;
            }

            // Whatever the record says: only from the quarantine, and only to where images are
            if (
                !str_starts_with((string) $record->quarantine_path, FileScanner::QUARANTINE_FOLDER . '/')
                || preg_match('#(^|/)\.\.?(/|$)#', (string) $record->quarantine_path)
                || !FileScanner::covers((string) $path)
                || preg_match('#(^|/)\.\.?(/|$)#', (string) $path)
            ) {
                $result['refused'][] = ['path' => $path, 'reason' => self::REASON_NOT_HELD, 'detail' => (string) $record->quarantine_path];

                continue;
            }

            if (!is_file($this->root . '/' . $record->quarantine_path)) {
                $result['refused'][] = ['path' => $path, 'reason' => self::REASON_GONE, 'detail' => $record->quarantine_path];

                continue;
            }

            // Another file has taken the place: it is not ours to overwrite
            if (file_exists($this->root . '/' . $path)) {
                $result['refused'][] = ['path' => $path, 'reason' => self::REASON_TAKEN, 'detail' => ''];

                continue;
            }

            $folder     = \dirname($this->root . '/' . $path);
            $filesystem = new CaseSensitiveFilesystem($this->root);

            if (!is_dir($folder) && !@mkdir($folder, 0755, true) && !is_dir($folder)) {
                $result['refused'][] = ['path' => $path, 'reason' => self::REASON_FAILED, 'detail' => 'mkdir'];

                continue;
            }

            // Where the paths say, and not where a link in them leads
            if (
                is_link($this->root . '/' . $record->quarantine_path)
                || !$filesystem->isInside((string) $record->quarantine_path, FileScanner::QUARANTINE_FOLDER)
                || !$filesystem->isInside((string) $path, FileScanner::FOLDER)
            ) {
                $result['refused'][] = ['path' => $path, 'reason' => self::REASON_NOT_HELD, 'detail' => (string) $record->quarantine_path];

                continue;
            }

            if (!@rename($this->root . '/' . $record->quarantine_path, $this->root . '/' . $path)) {
                $result['refused'][] = ['path' => $path, 'reason' => self::REASON_FAILED, 'detail' => 'rename'];

                continue;
            }

            $query = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__mediaaudit_quarantine'))
                ->set($this->db->quoteName('restored_by') . ' = ' . $userId)
                ->set($this->db->quoteName('restored_at') . ' = ' . $this->db->quote(gmdate('Y-m-d H:i:s')))
                ->where($this->db->quoteName('id') . ' = ' . (int) $record->id);
            $this->db->setQuery($query)->execute();

            $this->setStatus($path, 'active');

            $result['restored'][] = ['id' => (int) $record->id, 'path' => $path];

            if ($this->logger !== null) {
                $this->logger->log(
                    'COM_MEDIAAUDIT_ACTION_LOG_RESTORED',
                    ['action' => 'restore', 'id' => (int) $record->id, 'path' => $path],
                    $userId,
                    $username
                );
            }
        }

        return $result;
    }

    /**
     * What delete() would do with the records, without doing it.
     *
     * @param   int[]  $ids  Of the records of the quarantine.
     *
     * @return  list<array{id: int, path: string, quarantine_path: string, size: int, moved_at: string, deletable_at: string, deletable: bool, reason: string, detail: string}>
     */
    public function preview(array $ids): array
    {
        $fresh   = $this->isScanFresh();
        $preview = [];

        foreach ($this->records($ids) as $record) {
            [$reason, $detail] = $this->whyNot($record, $fresh);

            $file = $this->root . '/' . $record->quarantine_path;

            $preview[] = [
                'id'              => (int) $record->id,
                'path'            => $record->original_path,
                'quarantine_path' => $record->quarantine_path,
                'size'            => is_file($file) ? (int) filesize($file) : 0,
                'moved_at'        => $record->moved_at,
                'deletable_at'    => gmdate('Y-m-d H:i:s', strtotime($record->moved_at . ' UTC') + $this->deleteAfterDays * 86400),
                'deletable'       => $reason === '',
                'reason'          => $reason,
                'detail'          => $detail,
            ];
        }

        return $preview;
    }

    /**
     * Deletes files of the quarantine for good. There is no way back from here.
     *
     * @param   int[]   $ids       Of the records of the quarantine.
     * @param   int     $userId    Who asked for it.
     * @param   string  $username  For the log of the actions.
     *
     * @return  array{deleted: list<array{id: int, path: string, bytes: int}>, refused: list<array{path: string, reason: string, detail: string}>}
     */
    public function delete(array $ids, int $userId, string $username = ''): array
    {
        $result = ['deleted' => [], 'refused' => []];
        $fresh  = $this->isScanFresh();

        foreach ($this->records($ids) as $record) {
            // Every check again, now: the list that the person confirmed is minutes old
            [$reason, $detail] = $this->whyNot($record, $fresh);

            if ($reason !== '') {
                $result['refused'][] = ['path' => $record->original_path, 'reason' => $reason, 'detail' => $detail];

                continue;
            }

            $file  = $this->root . '/' . $record->quarantine_path;
            $bytes = (int) filesize($file);

            if (!@unlink($file)) {
                $result['refused'][] = ['path' => $record->original_path, 'reason' => self::REASON_FAILED, 'detail' => 'unlink'];

                continue;
            }

            $query = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__mediaaudit_quarantine'))
                ->set($this->db->quoteName('deleted_by') . ' = ' . $userId)
                ->set($this->db->quoteName('deleted_at') . ' = ' . $this->db->quote(gmdate('Y-m-d H:i:s')))
                ->where($this->db->quoteName('id') . ' = ' . (int) $record->id);
            $this->db->setQuery($query)->execute();

            // The file is no file of the site any more, in quarantine or out of it
            $query = $this->db->getQuery(true)
                ->delete($this->db->quoteName('#__mediaaudit_files'))
                ->where($this->db->quoteName('path_hash') . ' = ' . $this->db->quote(NormalizedPath::hashOf($record->original_path)))
                ->where($this->db->quoteName('status') . ' = ' . $this->db->quote('quarantined'));
            $this->db->setQuery($query)->execute();

            $result['deleted'][] = ['id' => (int) $record->id, 'path' => $record->original_path, 'bytes' => $bytes];

            if ($this->logger !== null) {
                $this->logger->log(
                    'COM_MEDIAAUDIT_ACTION_LOG_DELETED',
                    ['action' => 'delete', 'id' => (int) $record->id, 'path' => $record->original_path, 'sha1' => (string) $record->sha1, 'bytes' => $bytes],
                    $userId,
                    $username
                );
            }
        }

        return $result;
    }

    public function getDeleteAfterDays(): int
    {
        return $this->deleteAfterDays;
    }

    /**
     * The reason that a record cannot be deleted, or nothing.
     *
     * @return  array{0: string, 1: string}  Reason and detail
     */
    private function whyNot(object $record, bool $scanIsFresh): array
    {
        if ($record->deleted_at !== null) {
            return [self::REASON_DELETED, $record->deleted_at];
        }

        if ($record->restored_at !== null) {
            return [self::REASON_RESTORED, $record->restored_at];
        }

        $held = (string) $record->quarantine_path;

        // Whatever the record says: nothing outside the quarantine is deleted here
        if (!str_starts_with($held, FileScanner::QUARANTINE_FOLDER . '/') || preg_match('#(^|/)\.\.?(/|$)#', $held)) {
            return [self::REASON_NOT_HELD, $held];
        }

        if (!is_file($this->root . '/' . $held) || is_link($this->root . '/' . $held)) {
            return [self::REASON_GONE, $held];
        }

        // Not through a folder that is a link to somewhere else
        if (!(new CaseSensitiveFilesystem($this->root))->isInside($held, FileScanner::QUARANTINE_FOLDER)) {
            return [self::REASON_NOT_HELD, $held];
        }

        $age = time() - strtotime($record->moved_at . ' UTC');

        if ($age < $this->deleteAfterDays * 86400) {
            return [self::REASON_TOO_RECENT, (string) $this->deleteAfterDays];
        }

        // The file that was moved, not one that was put in its place
        if ($record->sha1 !== null && $record->sha1 !== '' && @sha1_file($this->root . '/' . $held) !== $record->sha1) {
            return [self::REASON_CHANGED, ''];
        }

        if (!$scanIsFresh) {
            return [self::REASON_SCAN_TOO_OLD, (string) $this->maxScanAge];
        }

        $references = $this->countReferences($record->original_path);

        if ($references > 0) {
            return [self::REASON_REFERENCED, (string) $references];
        }

        // Content that refers to the path in other capitals most likely means this file
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__mediaaudit_refs'))
            ->where($this->db->quoteName('is_external') . ' = 0')
            ->where($this->db->quoteName('path_ci_hash') . ' = ' . $this->db->quote(NormalizedPath::caseInsensitiveHashOf($record->original_path)));

        $otherCase = (int) $this->db->setQuery($query)->loadResult();

        if ($otherCase > 0) {
            return [self::REASON_OTHER_CASE, (string) $otherCase];
        }

        return ['', ''];
    }

    /**
     * @param   int[]  $ids
     *
     * @return  object[]
     */
    private function records(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if (!$ids) {
            return [];
        }

        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__mediaaudit_quarantine'))
            ->where($this->db->quoteName('id') . ' IN (' . implode(',', $ids) . ')')
            ->order($this->db->quoteName('id') . ' ASC');

        return $this->db->setQuery($query)->loadObjectList();
    }

    /**
     * Whether the last scan is young enough to trust its references.
     */
    public function isScanFresh(): bool
    {
        if ($this->maxScanAge <= 0) {
            return true;
        }

        $last = $this->history->last();

        return $last !== null && strtotime($last['scanned_at'] . ' UTC') >= time() - $this->maxScanAge * 3600;
    }

    public function getMaxScanAge(): int
    {
        return $this->maxScanAge;
    }

    private function countReferences(string $path): int
    {
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__mediaaudit_refs'))
            ->where($this->db->quoteName('is_external') . ' = 0')
            ->where($this->db->quoteName('path_hash') . ' = ' . $this->db->quote(NormalizedPath::hashOf($path)));

        $count = (int) $this->db->setQuery($query)->loadResult();

        // An address of another host that ends in the path of this file can be this site under a
        // name that the options do not list. Counted as a reference: better kept than taken away
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__mediaaudit_refs'))
            ->where($this->db->quoteName('is_external') . ' = 1')
            ->where($this->db->quoteName('path') . ' LIKE ' . $this->db->quote('%://%/' . $this->db->escape($path, true), false));

        return $count + (int) $this->db->setQuery($query)->loadResult();
    }

    private function setStatus(string $path, string $status): void
    {
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__mediaaudit_files'))
            ->set($this->db->quoteName('status') . ' = ' . $this->db->quote($status))
            ->where($this->db->quoteName('path_hash') . ' = ' . $this->db->quote(NormalizedPath::hashOf($path)));

        $this->db->setQuery($query)->execute();

        // References to the file are broken while it is away, and whole again when it is back
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__mediaaudit_refs'))
            ->set($this->db->quoteName('exists_local') . ' = ' . ($status === 'active' ? 1 : 0))
            ->where($this->db->quoteName('is_external') . ' = 0')
            ->where($this->db->quoteName('path_hash') . ' = ' . $this->db->quote(NormalizedPath::hashOf($path)));

        $this->db->setQuery($query)->execute();
    }

    /**
     * The same file can be quarantined, restored and quarantined again on one day.
     */
    private function freeName(string $target): string
    {
        if (!file_exists($this->root . '/' . $target)) {
            return $target;
        }

        $extension = pathinfo($target, PATHINFO_EXTENSION);
        $base      = substr($target, 0, -\strlen($extension) - 1);

        for ($i = 2; ; $i++) {
            $candidate = $base . '.' . $i . '.' . $extension;

            if (!file_exists($this->root . '/' . $candidate)) {
                return $candidate;
            }
        }
    }

    /**
     * Creates the folder, and closes the quarantine to visitors: a quarantined image has to be as
     * gone for them as a deleted one, or the quarantine tells nothing about who misses the image.
     */
    private function prepare(string $folder): void
    {
        if (!is_dir($folder) && !@mkdir($folder, 0755, true) && !is_dir($folder)) {
            throw new \RuntimeException('mkdir');
        }

        $quarantine = $this->root . '/' . FileScanner::QUARANTINE_FOLDER;

        if (!is_file($quarantine . '/.htaccess')) {
            @file_put_contents(
                $quarantine . '/.htaccess',
                "# Media Audit: quarantined images are not served\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n"
            );
        }

        if (!is_file($quarantine . '/web.config')) {
            @file_put_contents(
                $quarantine . '/web.config',
                "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n    <system.webServer>\n        <security>\n            <authorization>\n"
                . "                <remove users=\"*\" roles=\"\" verbs=\"\" />\n                <add accessType=\"Deny\" users=\"*\" />\n"
                . "            </authorization>\n        </security>\n    </system.webServer>\n</configuration>\n"
            );
        }

        if (!is_file($quarantine . '/index.html')) {
            @file_put_contents($quarantine . '/index.html', '<!DOCTYPE html><title></title>');
        }
    }
}
