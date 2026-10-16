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
 * Tags: the description and the two images.
 */
final class TagExtractor extends AbstractTableExtractor
{
    public function getSourceType(): string
    {
        return 'tag';
    }

    public function getContexts(): array
    {
        return ['com_tags.tag'];
    }

    public function getRewritableHtmlColumns(): array
    {
        return ['description'];
    }

    public function getRewritableJsonColumns(): array
    {
        return ['images', 'params'];
    }

    protected function getTable(): string
    {
        return '#__tags';
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
            $references[] = $this->builder->fromHtml($row->$column, 'tag', $id, $column, $state);
        }

        foreach ($this->getRewritableJsonColumns() as $column) {
            $references[] = $this->builder->fromJson($row->$column, 'tag', $id, $column, $state);
        }

        return array_merge(...$references);
    }
}
