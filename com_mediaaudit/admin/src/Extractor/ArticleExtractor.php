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
 * Articles: the two texts, and the intro and full article images.
 */
final class ArticleExtractor extends AbstractTableExtractor
{
    public function getSourceType(): string
    {
        return 'article';
    }

    protected function getTable(): string
    {
        return '#__content';
    }

    protected function getColumns(): array
    {
        return ['introtext', 'fulltext', 'images'];
    }

    public function getContexts(): array
    {
        return ['com_content.article', 'com_content.form'];
    }

    public function getRewritableHtmlColumns(): array
    {
        return ['introtext', 'fulltext'];
    }

    public function getRewritableJsonColumns(): array
    {
        return ['images'];
    }

    protected function getStateColumn(): ?string
    {
        return 'state';
    }

    protected function extractRow(object $row, ?int $state): array
    {
        $id = (int) $row->id;

        return array_merge(
            $this->builder->fromHtml($row->introtext, 'article', $id, 'introtext', $state),
            $this->builder->fromHtml($row->fulltext, 'article', $id, 'fulltext', $state),
            $this->builder->fromJson($row->images, 'article', $id, 'images', $state)
        );
    }
}
