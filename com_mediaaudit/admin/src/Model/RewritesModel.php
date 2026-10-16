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
 * What Media Audit changed in the content: addresses of images that became paths of copies, and
 * lost images that became a note. One row for every column that was changed.
 */
class RewritesModel extends ListModel
{
    /** Type of source => table, column of the title, link to the edit form */
    private const SOURCES = [
        'article'  => ['#__content', 'title', 'index.php?option=com_content&task=article.edit&id=%d'],
        'module'   => ['#__modules', 'title', 'index.php?option=com_modules&task=module.edit&id=%d'],
        'category' => ['#__categories', 'title', 'index.php?option=com_categories&task=category.edit&id=%d'],
        'tag'      => ['#__tags', 'title', 'index.php?option=com_tags&task=tag.edit&id=%d'],
        'contact'  => ['#__contact_details', 'name', 'index.php?option=com_contact&task=contact.edit&id=%d'],
        'banner'   => ['#__banners', 'name', 'index.php?option=com_banners&task=banner.edit&id=%d'],
        'menu'     => ['#__menu', 'title', 'index.php?option=com_menus&task=item.edit&id=%d'],
    ];

    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        $config['filter_fields'] ??= ['w.id', 'w.rewritten_at', 'w.replaced', 'w.source_id', 'search', 'source_type', 'state', 'kind'];

        parent::__construct($config, $factory);
    }

    protected function populateState($ordering = 'w.id', $direction = 'DESC')
    {
        parent::populateState($ordering, $direction);
    }

    protected function getStoreId($id = '')
    {
        $id .= ':' . $this->getState('filter.search');
        $id .= ':' . $this->getState('filter.source_type');
        $id .= ':' . $this->getState('filter.state');
        $id .= ':' . $this->getState('filter.kind');

        return parent::getStoreId($id);
    }

    protected function getListQuery()
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['w.id', 'w.source_type', 'w.source_id', 'w.field', 'w.kind', 'w.replaced', 'w.rewritten_by', 'w.rewritten_at', 'w.reverted_at']))
            ->select($db->quoteName('u.name', 'rewritten_by_name'))
            ->from($db->quoteName('#__mediaaudit_rewrites', 'w'))
            ->join('LEFT', $db->quoteName('#__users', 'u'), $db->quoteName('u.id') . ' = ' . $db->quoteName('w.rewritten_by'));

        $state = (string) $this->getState('filter.state', 'kept');

        if ($state === 'reverted') {
            $query->where($db->quoteName('w.reverted_at') . ' IS NOT NULL');
        } elseif ($state !== 'all') {
            $query->where($db->quoteName('w.reverted_at') . ' IS NULL');
        }

        $type = (string) $this->getState('filter.source_type');

        if ($type !== '') {
            $query->where($db->quoteName('w.source_type') . ' = ' . $db->quote($type));
        }

        $kind = (string) $this->getState('filter.kind');

        if ($kind !== '') {
            $query->where($db->quoteName('w.kind') . ' = ' . $db->quote($kind));
        }

        $search = trim((string) $this->getState('filter.search'));

        if ($search !== '') {
            if (ctype_digit($search)) {
                $query->where($db->quoteName('w.source_id') . ' = ' . (int) $search);
            } else {
                // The title of an article: the kind of source that is rewritten most
                $query->join('LEFT', $db->quoteName('#__content', 'c'), $db->quoteName('c.id') . ' = ' . $db->quoteName('w.source_id') . ' AND ' . $db->quoteName('w.source_type') . ' = ' . $db->quote('article'))
                    ->where($db->quoteName('c.title') . ' LIKE ' . $db->quote('%' . $db->escape($search, true) . '%', false));
            }
        }

        $ordering  = $this->getState('list.ordering', 'w.id');
        $direction = strtoupper((string) $this->getState('list.direction', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $allowed   = ['w.id', 'w.rewritten_at', 'w.replaced', 'w.source_id'];

        $query->order($db->quoteName(\in_array($ordering, $allowed, true) ? $ordering : 'w.id') . ' ' . $direction);

        return $query;
    }

    public function getItems()
    {
        $items = parent::getItems();

        if (!$items) {
            return $items;
        }

        $db  = $this->getDatabase();
        $ids = [];

        foreach ($items as $item) {
            $ids[$item->source_type][(int) $item->source_id] = true;
        }

        $sources = [];

        foreach ($ids as $type => $list) {
            if (!isset(self::SOURCES[$type])) {
                continue;
            }

            [$table, $column] = self::SOURCES[$type];

            $query = $db->getQuery(true)
                ->select($db->quoteName('id') . ', ' . $db->quoteName($column, 'title') . ', ' . $db->quoteName($type === 'article' || $type === 'banner' ? 'state' : 'published', 'state'))
                ->from($db->quoteName($table))
                ->where($db->quoteName('id') . ' IN (' . implode(',', array_map('intval', array_keys($list))) . ')');

            try {
                $sources[$type] = $db->setQuery($query)->loadObjectList('id');
            } catch (\Throwable $e) {
                // A table of an extension that was removed
            }
        }

        foreach ($items as $item) {
            $source = $sources[$item->source_type][(int) $item->source_id] ?? null;

            $item->title = $source->title ?? null;
            $item->state = $source === null ? null : (int) $source->state;
            $item->link  = $source === null || !isset(self::SOURCES[$item->source_type])
                ? null
                : \sprintf(self::SOURCES[$item->source_type][2], $item->source_id);
        }

        return $items;
    }
}
