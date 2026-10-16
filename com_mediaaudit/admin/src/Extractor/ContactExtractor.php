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
 * Contacts: the text, and what the params hold. The image has a column of its own.
 */
final class ContactExtractor extends AbstractTableExtractor
{
    public function getSourceType(): string
    {
        return 'contact';
    }

    public function getContexts(): array
    {
        return ['com_contact.contact', 'com_contact.form'];
    }

    public function getRewritableHtmlColumns(): array
    {
        return ['misc'];
    }

    public function getRewritableJsonColumns(): array
    {
        return ['params'];
    }

    protected function getTable(): string
    {
        return '#__contact_details';
    }

    protected function getColumns(): array
    {
        return array_merge($this->getRewritableHtmlColumns(), $this->getRewritableJsonColumns(), ['image']);
    }

    protected function getStateColumn(): ?string
    {
        return 'published';
    }

    protected function extractRow(object $row, ?int $state): array
    {
        $id         = (int) $row->id;
        $references = [];

        foreach ($this->getRewritableHtmlColumns() as $column) {
            $references[] = $this->builder->fromHtml($row->$column, 'contact', $id, $column, $state);
        }

        foreach ($this->getRewritableJsonColumns() as $column) {
            $references[] = $this->builder->fromJson($row->$column, 'contact', $id, $column, $state);
        }

        $references[] = $this->builder->fromValue($row->image, 'contact', $id, 'image', $state);

        return array_merge(...$references);
    }
}
