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
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\ListModel;

\defined('_JEXEC') or die;

/**
 * Broken images: references to local files that are not there, or to external images that do not
 * answer. One row for every target.
 */
class BrokenModel extends ListModel
{
    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        $config['filter_fields'] ??= ['target', 'refs', 'live_refs', 'search', 'source_type', 'kind', 'folder'];

        parent::__construct($config, $factory);
    }

    protected function populateState($ordering = 'target', $direction = 'ASC')
    {
        parent::populateState($ordering, $direction);
    }

    protected function getStoreId($id = '')
    {
        $id .= ':' . $this->getState('filter.search');
        $id .= ':' . $this->getState('filter.source_type');
        $id .= ':' . $this->getState('filter.kind');
        $id .= ':' . $this->getState('filter.folder');

        return parent::getStoreId($id);
    }

    public function isExternal(): bool
    {
        return $this->getState('filter.kind') === 'external';
    }

    protected function getListQuery()
    {
        $db       = $this->getDatabase();
        $live     = '(' . $db->quoteName('r.source_state') . ' = 1 OR ' . $db->quoteName('r.source_state') . ' IS NULL)';
        $external = $this->isExternal();

        $query = $db->getQuery(true)
            ->select($db->quoteName('r.path', 'target') . ', ' . $db->quoteName('r.path_hash') . ', ' . $db->quoteName('r.path_ci_hash'))
            ->select('COUNT(*) AS ' . $db->quoteName('refs'))
            ->select('COALESCE(SUM(' . $live . '), 0) AS ' . $db->quoteName('live_refs'))
            ->select('MAX(' . $db->quoteName('r.http_status') . ') AS ' . $db->quoteName('http_status'))
            ->select('MAX(' . $db->quoteName('r.checked_at') . ') AS ' . $db->quoteName('checked_at'))
            ->from($db->quoteName('#__mediaaudit_refs', 'r'))
            ->group($db->quoteName(['r.path', 'r.path_hash', 'r.path_ci_hash']));

        if ($external) {
            $query->select('MAX(' . $db->quoteName('u.error') . ') AS ' . $db->quoteName('error'))
                ->join('INNER', $db->quoteName('#__mediaaudit_urls', 'u'), $db->quoteName('u.url_hash') . ' = ' . $db->quoteName('r.path_hash'))
                ->where($db->quoteName('r.is_external') . ' = 1')
                ->where($db->quoteName('u.checked_at') . ' IS NOT NULL')
                ->where($db->quoteName('u.local_path') . ' IS NULL')
                ->where(
                    '(' . $db->quoteName('u.http_status') . ' < 200 OR ' . $db->quoteName('u.http_status') . ' >= 300 OR '
                    . $db->quoteName('u.error') . ' <> ' . $db->quote('') . ')'
                );
        } else {
            $query->select($db->quote('') . ' AS ' . $db->quoteName('error'))
                ->where($db->quoteName('r.is_external') . ' = 0')
                ->where($db->quoteName('r.exists_local') . ' = 0');

            // The report is about images/, unless another folder is asked for
            $folder = trim((string) $this->getState('filter.folder', 'images'), '/ ');

            if ($folder !== '' && $folder !== '*') {
                $query->where($db->quoteName('r.path') . ' LIKE ' . $db->quote($db->escape($folder, true) . '/%', false));
            }
        }

        $search = trim((string) $this->getState('filter.search'));

        if ($search !== '') {
            $query->where($db->quoteName('r.path') . ' LIKE ' . $db->quote('%' . $db->escape($search, true) . '%', false));
        }

        $type = (string) $this->getState('filter.source_type');

        if ($type !== '') {
            $query->where($db->quoteName('r.source_type') . ' = ' . $db->quote($type));
        }

        $ordering  = $this->getState('list.ordering', 'target');
        $direction = strtoupper((string) $this->getState('list.direction', 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

        $query->order($db->quoteName(\in_array($ordering, ['target', 'refs', 'live_refs'], true) ? $ordering : 'target') . ' ' . $direction);

        return $query;
    }

    public function getItems()
    {
        $items = parent::getItems();

        if (!$items) {
            return $items;
        }

        $db         = $this->getDatabase();
        $references = SourceHelper::referencesTo($db, array_column($items, 'path_hash'));
        $files      = $this->isExternal() ? [] : $this->filesInAnotherCase(array_column($items, 'path_ci_hash'));
        $quarantine = $this->isExternal() ? [] : $this->quarantined(array_column($items, 'path_hash'));

        foreach ($items as $item) {
            $item->references   = $references[$item->path_hash] ?? [];
            $item->case_suspect = $files[$item->path_ci_hash] ?? null;
            $item->quarantined  = isset($quarantine[$item->path_hash]);
        }

        return $items;
    }

    /**
     * @return  array<string, string>  Case insensitive hash => path of the file
     */
    private function filesInAnotherCase(array $hashes): array
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['path_ci_hash', 'path']))
            ->from($db->quoteName('#__mediaaudit_files'))
            ->where($db->quoteName('status') . ' = ' . $db->quote('active'))
            ->where($db->quoteName('path_ci_hash') . ' IN (' . implode(',', array_map([$db, 'quote'], array_unique($hashes))) . ')');

        return $db->setQuery($query)->loadAssocList('path_ci_hash', 'path');
    }

    /**
     * @return  array<string, string>  Hash of the path => path, of the files that are in quarantine
     */
    private function quarantined(array $hashes): array
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['path_hash', 'path']))
            ->from($db->quoteName('#__mediaaudit_files'))
            ->where($db->quoteName('status') . ' = ' . $db->quote('quarantined'))
            ->where($db->quoteName('path_hash') . ' IN (' . implode(',', array_map([$db, 'quote'], array_unique($hashes))) . ')');

        return $db->setQuery($query)->loadAssocList('path_hash', 'path');
    }
}
