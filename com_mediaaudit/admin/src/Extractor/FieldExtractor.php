<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Extractor;

use Gss\Component\Mediaaudit\Administrator\Service\ReferenceBuilder;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * The values of the custom fields of one kind of item.
 *
 * A source is an item, not a value: the values of a field have no id of their own. The name of the
 * field is in the name of the reference, like "fields.photo".
 */
final class FieldExtractor implements ReferenceExtractorInterface, ContextAwareInterface, RewritableValuesInterface
{
    /**
     * Types of fields that are left as they are. A list of images offers the files of a folder that
     * end the way its options say: a file of another format would not be in the list.
     */
    private const FIXED_TYPES = ['imagelist'];

    /** Context of the fields => type of source, table of the items, column of the state, contexts of the events */
    public const KINDS = [
        'com_content.article'    => ['article_field', '#__content', 'state', ['com_content.article', 'com_content.form']],
        'com_content.categories' => ['category_field', '#__categories', 'published', ['com_categories.category']],
        'com_contact.contact'    => ['contact_field', '#__contact_details', 'published', ['com_contact.contact', 'com_contact.form']],
        'com_users.user'         => ['user_field', '#__users', null, ['com_users.user', 'com_users.profile', 'com_users.registration']],
    ];

    private string $type;

    private string $table;

    private ?string $stateColumn;

    /** @var string[] */
    private array $contexts;

    /** @var array<int, object>|null  The fields of the context, by id */
    private ?array $fields = null;

    public function __construct(private DatabaseInterface $db, private ReferenceBuilder $builder, private string $fieldContext)
    {
        if (!isset(self::KINDS[$fieldContext])) {
            throw new \InvalidArgumentException('No custom fields are known for ' . $fieldContext);
        }

        [$this->type, $this->table, $this->stateColumn, $this->contexts] = self::KINDS[$fieldContext];
    }

    public function getSourceType(): string
    {
        return $this->type;
    }

    public function getContexts(): array
    {
        return $this->contexts;
    }

    public function getSourceIds(string $context, object $row): array
    {
        return empty($row->id) ? [] : [(int) $row->id];
    }

