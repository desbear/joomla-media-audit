<?php

/**
 * @package     Media Audit
 * @subpackage  plg_mediaaudit_falang
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mediaaudit\Falang\Extractor;

use Gss\Component\Mediaaudit\Administrator\Extractor\AbstractTableExtractor;
use Gss\Component\Mediaaudit\Administrator\Service\PathNormalizer;
use Gss\Component\Mediaaudit\Administrator\Service\ReferenceBuilder;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * The translations of Falang. A source is one translated value.
 *
 * A translation is as published as what it translates: the translation of a trashed article is
 * shown to nobody, whatever Falang says of the translation itself.
 */
final class FalangExtractor extends AbstractTableExtractor
{
    /** Table of the original, as Falang names it => column of its state */
    private const ORIGINALS = [
        'content'         => 'state',
        'categories'      => 'published',
        'modules'         => 'published',
        'menu'            => 'published',
        'tags'            => 'published',
        'contact_details' => 'published',
        'banners'         => 'state',
    ];

    public function __construct(DatabaseInterface $db, ReferenceBuilder $builder, string $root = '')
    {
        parent::__construct($db, $builder);
    }

    public function getSourceType(): string
    {
        return 'falang';
    }

    protected function getTable(): string
    {
        return '#__falang_content';
    }

    protected function getColumns(): array
    {
        return ['value', 'reference_table', 'reference_field'];
    }

    protected function getStateColumn(): ?string
    {
        return 'published';
    }

    protected function getConditions(): array
    {
        return [$this->db->quoteName('value') . ' <> ' . $this->db->quote('')];
    }

    protected function getQuery()
    {
        $db    = $this->db;
        $own   = $db->quoteName('#__falang_content');
        $cases = '';

        foreach (self::ORIGINALS as $table => $column) {
            $cases .= ' WHEN ' . $db->quote($table) . ' THEN (SELECT ' . $db->quoteName('o.' . $column)
                . ' FROM ' . $db->quoteName('#__' . $table, 'o')
                . ' WHERE ' . $db->quoteName('o.id') . ' = ' . $own . '.' . $db->quoteName('reference_id') . ')';
        }

        // Originals of tables that are not in the list have no state that we know: published
        return parent::getQuery()
            ->select('CASE ' . $own . '.' . $db->quoteName('reference_table') . $cases . ' ELSE 1 END AS ' . $db->quoteName('original_state'))
            ->select($own . '.' . $db->quoteName('reference_table') . ' IN (' . implode(',', array_map([$db, 'quote'], array_keys(self::ORIGINALS))) . ') AS ' . $db->quoteName('has_original'));
    }

    protected function extractRow(object $row, ?int $state): array
    {
        $id    = (int) $row->id;
        $field = $row->reference_table . '.' . $row->reference_field;
        $value = trim((string) $row->value);

        if ($state === 1) {
            // The original is gone, or it is not published
            $state = $row->original_state === null ? ((int) $row->has_original ? 0 : 1) : (int) $row->original_state;
        }

        if ($value[0] === '{' || $value[0] === '[') {
            return $this->builder->fromJson($value, 'falang', $id, $field, $state);
        }

        if (str_contains($value, '<') || stripos($value, 'url(') !== false) {
            return $this->builder->fromHtml($value, 'falang', $id, $field, $state);
        }

        return PathNormalizer::looksLikeImage($value) ? $this->builder->fromValue($value, 'falang', $id, $field, $state) : [];
    }
}
