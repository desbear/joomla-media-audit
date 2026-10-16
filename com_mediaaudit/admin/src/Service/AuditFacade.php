<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Service;

use Gss\Component\Mediaaudit\Administrator\Helper\SourceHelper;
use Gss\Component\Mediaaudit\Administrator\Scanner\ScanRunner;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * What the API and the tools of the MCP server offer, in one place: the same answers, the same
 * checks of what a user may do.
 *
 * Every method throws \RuntimeException with code 403 for what the user may not do, and
 * \InvalidArgumentException for a request that makes no sense.
 */
final class AuditFacade
{
    /** Name that a request uses => model of the list */
    public const TYPES = [
        'orphan'      => 'Orphans',
        'semi'        => 'Semiorphans',
        'semi_orphan' => 'Semiorphans',
        'broken'      => 'Broken',
        'duplicate'   => 'Duplicates',
        'quarantine'  => 'Quarantine',
        'localized'   => 'Localized',
        'rewrites'    => 'Rewrites',
    ];

    public const MAX_LIMIT = 500;

    private ?ScanRunner $runner = null;

    public function __construct(private CMSApplicationInterface $app, private DatabaseInterface $db, private User $user)
    {
    }

    public static function create(?User $user = null): self
    {
        $app = Factory::getApplication();

        return new self($app, Factory::getContainer()->get(DatabaseInterface::class), $user ?? $app->getIdentity());
    }

    public function canRead(): bool
    {
        return (bool) $this->user->authorise('core.manage', 'com_mediaaudit');
    }

    public function canQuarantine(): bool
    {
        return $this->canRead() && (bool) $this->user->authorise('mediaaudit.quarantine', 'com_mediaaudit');
    }

    /**
     * One of the lists of the report.
     *
     * @param   array{search?: string, source_type?: string, kind?: string, folder?: string, state?: string, limit?: int, offset?: int}  $options
     *
     * @return  array{type: string, total: int, offset: int, limit: int, items: list<array<string, mixed>>, scanned_at: ?string, note: string}
     */
    public function report(string $type, array $options = []): array
    {
        $this->mustRead();

        $type = strtolower(trim($type));

        if (!isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException('type has to be one of: orphan, semi, broken, duplicate, quarantine, localized, rewrites.');
        }

        $limit  = max(1, min((int) ($options['limit'] ?? 50) ?: 50, self::MAX_LIMIT));
        $offset = max(0, (int) ($options['offset'] ?? 0));
        $model  = $this->model(self::TYPES[$type]);

        $model->setState('list.limit', $limit);
        $model->setState('list.start', $offset);

        if (!empty($options['include_derived'])) {
            $model->setState('filter.derived', '1');
        }

        foreach (['search', 'source_type', 'kind', 'folder', 'state', 'host'] as $filter) {
            if (isset($options[$filter]) && $options[$filter] !== '') {
                $model->setState('filter.' . $filter, (string) $options[$filter]);
            }
        }

        $items = array_map([$this, 'describe' . self::TYPES[$type]], $model->getItems() ?: []);
        $last  = $this->runner()->getHistory()->last();

        return [
            'type'       => $type === 'semi' ? 'semi_orphan' : $type,
            'total'      => (int) $model->getTotal(),
            'offset'     => $offset,
            'limit'      => $limit,
            'items'      => array_values($items),
            'scanned_at' => $last === null ? null : self::iso($last['scanned_at']),
            'note'       => 'Only the references in the content of this site are known. A file that is linked from elsewhere looks like an orphan.',
        ];
    }

    /**
     * The numbers of the report.
     *
     * @return  array{summary: array<string, int>, quarantined: int, scanned_at: ?string, scan_is_fresh: bool, external_hosts: list<array<string, mixed>>}
     */
    public function summary(): array
    {
        $this->mustRead();

        $runner = $this->runner();
        $last   = $runner->getHistory()->last();
        $query  = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__mediaaudit_quarantine'))
            ->where($this->db->quoteName('restored_at') . ' IS NULL');

        return [
            'summary'        => $runner->getReport()->summary(),
            'quarantined'    => (int) $this->db->setQuery($query)->loadResult(),
            'scanned_at'     => $last === null ? null : self::iso($last['scanned_at']),
            'scan_is_fresh'  => $runner->getQuarantine()->isScanFresh(),
            'external_hosts' => $runner->getReport()->externalHosts(),
        ];
    }

