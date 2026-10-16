<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Model;

use Gss\Component\Mediaaudit\Administrator\Report\ReportRepository;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\ListModel;

\defined('_JEXEC') or die;

/**
 * Images with the same content under different paths. One row for every group.
 */
class DuplicatesModel extends ListModel
{
    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        $config['filter_fields'] ??= ['wasted', 'size', 'copies', 'search', 'derived'];

        parent::__construct($config, $factory);
    }

    protected function populateState($ordering = 'wasted', $direction = 'DESC')
    {
        parent::populateState($ordering, $direction);
    }

    protected function getStoreId($id = '')
    {
        $id .= ':' . $this->getState('filter.search');
        $id .= ':' . $this->getState('filter.derived');

        return parent::getStoreId($id);
    }

    /**
     * Whether the files that a component made of another image count as well.
     */
    public function withDerived(): bool
    {
        return (string) $this->getState('filter.derived') === '1';
    }

    /**
     * How many files are left out because a component made them of another image.
     */
    public function getDerivedCount(): int
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__mediaaudit_files', 'f'))
            ->where($db->quoteName('f.status') . ' = ' . $db->quote('active'))
            ->where(ReportRepository::isDerived($db, 'f'));

        return (int) $db->setQuery($query)->loadResult();
    }

    protected function getListQuery()
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('f.sha1'))
            ->select('MAX(' . $db->quoteName('f.size') . ') AS ' . $db->quoteName('size'))
            ->select('COUNT(*) AS ' . $db->quoteName('copies'))
            ->select('MAX(' . $db->quoteName('f.size') . ') * (COUNT(*) - 1) AS ' . $db->quoteName('wasted'))
            ->from($db->quoteName('#__mediaaudit_files', 'f'))
            ->where($db->quoteName('f.status') . ' = ' . $db->quote('active'))
            ->where($db->quoteName('f.sha1') . ' IS NOT NULL')
            ->group($db->quoteName('f.sha1'))
            ->having('COUNT(*) > 1');

        if (!$this->withDerived()) {
            $query->where('NOT ' . ReportRepository::isDerived($db, 'f'));
        }

        $search = trim((string) $this->getState('filter.search'));

        if ($search !== '') {
            // Groups with a file that matches, with all their files
            $query->where(
                $db->quoteName('f.sha1') . ' IN (SELECT ' . $db->quoteName('s.sha1') . ' FROM ' . $db->quoteName('#__mediaaudit_files', 's')
                . ' WHERE ' . $db->quoteName('s.path') . ' LIKE ' . $db->quote('%' . $db->escape($search, true) . '%', false) . ')'
            );
        }

        $ordering  = $this->getState('list.ordering', 'wasted');
        $direction = strtoupper((string) $this->getState('list.direction', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $query->order($db->quoteName(\in_array($ordering, ['wasted', 'size', 'copies'], true) ? $ordering : 'wasted') . ' ' . $direction)
            ->order($db->quoteName('f.sha1') . ' ASC');

        return $query;
    }

    public function getItems()
    {
        $groups = parent::getItems();

        if (!$groups) {
            return $groups;
        }

        $db    = $this->getDatabase();
        $live  = '(' . $db->quoteName('r.source_state') . ' = 1 OR ' . $db->quoteName('r.source_state') . ' IS NULL)';
        $query = $db->getQuery(true)
            ->select($db->quoteName(['f.id', 'f.path', 'f.sha1', 'f.mtime']))
            ->select('COUNT(' . $db->quoteName('r.id') . ') AS ' . $db->quoteName('refs'))
            ->select('COALESCE(SUM(' . $db->quoteName('r.id') . ' IS NOT NULL AND ' . $live . '), 0) AS ' . $db->quoteName('live_refs'))
            ->from($db->quoteName('#__mediaaudit_files', 'f'))
            ->join(
                'LEFT',
                $db->quoteName('#__mediaaudit_refs', 'r'),
                $db->quoteName('r.path_hash') . ' = ' . $db->quoteName('f.path_hash') . ' AND ' . $db->quoteName('r.is_external') . ' = 0'
            )
            ->where($db->quoteName('f.status') . ' = ' . $db->quote('active'))
            ->where($db->quoteName('f.sha1') . ' IN (' . implode(',', array_map([$db, 'quote'], array_column($groups, 'sha1'))) . ')')
            ->select('(' . ReportRepository::isDerived($db, 'f') . ') AS ' . $db->quoteName('derived'))
            ->group($db->quoteName(['f.id', 'f.path', 'f.sha1', 'f.mtime']))
            ->order($db->quoteName('refs') . ' DESC, ' . $db->quoteName('f.path') . ' ASC');

        if (!$this->withDerived()) {
            $query->where('NOT ' . ReportRepository::isDerived($db, 'f'));
        }

        $files = [];

        foreach ($db->setQuery($query)->loadObjectList() as $file) {
            $files[$file->sha1][] = $file;
        }

        foreach ($groups as $group) {
            $group->files = $files[$group->sha1] ?? [];
        }

        return $groups;
    }
}
