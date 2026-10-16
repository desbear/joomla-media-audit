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
 * Banners: the image is in the params, the code and the description are HTML.
 */
final class BannerExtractor extends AbstractTableExtractor
{
    public function getSourceType(): string
    {
        return 'banner';
    }

    public function getContexts(): array
    {
        return ['com_banners.banner'];
    }

    public function getRewritableHtmlColumns(): array
    {
        return ['custombannercode', 'description'];
    }

    public function getRewritableJsonColumns(): array
    {
        return ['params'];
    }

    protected function getTable(): string
    {
        return '#__banners';
    }

    protected function getColumns(): array
    {
        return array_merge($this->getRewritableHtmlColumns(), $this->getRewritableJsonColumns());
    }

    protected function getStateColumn(): ?string
    {
        return 'state';
    }

    protected function extractRow(object $row, ?int $state): array
    {
        $id         = (int) $row->id;
        $references = [];

        foreach ($this->getRewritableHtmlColumns() as $column) {
            $references[] = $this->builder->fromHtml($row->$column, 'banner', $id, $column, $state);
        }

        foreach ($this->getRewritableJsonColumns() as $column) {
            $references[] = $this->builder->fromJson($row->$column, 'banner', $id, $column, $state);
        }

        return array_merge(...$references);
    }
}
