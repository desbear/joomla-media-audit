<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Model;

use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\ListModel;

\defined('_JEXEC') or die;

/**
 * What was moved to the quarantine, and what was put back.
 */
class QuarantineModel extends ListModel
{
    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        $config['filter_fields'] ??= ['q.id', 'q.original_path', 'q.moved_at', 'q.restored_at', 'search', 'state'];

        parent::__construct($config, $factory);
    }

    protected function populateState($ordering = 'q.id', $direction = 'DESC')
    {
        parent::populateState($ordering, $direction);
    }

    protected function getStoreId($id = '')
    {
        $id .= ':' . $this->getState('filter.search');
        $id .= ':' . $this->getState('filter.state');

        return parent::getStoreId($id);
    }

    protected function getListQuery()
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('q') . '.*')
            ->select($db->quoteName('m.name', 'moved_by_name') . ', ' . $db->quoteName('b.name', 'restored_by_name') . ', ' . $db->quoteName('x.name', 'deleted_by_name'))
            ->select($db->quoteName('f.size'))
            ->from($db->quoteName('#__mediaaudit_quarantine', 'q'))
            ->join('LEFT', $db->quoteName('#__users', 'm'), $db->quoteName('m.id') . ' = ' . $db->quoteName('q.moved_by'))
            ->join('LEFT', $db->quoteName('#__users', 'b'), $db->quoteName('b.id') . ' = ' . $db->quoteName('q.restored_by'))
            ->join('LEFT', $db->quoteName('#__users', 'x'), $db->quoteName('x.id') . ' = ' . $db->quoteName('q.deleted_by'))
            ->join('LEFT', $db->quoteName('#__mediaaudit_files', 'f'), $db->quoteName('f.path') . ' = ' . $db->quoteName('q.original_path'));

        // In quarantine, unless the filter says otherwise
        $state = (string) $this->getState('filter.state', 'held');

        if ($state === 'restored') {
            $query->where($db->quoteName('q.restored_at') . ' IS NOT NULL');
        } elseif ($state === 'deleted') {
            $query->where($db->quoteName('q.deleted_at') . ' IS NOT NULL');
        } elseif ($state !== 'all') {
            $query->where($db->quoteName('q.restored_at') . ' IS NULL')
                ->where($db->quoteName('q.deleted_at') . ' IS NULL');
        }

        $search = trim((string) $this->getState('filter.search'));

        if ($search !== '') {
            $query->where($db->quoteName('q.original_path') . ' LIKE ' . $db->quote('%' . $db->escape($search, true) . '%', false));
        }

        $ordering  = $this->getState('list.ordering', 'q.id');
        $direction = strtoupper((string) $this->getState('list.direction', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $allowed   = ['q.id', 'q.original_path', 'q.moved_at', 'q.restored_at'];

        $query->order($db->quoteName(\in_array($ordering, $allowed, true) ? $ordering : 'q.id') . ' ' . $direction);

        return $query;
    }
}
