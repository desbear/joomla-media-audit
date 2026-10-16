<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Extractor;

\defined('_JEXEC') or die;

/**
 * Menu items of the site: the icon, and what the params of the page hold.
 */
final class MenuExtractor extends AbstractTableExtractor
{
    public function getSourceType(): string
    {
        return 'menu';
    }

    public function getContexts(): array
    {
        return ['com_menus.item'];
    }

    public function getRewritableHtmlColumns(): array
    {
        return [];
    }

    public function getRewritableJsonColumns(): array
    {
        return ['params'];
    }

    protected function getTable(): string
    {
        return '#__menu';
    }

    protected function getColumns(): array
    {
        return array_merge($this->getRewritableHtmlColumns(), $this->getRewritableJsonColumns());
    }

    protected function getStateColumn(): ?string
    {
        return 'published';
    }

    protected function getConditions(): array
    {
        // Not the root of the tree, and not the menu of the administrator
        return [$this->db->quoteName('id') . ' > 1', $this->db->quoteName('client_id') . ' = 0'];
    }

    protected function extractRow(object $row, ?int $state): array
    {
        $id         = (int) $row->id;
        $references = [];

        foreach ($this->getRewritableHtmlColumns() as $column) {
            $references[] = $this->builder->fromHtml($row->$column, 'menu', $id, $column, $state);
        }

        foreach ($this->getRewritableJsonColumns() as $column) {
            $references[] = $this->builder->fromJson($row->$column, 'menu', $id, $column, $state);
        }

        return array_merge(...$references);
    }
}