    /**
     * What refers to a file or to an external image.
     *
     * @param   string  $path  As it is written in the content: a path, or an address.
     *
     * @return  array{path: string, is_external: bool, file: ?array<string, mixed>, class: ?string, references: list<array<string, mixed>>, other_case: list<string>}
     */
    public function references(string $path): array
    {
        $this->mustRead();

        $params = ComponentHelper::getParams('com_mediaaudit');
        $target = PathNormalizer::fromOptions((string) $params->get('site_domains', ''), (string) $params->get('base_path', ''))->normalize($path);

        if ($target === null) {
            throw new \InvalidArgumentException('path is not the path of a file or the address of an image.');
        }

        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['path', 'size', 'sha1', 'mtime', 'status']))
            ->from($db->quoteName('#__mediaaudit_files'))
            ->where($db->quoteName('path_hash') . ' = ' . $db->quote($target->hash()));

        $file       = $db->setQuery($query)->loadAssoc();
        $references = SourceHelper::referencesTo($db, [$target->hash()], 200)[$target->hash()] ?? [];
        $live       = \count(array_filter($references, static fn (array $reference): bool => $reference['state'] === null || $reference['state'] === 1));

        // Paths that differ by capitals only: a likely mistake in the content
        $query = $db->getQuery(true)
            ->select('DISTINCT ' . $db->quoteName('path'))
            ->from($db->quoteName('#__mediaaudit_refs'))
            ->where($db->quoteName('path_ci_hash') . ' = ' . $db->quote($target->caseInsensitiveHash()))
            ->where($db->quoteName('path_hash') . ' <> ' . $db->quote($target->hash()));

        $otherCase = $db->setQuery($query)->loadColumn();

        $query = $db->getQuery(true)
            ->select($db->quoteName('path'))
            ->from($db->quoteName('#__mediaaudit_files'))
            ->where($db->quoteName('path_ci_hash') . ' = ' . $db->quote($target->caseInsensitiveHash()))
            ->where($db->quoteName('path_hash') . ' <> ' . $db->quote($target->hash()));

        $otherCase = array_values(array_unique(array_merge($otherCase, $db->setQuery($query)->loadColumn())));

        if ($file !== null) {
            $file['size']  = (int) $file['size'];
            $file['mtime'] = self::iso($file['mtime']);
            $class         = $file['status'] !== 'active' ? 'quarantined' : (!$references ? 'orphan' : ($live ? 'in_use' : 'semi_orphan'));
        } else {
            $class = $target->isExternal ? null : ($references ? 'broken' : null);
        }

        return [
            'path'        => $target->path,
            'is_external' => $target->isExternal,
            'file'        => $file,
            'class'       => $class,
            'references'  => array_map([$this, 'describeReference'], $references),
            'other_case'  => $otherCase,
        ];
    }

    /**
     * @param   string[]  $paths
     *
     * @return  array{moved: list<array{id: int, path: string, quarantine_path: string}>, refused: list<array{path: string, reason: string, detail: string}>}
     */
    public function quarantine(array $paths, string $note = ''): array
    {
        $this->mustQuarantine();

        $paths = array_values(array_filter(array_map('strval', $paths), static fn (string $path): bool => trim($path) !== ''));

        if (!$paths) {
            throw new \InvalidArgumentException('paths has to name at least one file.');
        }

        if (\count($paths) > self::MAX_LIMIT) {
            throw new \InvalidArgumentException('At most ' . self::MAX_LIMIT . ' files at a time.');
        }

        return $this->runner()->getQuarantine()->move($paths, (int) $this->user->id, mb_substr($note, 0, 500), (string) $this->user->username);
    }

    /**
     * @param   int[]  $ids  Of the records of the quarantine.
     *
     * @return  array{restored: list<array{id: int, path: string}>, refused: list<array{path: string, reason: string, detail: string}>}
     */
    public function restore(array $ids): array
    {
        $this->mustQuarantine();

        $ids = array_values(array_filter(array_map('intval', $ids)));

        if (!$ids) {
            throw new \InvalidArgumentException('ids has to name at least one record of the quarantine.');
        }

        return $this->runner()->getQuarantine()->restore($ids, (int) $this->user->id, (string) $this->user->username);
    }

    /**
     * Scans now, and answers when the scan is done.
     *
     * @return  array{seconds: float, files: array<string, int>, sources: array<string, mixed>, summary: array<string, int>}
     */
    public function scan(string $source = 'api'): array
    {
        $this->mustRead();

        $runner = $this->runner();
        $scan   = $runner->run((bool) ComponentHelper::getParams('com_mediaaudit')->get('hash_files', 1));
        $report = $runner->getReport()->summary();

        $runner->getHistory()->record($source, (float) $scan['seconds'], $report);

        return ['seconds' => (float) $scan['seconds'], 'files' => $scan['files'], 'sources' => $scan['sources'], 'summary' => $report];
    }

    /**
     * @return  array{scanned_at: ?string, seconds: ?float, source: ?string, scan_is_fresh: bool}
     */
    public function lastScan(): array
    {
        $this->mustRead();

        $last = $this->runner()->getHistory()->last();

        return [
            'scanned_at'    => $last === null ? null : self::iso($last['scanned_at']),
            'seconds'       => $last['seconds'] ?? null,
            'source'        => $last['source'] ?? null,
            'scan_is_fresh' => $this->runner()->getQuarantine()->isScanFresh(),
        ];
    }

    private function mustRead(): void
    {
        if (!$this->canRead()) {
            throw new \RuntimeException('This user is not allowed to see the reports of Media Audit (permission "Access Administration Interface" of com_mediaaudit).', 403);
        }
    }

    private function mustQuarantine(): void
    {
        if (!$this->canQuarantine()) {
            throw new \RuntimeException('This user is not allowed to move files to the quarantine or back (permission "Quarantine and restore files" of com_mediaaudit).', 403);
        }
    }

    private function runner(): ScanRunner
    {
        return $this->runner ??= ScanRunner::create(
            $this->db,
            ComponentHelper::getParams('com_mediaaudit'),
            JPATH_ROOT,
            $this->app->getDispatcher(),
            (string) $this->app->get('tmp_path', sys_get_temp_dir())
        );
    }

    private function model(string $name)
    {
        return $this->app->bootComponent('com_mediaaudit')->getMVCFactory()->createModel($name, 'Administrator', ['ignore_request' => true]);
    }

    private function describeReference(array $reference): array
    {
        return [
            'source_type' => $reference['type'],
            'source_id'   => $reference['id'],
            'title'       => $reference['title'],
            'field'       => $reference['field'],
            'state'       => self::stateName($reference['state']),
            'edit_link'   => $reference['link'] === null ? null : 'administrator/' . $reference['link'],
        ];
    }

    private function describeOrphans(object $item): array
    {
        return [
            'path'                          => $item->path,
            'size'                          => (int) $item->size,
            'modified'                      => self::iso($item->mtime),
            'sha1'                          => $item->sha1,
            'references'                    => (int) $item->refs,
            'published_references'          => (int) $item->live_refs,
            'referenced_in_another_case_as' => $item->case_suspect,
            'sources'                       => array_map([$this, 'describeReference'], $item->references),
        ];
    }

    private function describeSemiorphans(object $item): array
    {
        return $this->describeOrphans($item);
    }

    private function describeBroken(object $item): array
    {
        return [
            'target'               => $item->target,
            'http_status'          => $item->http_status === null ? null : (int) $item->http_status,
            'error'                => (string) $item->error,
            'references'           => (int) $item->refs,
            'published_references' => (int) $item->live_refs,
            'file_in_another_case' => $item->case_suspect,
            'in_quarantine'        => (bool) $item->quarantined,
            'sources'              => array_map([$this, 'describeReference'], $item->references),
        ];
    }

    private function describeDuplicates(object $group): array
    {
        return [
            'sha1'         => $group->sha1,
            'size'         => (int) $group->size,
            'copies'       => (int) $group->copies,
            'wasted_bytes' => (int) $group->wasted,
            'files'        => array_map(static fn (object $file): array => [
                'path'                 => $file->path,
                'made_by_a_component'  => !empty($file->derived),
                'modified'             => self::iso($file->mtime),
                'references'           => (int) $file->refs,
                'published_references' => (int) $file->live_refs,
            ], $group->files),
        ];
    }

    private function describeQuarantine(object $item): array
    {
        return [
            'id'              => (int) $item->id,
            'original_path'   => $item->original_path,
            'quarantine_path' => $item->quarantine_path,
            'sha1'            => $item->sha1,
            'size'            => $item->size === null ? null : (int) $item->size,
            'moved_at'        => self::iso($item->moved_at),
            'moved_by'        => $item->moved_by_name,
            'restored_at'     => self::iso($item->restored_at),
            'restored_by'     => $item->restored_by_name,
            'deleted_at'      => self::iso($item->deleted_at),
            'deleted_by'      => $item->deleted_by_name,
            'note'            => (string) $item->note,
        ];
    }

    private function describeLocalized(object $item): array
    {
        return [
            'url'               => $item->url,
            'host'              => $item->host,
            'local_path'        => $item->local_path,
            'size'              => $item->size === null ? null : (int) $item->size,
            'type'              => (string) $item->mime,
            'copied_at'         => self::iso($item->fetched_at),
            'http_status'       => $item->http_status === null ? null : (int) $item->http_status,
            'error'             => (string) $item->error,
            'file'              => $item->status,
            'used_by'           => array_map([$this, 'describeReference'], $item->references),
            'still_external_in' => array_map([$this, 'describeReference'], $item->external),
        ];
    }

    private function describeRewrites(object $item): array
    {
        return [
            'id'          => (int) $item->id,
            'source_type' => $item->source_type,
            'source_id'   => (int) $item->source_id,
            'title'       => $item->title,
            'state'       => self::stateName($item->state),
            'field'       => $item->field,
            'kind'        => $item->kind,
            'replaced'    => (int) $item->replaced,
            'changed_at'  => self::iso($item->rewritten_at),
            'changed_by'  => $item->rewritten_by_name,
            'put_back_at' => self::iso($item->reverted_at),
            'edit_link'   => $item->link === null ? null : 'administrator/' . $item->link,
        ];
    }

    private static function stateName(?int $state): string
    {
        return [1 => 'published', 0 => 'unpublished', 2 => 'archived', -2 => 'trashed'][$state ?? 99] ?? ($state === null ? 'none' : (string) $state);
    }

    /**
     * The times of the tables are UTC.
     */
    private static function iso(?string $time): ?string
    {
        return $time === null || $time === '' ? null : gmdate('Y-m-d\TH:i:s\Z', strtotime($time . ' UTC'));
    }
}