    public function getRewritableValues(int $sourceId): array
    {
        $fields = array_filter($this->getFields(), static fn (object $field): bool => !\in_array($field->type, self::FIXED_TYPES, true));

        if (!$fields || $sourceId <= 0) {
            return [];
        }

        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['field_id', 'value']))
            ->from($db->quoteName('#__fields_values'))
            ->where($db->quoteName('field_id') . ' IN (' . implode(',', array_keys($fields)) . ')')
            ->where($db->quoteName('item_id') . ' = ' . $db->quote((string) $sourceId))
            ->where($db->quoteName('value') . ' <> ' . $db->quote(''));

        $values = [];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $values[] = ['key' => 'fields.' . $fields[(int) $row->field_id]->name, 'value' => (string) $row->value];
        }

        return $values;
    }

    public function setRewritableValue(int $sourceId, string $key, string $from, string $to): bool
    {
        foreach ($this->getFields() as $field) {
            if ('fields.' . $field->name !== $key || \in_array($field->type, self::FIXED_TYPES, true)) {
                continue;
            }

            $db    = $this->db;
            $query = $db->getQuery(true)
                ->update($db->quoteName('#__fields_values'))
                ->set($db->quoteName('value') . ' = ' . $db->quote($to))
                ->where($db->quoteName('field_id') . ' = ' . (int) $field->id)
                ->where($db->quoteName('item_id') . ' = ' . $db->quote((string) $sourceId))
                ->where($db->quoteName('value') . ' = ' . $db->quote($from));

            $db->setQuery($query)->execute();

            return $db->getAffectedRows() > 0;
        }

        return false;
    }

    public function getFixedFields(): array
    {
        $fixed = [];

        foreach ($this->getFields() as $field) {
            if (\in_array($field->type, self::FIXED_TYPES, true)) {
                $fixed[] = 'fields.' . $field->name;
            }
        }

        return $fixed;
    }

    /**
     * The values are written by the fields plugin, in the same event that tells us of the save.
     */
    public function isWrittenLater(): bool
    {
        return true;
    }

    public function extractAll(callable $emit, int $batchSize = 200): void
    {
        if (!$this->getFields()) {
            return;
        }

        $db   = $this->db;
        $last = 0;

        do {
            // The items that have values, a batch of them
            $query = $db->getQuery(true)
                ->select('DISTINCT CAST(' . $db->quoteName('item_id') . ' AS UNSIGNED) AS ' . $db->quoteName('id'))
                ->from($db->quoteName('#__fields_values'))
                ->where($db->quoteName('field_id') . ' IN (' . implode(',', array_keys($this->getFields())) . ')')
                ->where('CAST(' . $db->quoteName('item_id') . ' AS UNSIGNED) > ' . $last)
                ->order($db->quoteName('id') . ' ASC')
                ->setLimit(max(1, $batchSize));

            $ids = array_map('intval', $db->setQuery($query)->loadColumn());

            if (!$ids) {
                break;
            }

            $last = max($ids);

            foreach ($this->extract($ids) as $reference) {
                $emit($reference);
            }
        } while (\count($ids) === max(1, $batchSize));
    }

    public function extractOne(int $sourceId): array
    {
        return $this->getFields() ? $this->extract([$sourceId]) : [];
    }

    /**
     * @param   int[]  $ids  Of the items.
     *
     * @return  \Gss\Component\Mediaaudit\Administrator\Service\Reference[]
     */
    private function extract(array $ids): array
    {
        $db     = $this->db;
        $states = $this->states($ids);
        $query  = $db->getQuery(true)
            ->select($db->quoteName(['field_id', 'item_id', 'value']))
            ->from($db->quoteName('#__fields_values'))
            ->where($db->quoteName('field_id') . ' IN (' . implode(',', array_keys($this->getFields())) . ')')
            ->where($db->quoteName('item_id') . ' IN (' . implode(',', array_map([$db, 'quote'], $ids)) . ')')
            ->where($db->quoteName('value') . ' <> ' . $db->quote(''));

        $references = [];

        foreach ($db->setQuery($query)->loadObjectList() as $value) {
            $id = (int) $value->item_id;

            // Values of items that are gone
            if (!\array_key_exists($id, $states)) {
                continue;
            }

            $references[] = $this->read($this->getFields()[(int) $value->field_id], $id, (string) $value->value, $states[$id]);
        }

        return ReferenceBuilder::unique(array_merge(...$references));
    }

    /**
     * @return  \Gss\Component\Mediaaudit\Administrator\Service\Reference[]
     */
    private function read(object $field, int $id, string $value, ?int $state): array
    {
        $name = 'fields.' . $field->name;

        switch ($field->type) {
            case 'editor':
            case 'textarea':
                return $this->builder->fromHtml($value, $this->type, $id, $name, $state);

            case 'imagelist':
                // The name of a file in the folder of the field, below images/
                if ($value === '-1') {
                    return [];
                }

                $folder = trim((string) (json_decode((string) $field->fieldparams, true)['directory'] ?? ''), '/');

                return $this->builder->fromValue('images/' . ($folder === '' ? '' : $folder . '/') . $value, $this->type, $id, $name, $state);

            case 'media':
            case 'subform':
            case 'repeatable':
            default:
                // JSON since Joomla 4, a plain path before
                if ($value[0] === '{' || $value[0] === '[') {
                    return $this->builder->fromJson([$field->name => json_decode($value, true)], $this->type, $id, 'fields', $state);
                }

                if (str_contains($value, '<')) {
                    return $this->builder->fromHtml($value, $this->type, $id, $name, $state);
                }

                return \Gss\Component\Mediaaudit\Administrator\Service\PathNormalizer::looksLikeImage($value)
                    ? $this->builder->fromValue($value, $this->type, $id, $name, $state)
                    : [];
        }
    }

    /**
     * @return  array<int, object>
     */
    private function getFields(): array
    {
        if ($this->fields === null) {
            $db    = $this->db;
            $query = $db->getQuery(true)
                ->select($db->quoteName(['id', 'name', 'type', 'fieldparams']))
                ->from($db->quoteName('#__fields'))
                ->where($db->quoteName('context') . ' = ' . $db->quote($this->fieldContext));

            $this->fields = $db->setQuery($query)->loadObjectList('id');
        }

        return $this->fields;
    }

    /**
     * @param   int[]  $ids
     *
     * @return  array<int, int|null>  The state of the items that exist, by id
     */
    private function states(array $ids): array
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->select(($this->stateColumn === null ? 'NULL' : $db->quoteName($this->stateColumn)) . ' AS ' . $db->quoteName('state'))
            ->from($db->quoteName($this->table))
            ->where($db->quoteName('id') . ' IN (' . implode(',', array_map('intval', $ids)) . ')');

        $states = [];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $states[(int) $row->id] = $row->state === null ? null : (int) $row->state;
        }

        return $states;
    }
}
