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
 * Modules of the site and of the administrator: the content of custom modules, and every value of
 * the params that looks like the path of an image.
 */
final class ModuleExtractor extends AbstractTableExtractor
{
    public function getSourceType(): string
    {
        return 'module';
    }

    protected function getTable(): string
    {
        return '#__modules';
    }

    protected function getColumns(): array
    {
        return ['content', 'params'];
    }

    public function getContexts(): array
    {
        return ['com_modules.module'];
    }

    public function getRewritableHtmlColumns(): array
    {
        return ['content'];
    }

    public function getRewritableJsonColumns(): array
    {
        return ['params'];
    }

    protected function getStateColumn(): ?string
    {
        return 'published';
    }

    protected function extractRow(object $row, ?int $state): array
    {
        $id = (int) $row->id;

        return array_merge(
            $this->builder->fromHtml($row->content, 'module', $id, 'content', $state),
            $this->builder->fromJson($row->params, 'module', $id, 'params', $state)
        );
    }
}
