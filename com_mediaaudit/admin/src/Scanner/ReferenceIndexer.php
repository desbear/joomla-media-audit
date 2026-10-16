<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Scanner;

use Gss\Component\Mediaaudit\Administrator\Extractor\ReferenceExtractorInterface;
use Gss\Component\Mediaaudit\Administrator\Service\CaseSensitiveFilesystem;
use Gss\Component\Mediaaudit\Administrator\Service\Reference;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Writes #__mediaaudit_refs.
 */
final class ReferenceIndexer
{
    private const BATCH      = 200;
    private const MAX_PATH   = 1024;
    private const MAX_RAW    = 1000;
    private const MAX_FIELD  = 100;

    /** @var Reference[] */
    private array $buffer = [];

    private int $written = 0;

    private int $skipped = 0;

    private string $root;

    public function __construct(private DatabaseInterface $db, string $root, private ?FileScanner $files = null)
    {
        $this->root = rtrim($root, '/');
    }

    /**
     * Replaces all the references of the type of the extractor.
     *
     * @return  array{references: int, skipped: int}
     */
    public function indexAll(ReferenceExtractorInterface $extractor, int $batchSize = 200): array
    {
        $this->written = 0;
        $this->skipped = 0;

        $this->db->transactionStart();

        try {
            $query = $this->db->getQuery(true)
                ->delete($this->db->quoteName('#__mediaaudit_refs'))
                ->where($this->db->quoteName('source_type') . ' = ' . $this->db->quote($extractor->getSourceType()));
            $this->db->setQuery($query)->execute();

            $extractor->extractAll(function (Reference $reference): void {
                $this->add($reference);
            }, $batchSize);

            $this->flush();
            $this->db->transactionCommit();
        } catch (\Throwable $e) {
            $this->buffer = [];
            $this->db->transactionRollback();

            throw $e;
        }

        return ['references' => $this->written, 'skipped' => $this->skipped];
    }

    /**
     * Replaces the references of one source, after it was saved, deleted or changed state.
     *
     * @param  Reference[]  $references  Empty when the source is gone.
     * @param  bool         $check       Look right away which of the references are broken. False
     *                                   for the caller that goes through many sources, and calls
     *                                   checkExistence() once at the end.
     */
    public function indexOne(string $sourceType, int $sourceId, array $references, bool $check = true): void
    {
        $this->db->transactionStart();

        try {
            $query = $this->db->getQuery(true)
                ->delete($this->db->quoteName('#__mediaaudit_refs'))
                ->where($this->db->quoteName('source_type') . ' = ' . $this->db->quote($sourceType))
                ->where($this->db->quoteName('source_id') . ' = ' . $sourceId);
            $this->db->setQuery($query)->execute();

            foreach ($references as $reference) {
                $this->add($reference);
            }

            $this->flush();
            $this->db->transactionCommit();
        } catch (\Throwable $e) {
            $this->buffer = [];
            $this->db->transactionRollback();

            throw $e;
        }

        if ($check) {
            $this->checkExistence($sourceType, $sourceId);
        }
    }

    /**
     * After the state of sources was changed in a list.
     *
     * @param  int[]  $sourceIds
     */
    public function setState(string $sourceType, array $sourceIds, int $state): void
    {
        $sourceIds = array_values(array_filter(array_map('intval', $sourceIds)));

        if (!$sourceIds) {
            return;
        }

        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__mediaaudit_refs'))
            ->set($this->db->quoteName('source_state') . ' = ' . $state)
            ->where($this->db->quoteName('source_type') . ' = ' . $this->db->quote($sourceType))
            ->where($this->db->quoteName('source_id') . ' IN (' . implode(',', $sourceIds) . ')');

        $this->db->setQuery($query)->execute();
    }

