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
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\ListModel;

\defined('_JEXEC') or die;

/**
 * The images on disk of one class. The models of the lists set the class.
 */
class FilesModel extends ListModel
{
    /** One of the class constants of ReportRepository. Empty for all. */
    protected string $class = '';

    /** The lists of the classes share their filters */
    protected $filterFormName = 'filter_files';

    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        $config['filter_fields'] ??= ['path', 'f.path', 'size', 'f.size', 'mtime', 'f.mtime', 'refs', 'search', 'source_type'];

        parent::__construct($config, $factory);
    }

    public function getClass(): string
    {
        return $this->class;
    }

    protected function populateState($ordering = 'f.path', $direction = 'ASC')
    {
        parent::populateState($ordering, $direction);
    }

    protected function getStoreId($id = '')
    {
        $id .= ':' . $this->class;
        $id .= ':' . $this->getState('filter.search');
        $id .= ':' . $this->getState('filter.source_type');

        return parent::getStoreId($id);
    }

    protected function getListQuery()
    {
        $db   = $this->getDatabase();
        $live = '(' . $db->quoteName('r.source_state') . ' = 1 OR ' . $db->quoteName('r.source_state') . ' IS NULL)';

        $query = $db->getQuery(true)
            ->select($db->quoteName(['f.id', 'f.path', 'f.path_hash', 'f.path_ci_hash', 'f.size', 'f.sha1', 'f.mtime']))
            ->select('COUNT(' . $db->quoteName('r.id') . ') AS ' . $db->quoteName('refs'))
            ->select('COALESCE(SUM(' . $db->quoteName('r.id') . ' IS NOT NULL AND ' . $live . '), 0) AS ' . $db->quoteName('live_refs'))
            ->from($db->quoteName('#__mediaaudit_files', 'f'))
            ->join(
                'LEFT',
                $db->quoteName('#__mediaaudit_refs', 'r'),
                $db->quoteName('r.path_hash') . ' = ' . $db->quoteName('f.path_hash') . ' AND ' . $db->quoteName('r.is_external') . ' = 0'
            )
            ->where($db->quoteName('f.status') . ' = ' . $db->quote('active'))
            ->group($db->quoteName(['f.id', 'f.path', 'f.path_hash', 'f.path_ci_hash', 'f.size', 'f.sha1', 'f.mtime']));

        if ($this->class === ReportRepository::ORPHAN) {
            $query->having($db->quoteName('refs') . ' = 0');
        } elseif ($this->class === ReportRepository::SEMI_ORPHAN) {
            $query->having($db->quoteName('refs') . ' > 0 AND ' . $db->quoteName('live_refs') . ' = 0');
        } elseif ($this->class === ReportRepository::IN_USE) {
            $query->having($db->quoteName('live_refs') . ' > 0');
        }

        $search = trim((string) $this->getState('filter.search'));

        if ($search !== '') {
            $query->where($db->quoteName('f.path') . ' LIKE ' . $db->quote('%' . $db->escape($search, true) . '%', false));
        }

        $type = (string) $this->getState('filter.source_type');

        if ($type !== '') {
            $query->where(
                'EXISTS (SELECT 1 FROM ' . $db->quoteName('#__mediaaudit_refs', 't')
                . ' WHERE ' . $db->quoteName('t.path_hash') . ' = ' . $db->quoteName('f.path_hash')
                . ' AND ' . $db->quoteName('t.source_type') . ' = ' . $db->quote($type) . ')'
            );
        }

        $ordering  = $this->getState('list.ordering', 'f.path');
        $direction = strtoupper((string) $this->getState('list.direction', 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

        $query->order($db->quoteName(\in_array($ordering, ['f.path', 'f.size', 'f.mtime', 'refs'], true) ? $ordering : 'f.path') . ' ' . $direction);

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
        $suspects   = $this->caseSuspects(array_column($items, 'path_ci_hash'));

        foreach ($items as $item) {
            $item->references   = $references[$item->path_hash] ?? [];
            $item->case_suspect = $suspects[$item->path_ci_hash][$item->path_hash] ?? null;
        }

        return $items;
    }

    /**
     * The paths of the files with these ids, for the actions on a selection.
     *
     * @param   int[]  $ids
     *
     * @return  string[]
     */
    public function getPaths(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if (!$ids) {
            return [];
        }

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('path'))
            ->from($db->quoteName('#__mediaaudit_files'))
            ->where($db->quoteName('status') . ' = ' . $db->quote('active'))
            ->where($db->quoteName('id') . ' IN (' . implode(',', $ids) . ')');

        return $db->setQuery($query)->loadColumn();
    }

    /**
     * References that are broken and differ from a file by case only.
     *
     * @param   string[]  $hashes  Case insensitive hashes of the files.
     *
     * @return  array<string, array<string, string>>  Case insensitive hash => hash of the file => path of the reference
     */
    private function caseSuspects(array $hashes): array
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('DISTINCT ' . $db->quoteName('f.path_ci_hash') . ', ' . $db->quoteName('f.path_hash') . ', ' . $db->quoteName('r.path'))
            ->from($db->quoteName('#__mediaaudit_files', 'f'))
            ->join(
                'INNER',
                $db->quoteName('#__mediaaudit_refs', 'r'),
                $db->quoteName('r.path_ci_hash') . ' = ' . $db->quoteName('f.path_ci_hash')
                . ' AND ' . $db->quoteName('r.path_hash') . ' <> ' . $db->quoteName('f.path_hash')
            )
            ->where($db->quoteName('r.is_external') . ' = 0')
            ->where($db->quoteName('r.exists_local') . ' = 0')
            ->where($db->quoteName('f.path_ci_hash') . ' IN (' . implode(',', array_map([$db, 'quote'], array_unique($hashes))) . ')');

        $suspects = [];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $suspects[$row->path_ci_hash][$row->path_hash] ??= $row->path;
        }

        return $suspects;
    }
}
