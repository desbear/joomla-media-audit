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
 * Categories of every component: the description and the image.
 */
final class CategoryExtractor extends AbstractTableExtractor
{
    public function getSourceType(): string
    {
        return 'category';
    }

    public function getContexts(): array
    {
        return ['com_categories.category'];
    }

    public function getRewritableHtmlColumns(): array
    {
        return ['description'];
    }

    public function getRewritableJsonColumns(): array
    {
        return ['params'];
    }

    protected function getTable(): string
    {
        return '#__categories';
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
        // Not the root of the tree
        return [$this->db->quoteName('id') . ' > 1'];
    }

    protected function extractRow(object $row, ?int $state): array
    {
        $id         = (int) $row->id;
        $references = [];

        foreach ($this->getRewritableHtmlColumns() as $column) {
            $references[] = $this->builder->fromHtml($row->$column, 'category', $id, $column, $state);
        }

        foreach ($this->getRewritableJsonColumns() as $column) {
            $references[] = $this->builder->fromJson($row->$column, 'category', $id, $column, $state);
        }

        return array_merge(...$references);
    }
}