    /**
     * Fills exists_local of the references to local files.
     *
     * Images below images/ are looked up in the index of the files. Everything else is looked up on
     * disk, by the name as it is written.
     *
     * For one source, images below images/ that are not in the index are looked up on disk too, and
     * added to the index: the file may have been uploaded after the last scan.
     *
     * @param   string|null  $sourceType  Only the references of this source. Null for all, which is
     *                                    for right after a scan of the files.
     *
     * @return  array{checked: int, missing: int}
     */
    public function checkExistence(?string $sourceType = null, int $sourceId = 0): array
    {
        $now   = $this->db->quote(gmdate('Y-m-d H:i:s'));
        $scope = $sourceType === null
            ? ''
            : ' AND ' . $this->db->quoteName('r.source_type') . ' = ' . $this->db->quote($sourceType)
                . ' AND ' . $this->db->quoteName('r.source_id') . ' = ' . $sourceId;

        $this->db->setQuery(
            'UPDATE ' . $this->db->quoteName('#__mediaaudit_refs', 'r')
            . ' LEFT JOIN ' . $this->db->quoteName('#__mediaaudit_files', 'f')
            . ' ON ' . $this->db->quoteName('f.path_hash') . ' = ' . $this->db->quoteName('r.path_hash')
            . ' AND ' . $this->db->quoteName('f.status') . ' = ' . $this->db->quote('active')
            . ' SET ' . $this->db->quoteName('r.exists_local') . ' = IF(' . $this->db->quoteName('f.id') . ' IS NULL, 0, 1), '
            . $this->db->quoteName('r.checked_at') . ' = ' . $now
            . ' WHERE ' . $this->db->quoteName('r.is_external') . ' = 0' . $scope
        )->execute();

        $query = $this->db->getQuery(true)
            ->select('DISTINCT ' . $this->db->quoteName('path') . ', ' . $this->db->quoteName('path_hash'))
            ->from($this->db->quoteName('#__mediaaudit_refs'))
            ->where($this->db->quoteName('is_external') . ' = 0')
            ->where($this->db->quoteName('exists_local') . ' = 0');

        if ($sourceType !== null) {
            $query->where($this->db->quoteName('source_type') . ' = ' . $this->db->quote($sourceType))
                ->where($this->db->quoteName('source_id') . ' = ' . $sourceId);
        }

        $filesystem = new CaseSensitiveFilesystem($this->root);
        $present    = [];
        $discovered = [];
        $missing    = 0;

        foreach ($this->db->setQuery($query)->loadObjectList() as $row) {
            $covered = FileScanner::covers($row->path);

            // Right after a scan, what the scanner covers is missing for sure
            if (($covered && ($sourceType === null || $this->files === null)) || !$filesystem->isFile($row->path)) {
                $missing++;

                continue;
            }

            // A quarantined file is not at its path, so it never gets here
            if ($covered) {
                $discovered[] = $row->path;
            }

            $present[] = $this->db->quote($row->path_hash);
        }

        if ($discovered) {
            $this->files->add($discovered);
        }

        foreach (array_chunk($present, 500) as $hashes) {
            $query = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__mediaaudit_refs'))
                ->set($this->db->quoteName('exists_local') . ' = 1')
                ->where($this->db->quoteName('is_external') . ' = 0')
                ->where($this->db->quoteName('path_hash') . ' IN (' . implode(',', $hashes) . ')');

            $this->db->setQuery($query)->execute();
        }

        return ['checked' => $missing + \count($present), 'missing' => $missing];
    }

    private function add(Reference $reference): void
    {
        // What the table cannot hold is left out: one reference must not stop the scan of a site
        if (!mb_check_encoding($reference->target->path, 'UTF-8') || mb_strlen($reference->target->path, 'UTF-8') > self::MAX_PATH) {
            $this->skipped++;

            return;
        }

        $this->buffer[] = $reference;

        if (\count($this->buffer) >= self::BATCH) {
            $this->flush();
        }
    }

    private function flush(): void
    {
        if (!$this->buffer) {
            return;
        }

        $now   = $this->db->quote(gmdate('Y-m-d H:i:s'));
        $query = $this->db->getQuery(true)
            ->insert($this->db->quoteName('#__mediaaudit_refs'))
            ->columns($this->db->quoteName([
                'path', 'path_hash', 'path_ci_hash', 'raw', 'is_external', 'source_type', 'source_id', 'field', 'source_state', 'updated_at',
            ]));

        foreach ($this->buffer as $reference) {
            $query->values(implode(',', [
                $this->db->quote($reference->target->path),
                $this->db->quote($reference->target->hash()),
                $this->db->quote($reference->target->caseInsensitiveHash()),
                $this->db->quote(mb_substr(mb_scrub($reference->raw, 'UTF-8'), 0, self::MAX_RAW, 'UTF-8')),
                $reference->target->isExternal ? 1 : 0,
                $this->db->quote(mb_substr($reference->sourceType, 0, 50, 'UTF-8')),
                $reference->sourceId,
                $this->db->quote(mb_substr($reference->field, 0, self::MAX_FIELD, 'UTF-8')),
                $reference->sourceState === null ? 'NULL' : $reference->sourceState,
                $now,
            ]));
        }

        $this->db->setQuery($query)->execute();

        $this->written += \count($this->buffer);
        $this->buffer   = [];
    }
}
