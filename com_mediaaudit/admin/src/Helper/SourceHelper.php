<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Helper;

use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * What the administrator needs to know of the sources of references: their titles, and where to
 * edit them.
 */
final class SourceHelper
{
    /** Type => table, column of the title, link to the edit form */
    private const SOURCES = [
        'article'        => ['#__content', 'title', 'index.php?option=com_content&task=article.edit&id=%d'],
        'module'         => ['#__modules', 'title', 'index.php?option=com_modules&task=module.edit&id=%d'],
        'category'       => ['#__categories', 'title', 'index.php?option=com_categories&task=category.edit&id=%d'],
        'menu'           => ['#__menu', 'title', 'index.php?option=com_menus&task=item.edit&id=%d'],
        'template_style' => ['#__template_styles', 'title', 'index.php?option=com_templates&task=style.edit&id=%d'],
        'tag'            => ['#__tags', 'title', 'index.php?option=com_tags&task=tag.edit&id=%d'],
        'contact'        => ['#__contact_details', 'name', 'index.php?option=com_contact&task=contact.edit&id=%d'],
        'banner'         => ['#__banners', 'name', 'index.php?option=com_banners&task=banner.edit&id=%d'],
        'article_field'  => ['#__content', 'title', 'index.php?option=com_content&task=article.edit&id=%d'],
        'category_field' => ['#__categories', 'title', 'index.php?option=com_categories&task=category.edit&id=%d'],
        'contact_field'  => ['#__contact_details', 'name', 'index.php?option=com_contact&task=contact.edit&id=%d'],
        'user_field'     => ['#__users', 'name', 'index.php?option=com_users&task=user.edit&id=%d'],
        // Of the plugins of the mediaaudit group
        'flexicontent'   => ['#__content', 'title', 'index.php?option=com_flexicontent&task=items.edit&view=item&id=%d'],
        'flexicontent_field' => ['#__flexicontent_fields', 'label', 'index.php?option=com_flexicontent&task=fields.edit&view=field&id=%d'],
        'easyblog'       => ['#__easyblog_post', 'title', 'index.php?option=com_easyblog&view=composer&tmpl=component&uid=%d'],
        'falang'         => ['#__falang_content', 'reference_field', 'index.php?option=com_falang&task=translate.overview'],
    ];

    /**
     * The references to the targets, with the title and the edit link of every source.
     *
     * @param   string[]  $hashes  Of the paths or URLs.
     *
     * @return  array<string, list<array{type: string, id: int, field: string, state: ?int, title: string, link: ?string}>>  By hash
     */
    public static function referencesTo(DatabaseInterface $db, array $hashes, int $perTarget = 50): array
    {
        $hashes = array_values(array_unique(array_filter($hashes)));

        if (!$hashes) {
            return [];
        }

        $query = $db->getQuery(true)
            ->select($db->quoteName(['path_hash', 'source_type', 'source_id', 'field', 'source_state']))
            ->from($db->quoteName('#__mediaaudit_refs'))
            ->where($db->quoteName('path_hash') . ' IN (' . implode(',', array_map([$db, 'quote'], $hashes)) . ')')
            ->order($db->quoteName('source_type') . ' ASC, ' . $db->quoteName('source_id') . ' ASC');

        $references = [];
        $ids        = [];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            if (\count($references[$row->path_hash] ?? []) >= $perTarget) {
                continue;
            }

            $references[$row->path_hash][] = [
                'type'  => $row->source_type,
                'id'    => (int) $row->source_id,
                'field' => $row->field,
                'state' => $row->source_state === null ? null : (int) $row->source_state,
                'title' => '',
                'link'  => isset(self::SOURCES[$row->source_type]) && $row->source_id
                    ? @\sprintf(self::SOURCES[$row->source_type][2], $row->source_id)
                    : null,
            ];

            $ids[$row->source_type][(int) $row->source_id] = true;
        }

        $titles = self::titles($db, $ids);

        foreach ($references as &$list) {
            foreach ($list as &$reference) {
                $reference['title'] = $titles[$reference['type']][$reference['id']] ?? '';
            }
        }

        return $references;
    }

    /**
     * What to call a kind of source. Kinds that plugins add, and that have no name in the language
     * files, go by the name that the plugin gave them.
     */
    public static function label(string $type): string
    {
        $key  = 'COM_MEDIAAUDIT_SOURCE_' . strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $type));
        $text = \Joomla\CMS\Language\Text::_($key);

        return $text === $key ? $type : $text;
    }

    /**
     * @return  string[]  The types of sources that the index knows.
     */
    public static function types(DatabaseInterface $db): array
    {
        $query = $db->getQuery(true)
            ->select('DISTINCT ' . $db->quoteName('source_type'))
            ->from($db->quoteName('#__mediaaudit_refs'))
            ->order($db->quoteName('source_type') . ' ASC');

        return $db->setQuery($query)->loadColumn();
    }

    /**
     * @param   array<string, array<int, true>>  $ids  By type
     *
     * @return  array<string, array<int, string>>
     */
    private static function titles(DatabaseInterface $db, array $ids): array
    {
        $titles = [];

        foreach ($ids as $type => $list) {
            if (!isset(self::SOURCES[$type])) {
                continue;
            }

            [$table, $column] = self::SOURCES[$type];

            $query = $db->getQuery(true)
                ->select($db->quoteName(['id', $column]))
                ->from($db->quoteName($table))
                ->where($db->quoteName('id') . ' IN (' . implode(',', array_map('intval', array_keys($list))) . ')');

            try {
                $titles[$type] = $db->setQuery($query)->loadAssocList('id', $column);
            } catch (\Throwable $e) {
                // A table of an extension that was removed
            }
        }

        return $titles;
    }
}
