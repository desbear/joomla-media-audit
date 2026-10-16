<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Model;

use Gss\Component\Mediaaudit\Administrator\Helper\SourceHelper;
use Gss\Component\Mediaaudit\Administrator\Report\ReportRepository;
use Gss\Component\Mediaaudit\Administrator\Service\HttpFetcher;
use Gss\Component\Mediaaudit\Administrator\Service\NormalizedPath;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\ListModel;

\defined('_JEXEC') or die;

/**
 * The images of other sites that were copied to this site, the ones that could not be copied, and
 * the ones that the content loads from there and nobody has tried to copy.
 */
class LocalizedModel extends ListModel
{
    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        $config['filter_fields'] ??= ['u.id', 'u.fetched_at', 'u.checked_at', 'u.size', 'u.host', 'u.local_path', 'search', 'host', 'state'];

        parent::__construct($config, $factory);
    }

    protected function populateState($ordering = 'u.id', $direction = 'DESC')
    {
        parent::populateState($ordering, $direction);
    }

    protected function getStoreId($id = '')
    {
        $id .= ':' . $this->getState('filter.search');
        $id .= ':' . $this->getState('filter.host');
        $id .= ':' . $this->getState('filter.state');

        return parent::getStoreId($id);
    }

    public function isFailed(): bool
    {
        return $this->getState('filter.state') === 'failed';
    }

    public function isWaiting(): bool
    {
        return $this->getState('filter.state') === 'waiting';
    }

    /**
     * The hosts are the ones of the images of every kind, also of the ones that nothing is known of.
     */
    public function getFilterForm($data = [], $loadData = true)
    {
        $form  = parent::getFilterForm($data, $loadData);
        $field = $form ? $form->getField('host', 'filter') : false;

        if ($field) {
            foreach (array_keys($this->getHosts()) as $host) {
                $field->addOption($host, ['value' => $host]);
            }
        }

        return $form;
    }

    protected function getListQuery()
    {
        if ($this->isWaiting()) {
            return $this->getWaitingQuery();
        }

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['u.id', 'u.url', 'u.url_hash', 'u.host', 'u.http_status', 'u.error', 'u.checked_at', 'u.local_path', 'u.sha1', 'u.size', 'u.mime', 'u.fetched_at']))
            ->from($db->quoteName('#__mediaaudit_urls', 'u'));

        // Copied, unless the filter asks for what could not be copied
        if ($this->isFailed()) {
            $query->where($db->quoteName('u.local_path') . ' IS NULL')
                ->where($db->quoteName('u.error') . ' <> ' . $db->quote(''));
        } else {
            $query->where($db->quoteName('u.local_path') . ' IS NOT NULL');
        }

        $host = trim((string) $this->getState('filter.host'));

        if ($host !== '') {
            $query->where($db->quoteName('u.host') . ' = ' . $db->quote($host));
        }

        $search = trim((string) $this->getState('filter.search'));

        if ($search !== '') {
            $like = $db->quote('%' . $db->escape($search, true) . '%', false);

            $query->where('(' . $db->quoteName('u.url') . ' LIKE ' . $like . ' OR ' . $db->quoteName('u.local_path') . ' LIKE ' . $like . ')');
        }

        $ordering  = $this->getState('list.ordering', 'u.id');
        $direction = strtoupper((string) $this->getState('list.direction', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $allowed   = ['u.id', 'u.fetched_at', 'u.checked_at', 'u.size', 'u.host', 'u.local_path'];

        $query->order($db->quoteName(\in_array($ordering, $allowed, true) ? $ordering : 'u.id') . ' ' . $direction);

        return $query;
    }

    /**
     * What the content loads from other sites and nobody has tried to copy. Such an image can be
     * without a row of its own: the references are what tells of it.
     */
    private function getWaitingQuery()
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('MIN(' . $db->quoteName('r.id') . ') AS ' . $db->quoteName('id'))
            ->select($db->quoteName('r.path', 'url') . ', ' . $db->quoteName('r.path_hash', 'url_hash'))
            ->from($db->quoteName('#__mediaaudit_refs', 'r'))
            ->join('LEFT', $db->quoteName('#__mediaaudit_urls', 'u'), $db->quoteName('u.url_hash') . ' = ' . $db->quoteName('r.path_hash'))
            ->where($db->quoteName('r.is_external') . ' = 1')
            ->where(ReportRepository::isWaiting($db, 'u'))
            ->group($db->quoteName(['r.path', 'r.path_hash']));

        foreach (['host', 'http_status', 'error', 'checked_at', 'local_path', 'sha1', 'size', 'mime', 'fetched_at'] as $column) {
            $query->select('MAX(' . $db->quoteName('u.' . $column) . ') AS ' . $db->quoteName($column));
        }

        $host = trim((string) $this->getState('filter.host'));

        if ($host !== '') {
            $query->where($db->quoteName('r.path') . ' LIKE ' . $db->quote('%//' . $db->escape($host, true) . '/%', false));
        }

        $search = trim((string) $this->getState('filter.search'));

        if ($search !== '') {
            $query->where($db->quoteName('r.path') . ' LIKE ' . $db->quote('%' . $db->escape($search, true) . '%', false));
        }

        $direction = strtoupper((string) $this->getState('list.direction', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        // Nothing was copied: every order but the one by host is the one of the addresses
        $query->order($db->quoteName('r.path') . ' ' . ($this->getState('list.ordering') === 'u.host' ? $direction : 'ASC'));

        return $query;
    }

    public function getItems()
    {
        $items = parent::getItems();

        if (!$items) {
            return $items;
        }

        $db     = $this->getDatabase();
        $hashes = [];

        foreach ($items as $item) {
            $item->host ??= HttpFetcher::hostOf($item->url);
            $item->error = (string) $item->error;

            // What uses the copy refers to the path of the copy; what still loads the image from the
            // other site refers to the address
            $item->target_hash = $item->local_path === null ? $item->url_hash : NormalizedPath::hashOf($item->local_path);
            $hashes[]          = $item->target_hash;
            $hashes[]          = $item->url_hash;
        }

        // A copy that was converted is used by what loads the converted file
        $converted  = $this->converted(array_filter(array_column($items, 'local_path')));
        $references = SourceHelper::referencesTo($db, array_merge($hashes, array_map([NormalizedPath::class, 'hashOf'], $converted)));
        $files      = $this->files(array_filter(array_column($items, 'local_path')));

        foreach ($items as $item) {
            $item->converted  = $item->local_path === null ? null : ($converted[$item->local_path] ?? null);
            $item->references = array_merge(
                $references[$item->target_hash] ?? [],
                $item->converted === null ? [] : ($references[NormalizedPath::hashOf($item->converted)] ?? [])
            );
            $item->external   = $item->local_path === null ? [] : ($references[$item->url_hash] ?? []);
            $item->status     = $item->local_path === null ? null : ($files[$item->local_path] ?? 'gone');
        }

        return $items;
    }

    /**
     * @return  array<string, int>  Hosts of the images of other sites => images, by name
     */
    public function getHosts(): array
    {
        $hosts = [];

        foreach ((new ReportRepository($this->getDatabase()))->externalByHost() as $host) {
            $hosts[$host['host']] = $host['copied'] + $host['waiting'] + $host['failed'];
        }

        ksort($hosts);

        return $hosts;
    }

    /**
     * @return  array{copied: int, waiting: int, failed: int, unchanged: int}
     */
    public function getProgress(): array
    {
        return (new ReportRepository($this->getDatabase()))->externalTotals();
    }

    /**
     * @return  list<array{at: string|null, by: string, copied: int, waiting: int, failed: int}>
     */
    public function getCourse(): array
    {
        $zone = new \DateTimeZone(Factory::getApplication()->get('offset', 'UTC') ?: 'UTC');

        return (new ReportRepository($this->getDatabase()))->externalCourse($zone);
    }

    /**
     * @return  array{copies: int, files: int, bytes: int, failed: int, rewritten: int}
     */
    public function getTotals(): array
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('SUM(' . $db->quoteName('local_path') . ' IS NOT NULL) AS ' . $db->quoteName('copies'))
            ->select('COUNT(DISTINCT ' . $db->quoteName('local_path') . ') AS ' . $db->quoteName('files'))
            ->select('SUM(' . $db->quoteName('local_path') . ' IS NULL AND ' . $db->quoteName('error') . ' <> ' . $db->quote('') . ') AS ' . $db->quoteName('failed'))
            ->from($db->quoteName('#__mediaaudit_urls'));

        $totals = array_map('intval', (array) $db->setQuery($query)->loadAssoc());

        // Several addresses can have the same copy: every file counts once
        $query = $db->getQuery(true)
            ->select('COALESCE(SUM(' . $db->quoteName('s.size') . '), 0)')
            ->from('(SELECT ' . $db->quoteName('local_path') . ', MAX(' . $db->quoteName('size') . ') AS ' . $db->quoteName('size')
                . ' FROM ' . $db->quoteName('#__mediaaudit_urls') . ' WHERE ' . $db->quoteName('local_path') . ' IS NOT NULL'
                . ' GROUP BY ' . $db->quoteName('local_path') . ') AS ' . $db->quoteName('s'));

        $totals['bytes'] = (int) $db->setQuery($query)->loadResult();

        $query = $db->getQuery(true)
            ->select('COUNT(DISTINCT ' . $db->quoteName('source_type') . ', ' . $db->quoteName('source_id') . ')')
            ->from($db->quoteName('#__mediaaudit_rewrites'))
            ->where($db->quoteName('kind') . ' = ' . $db->quote('localize'))
            ->where($db->quoteName('reverted_at') . ' IS NULL');

        $totals['rewritten'] = (int) $db->setQuery($query)->loadResult();

        return $totals + ['copies' => 0, 'files' => 0, 'failed' => 0];
    }

    /**
     * @param   string[]  $paths  Of copies.
     *
     * @return  array<string, string>  Path of the copy => path of the file that it was converted to
     */
    private function converted(array $paths): array
    {
        if (!$paths) {
            return [];
        }

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['source_path', 'target_path']))
            ->from($db->quoteName('#__mediaaudit_conversions'))
            ->where($db->quoteName('status') . ' = ' . $db->quote('converted'))
            ->where($db->quoteName('target_path') . ' IS NOT NULL')
            ->where($db->quoteName('source_hash') . ' IN (' . implode(',', array_map([$db, 'quote'], array_map([NormalizedPath::class, 'hashOf'], array_unique($paths)))) . ')')
            ->order($db->quoteName('id') . ' ASC');

        return $db->setQuery($query)->loadAssocList('source_path', 'target_path');
    }

    /**
     * @param   string[]  $paths
     *
     * @return  array<string, string>  Path => "active" or "quarantined"
     */
    private function files(array $paths): array
    {
        if (!$paths) {
            return [];
        }

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['path', 'status']))
            ->from($db->quoteName('#__mediaaudit_files'))
            ->where($db->quoteName('path_hash') . ' IN (' . implode(',', array_map([$db, 'quote'], array_map([NormalizedPath::class, 'hashOf'], array_unique($paths)))) . ')');

        return $db->setQuery($query)->loadAssocList('path', 'status');
    }
}
