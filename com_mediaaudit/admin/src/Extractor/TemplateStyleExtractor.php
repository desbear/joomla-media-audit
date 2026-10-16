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
 * Template styles: logo, background and whatever else the params hold. Styles have no state.
 */
final class TemplateStyleExtractor extends AbstractTableExtractor
{
    public function getSourceType(): string
    {
        return 'template_style';
    }

    public function getContexts(): array
    {
        return ['com_templates.style'];
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
        return '#__template_styles';
    }

    protected function getColumns(): array
    {
        return array_merge($this->getRewritableHtmlColumns(), $this->getRewritableJsonColumns());
    }

    protected function getStateColumn(): ?string
    {
        return null;
    }

    protected function extractRow(object $row, ?int $state): array
    {
        $id         = (int) $row->id;
        $references = [];

        foreach ($this->getRewritableHtmlColumns() as $column) {
            $references[] = $this->builder->fromHtml($row->$column, 'template_style', $id, $column, $state);
        }

        foreach ($this->getRewritableJsonColumns() as $column) {
            $references[] = $this->builder->fromJson($row->$column, 'template_style', $id, $column, $state);
        }

        return array_merge(...$references);
    }
}
