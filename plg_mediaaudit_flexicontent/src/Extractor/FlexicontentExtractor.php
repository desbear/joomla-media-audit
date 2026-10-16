<?php

/**
 * @package     Media Audit
 * @subpackage  plg_mediaaudit_flexicontent
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mediaaudit\Flexicontent\Extractor;

use Gss\Component\Mediaaudit\Administrator\Extractor\ContextAwareInterface;
use Gss\Component\Mediaaudit\Administrator\Extractor\ReferenceExtractorInterface;
use Gss\Component\Mediaaudit\Administrator\Extractor\RewritableValuesInterface;
use Gss\Component\Mediaaudit\Administrator\Extractor\RewriteListenerInterface;
use Gss\Component\Mediaaudit\Administrator\Service\CaseSensitiveFilesystem;
use Gss\Component\Mediaaudit\Administrator\Service\PathNormalizer;
use Gss\Component\Mediaaudit\Administrator\Service\ReferenceBuilder;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * The values of the fields of FLEXIcontent. A source is an item, which is an article of Joomla: its
 * texts are read by the article extractor.
 *
 * Of an image, the thumbnails that FLEXIcontent made count as referred to as well. Only the
 * thumbnails that are on disk: one that was never made is not a broken image.
 */
final class FlexicontentExtractor implements ReferenceExtractorInterface, ContextAwareInterface, RewriteListenerInterface, RewritableValuesInterface
{
    /** Types of fields that hold text written by a person */
    private const HTML_TYPES = ['textarea', 'text', 'maintext'];

    /** Of them, the ones whose values are rewritten. The main text is a column of the article */
    private const TEXT_TYPES = ['textarea', 'text'];

    /** @var array<int, object>|null  The fields that can hold images, by id */
    private ?array $fields = null;

    /** @var array<string, mixed>|null */
    private ?array $component = null;

    private ?CaseSensitiveFilesystem $filesystem;

    /** @var callable|null  function (string $path): bool. For the tests. */
    private $exists;

    public function __construct(private DatabaseInterface $db, private ReferenceBuilder $builder, string $root = '', ?callable $exists = null)
    {
        $this->filesystem = $root === '' ? null : new CaseSensitiveFilesystem($root);
        $this->exists     = $exists;
    }

    public function getSourceType(): string
    {
        return 'flexicontent';
    }

    public function getContexts(): array
    {
        return ['com_content.article', 'com_content.form', 'com_flexicontent.item', 'com_flexicontent.form'];
    }

    public function getSourceIds(string $context, object $row): array
    {
        return empty($row->id) ? [] : [(int) $row->id];
    }

    /**
     * The fields that a person writes text in. The value of an image field is the name of a file
     * that FLEXIcontent makes its thumbnails of, and its own option writes them as WebP: it is left
     * to FLEXIcontent. So are the values that hold more than text, which are written by serialize().
     */
    public function getRewritableValues(int $sourceId): array
    {
        $fields = $this->getTextFields();

        if (!$fields || $sourceId <= 0) {
            return [];
        }

        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['field_id', 'value']))
            ->from($db->quoteName('#__flexicontent_fields_item_relations'))
            ->where($db->quoteName('field_id') . ' IN (' . implode(',', array_keys($fields)) . ')')
            ->where($db->quoteName('item_id') . ' = ' . $sourceId)
            ->where($db->quoteName('value') . ' <> ' . $db->quote(''))
            ->order($db->quoteName('field_id') . ' ASC, ' . $db->quoteName('valueorder') . ' ASC, ' . $db->quoteName('suborder') . ' ASC');

        $values = [];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            if (!preg_match('/^[abisOCN]:\d*[:;]/', (string) $row->value)) {
                $values[] = ['key' => 'fields.' . $fields[(int) $row->field_id]->name, 'value' => (string) $row->value];
            }
        }

        return $values;
    }

    public function setRewritableValue(int $sourceId, string $key, string $from, string $to): bool
    {
        foreach ($this->getTextFields() as $field) {
            if ('fields.' . $field->name !== $key) {
                continue;
            }

            $db    = $this->db;
            $query = $db->getQuery(true)
                ->update($db->quoteName('#__flexicontent_fields_item_relations'))
                ->set($db->quoteName('value') . ' = ' . $db->quote($to))
                ->where($db->quoteName('field_id') . ' = ' . (int) $field->id)
                ->where($db->quoteName('item_id') . ' = ' . $sourceId)
                ->where($db->quoteName('value') . ' = ' . $db->quote($from));

            $db->setQuery($query)->execute();

            if ($db->getAffectedRows() === 0) {
                return false;
            }

            // The version that is shown is to say what the item says
            $version = $db->getQuery(true)
                ->select($db->quoteName('version'))
                ->from($db->quoteName('#__content'))
                ->where($db->quoteName('id') . ' = ' . $sourceId);

            $query = $db->getQuery(true)
                ->update($db->quoteName('#__flexicontent_items_versions'))
                ->set($db->quoteName('value') . ' = ' . $db->quote($to))
                ->where($db->quoteName('field_id') . ' = ' . (int) $field->id)
                ->where($db->quoteName('item_id') . ' = ' . $sourceId)
                ->where($db->quoteName('version') . ' = ' . (int) $db->setQuery($version)->loadResult())
                ->where($db->quoteName('value') . ' = ' . $db->quote($from));

            $db->setQuery($query)->execute();

            return true;
        }

        return false;
    }

    public function getFixedFields(): array
    {
        $fixed = [];

        foreach ($this->getFields() as $field) {
            if (!\in_array($field->field_type, self::TEXT_TYPES, true)) {
                $fixed[] = 'fields.' . $field->name;
            }
        }

        return $fixed;
    }

    /**
     * @return  array<int, object>  The fields that a person writes text in, by id
     */
    private function getTextFields(): array
    {
        return array_filter($this->getFields(), static fn (object $field): bool => \in_array($field->field_type, self::TEXT_TYPES, true));
    }

    /**
     * FLEXIcontent keeps the text of every version of an item. The one of the version that is
     * shown is to be the text of the article: it is rewritten with it. Versions of before stay as
     * they were written.
     */
    public function afterRewrite(string $table, int $id, callable $rewrite): void
    {
        if ($table !== '#__content' || $id <= 0) {
            return;
        }

        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['v.field_id', 'v.version', 'v.valueorder', 'v.suborder', 'v.value']))
            ->from($db->quoteName('#__flexicontent_items_versions', 'v'))
            ->join('INNER', $db->quoteName('#__flexicontent_fields', 'f'), $db->quoteName('f.id') . ' = ' . $db->quoteName('v.field_id'))
            ->join('INNER', $db->quoteName('#__content', 'c'), $db->quoteName('c.id') . ' = ' . $db->quoteName('v.item_id'))
            ->where($db->quoteName('v.item_id') . ' = ' . $id)
            ->where($db->quoteName('v.version') . ' = ' . $db->quoteName('c.version'))
            ->where($db->quoteName('f.iscore') . ' = 1')
            ->where($db->quoteName('f.field_type') . ' = ' . $db->quote('maintext'));

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $value = (string) $row->value;
            $new   = $value === '' ? $value : $rewrite($value);

            if ($new === $value) {
                continue;
            }

            $update = $db->getQuery(true)
                ->update($db->quoteName('#__flexicontent_items_versions'))
                ->set($db->quoteName('value') . ' = ' . $db->quote($new))
                ->where($db->quoteName('item_id') . ' = ' . $id)
                ->where($db->quoteName('field_id') . ' = ' . (int) $row->field_id)
                ->where($db->quoteName('version') . ' = ' . (int) $row->version)
                ->where($db->quoteName('valueorder') . ' = ' . (int) $row->valueorder)
                ->where($db->quoteName('suborder') . ' = ' . (int) $row->suborder);

            $db->setQuery($update)->execute();
        }
    }

    /**
     * FLEXIcontent writes the values of the fields after it has stored the article.
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
            $query = $db->getQuery(true)
                ->select('DISTINCT ' . $db->quoteName('item_id'))
                ->from($db->quoteName('#__flexicontent_fields_item_relations'))
                ->where($db->quoteName('field_id') . ' IN (' . implode(',', array_keys($this->getFields())) . ')')
                ->where($db->quoteName('item_id') . ' > ' . $last)
                ->order($db->quoteName('item_id') . ' ASC')
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
     * The state of Joomla that a state of FLEXIcontent stands for.
     */
    public static function toState(int $state): int
    {
        switch ($state) {
            case -5:
                // In progress: published, and being worked on
                return 1;

            case -3:
            case -4:
                // Pending approval, draft
                return 0;

            default:
                return $state;
        }
    }

    /**
     * @param   int[]  $ids  Of the items.
     *
     * @return  \Gss\Component\Mediaaudit\Administrator\Service\Reference[]
     */
    private function extract(array $ids): array
    {
        $db    = $this->db;
        $list  = implode(',', array_map('intval', $ids));
        $query = $db->getQuery(true)
            ->select($db->quoteName(['c.id', 'c.state', 'e.lang_parent_id']))
            ->from($db->quoteName('#__content', 'c'))
            ->join('LEFT', $db->quoteName('#__flexicontent_items_ext', 'e'), $db->quoteName('e.item_id') . ' = ' . $db->quoteName('c.id'))
            ->where($db->quoteName('c.id') . ' IN (' . $list . ')');

        $items = $db->setQuery($query)->loadObjectList('id');

        $query = $db->getQuery(true)
            ->select($db->quoteName(['field_id', 'item_id', 'value']))
            ->from($db->quoteName('#__flexicontent_fields_item_relations'))
            ->where($db->quoteName('field_id') . ' IN (' . implode(',', array_keys($this->getFields())) . ')')
            ->where($db->quoteName('item_id') . ' IN (' . $list . ')')
            ->where($db->quoteName('value') . ' <> ' . $db->quote(''));

        $references = [];

        foreach ($db->setQuery($query)->loadObjectList() as $value) {
            $item = $items[(int) $value->item_id] ?? null;

            // Values of items that are gone
            if ($item === null) {
                continue;
            }

            $references[] = $this->read($this->getFields()[(int) $value->field_id], $item, (string) $value->value);
        }

        return ReferenceBuilder::unique(array_merge(...$references));
    }

    /**
     * @return  \Gss\Component\Mediaaudit\Administrator\Service\Reference[]
     */
    private function read(object $field, object $item, string $value): array
    {
        $id    = (int) $item->id;
        $state = self::toState((int) $item->state);
        $name  = 'fields.' . $field->name;

        // Most values are arrays, written by serialize()
        $values = preg_match('/^a:\d+:\{/', $value) ? @unserialize($value, ['allowed_classes' => false]) : null;

        if ($field->field_type === 'image') {
            $original = \is_array($values) ? (string) ($values['originalname'] ?? '') : $value;

            return $original === '' ? [] : $this->readImage($field, $item, $original, $name, $state);
        }

        if (\is_array($values)) {
            return $this->builder->fromJson([$field->name => $values], 'flexicontent', $id, 'fields', $state);
        }

        if (\in_array($field->field_type, self::HTML_TYPES, true) && (str_contains($value, '<') || stripos($value, 'url(') !== false)) {
            return $this->builder->fromHtml($value, 'flexicontent', $id, $name, $state);
        }

        return PathNormalizer::looksLikeImage($value) ? $this->builder->fromValue($value, 'flexicontent', $id, $name, $state) : [];
    }

    /**
     * @return  \Gss\Component\Mediaaudit\Administrator\Service\Reference[]
     */
    private function readImage(object $field, object $item, string $original, string $name, int $state): array
    {
        $id     = (int) $item->id;
        $params = $field->params;

        // Translations can share the files of what they translate
        $shared = (int) $field->untranslatable || (int) ($params['of_usage'] ?? 0);
        $owner  = $shared && (int) $item->lang_parent_id && (int) $item->lang_parent_id !== $id ? (int) $item->lang_parent_id : $id;
        $paths  = ImagePaths::of($params, (int) $field->id, $this->getComponent(), $owner, $original);

        $references = [];

        if ($paths['original'] !== null) {
            $references[] = $this->builder->fromValue($paths['original'], 'flexicontent', $id, $name, $state);
        }

        foreach ($paths['thumbnails'] as $thumbnail) {
            if ($this->isFile($thumbnail)) {
                $references[] = $this->builder->fromValue($thumbnail, 'flexicontent', $id, $name . '#thumbnail', $state);
            }
        }

        return array_merge(...$references);
    }

    private function isFile(string $path): bool
    {
        if ($this->exists !== null) {
            return (bool) ($this->exists)($path);
        }

        return $this->filesystem !== null && $this->filesystem->isFile(PathNormalizer::composeUnicode($path));
    }

    /**
     * @return  array<int, object>
     */
    private function getFields(): array
    {
        if ($this->fields === null) {
            $db    = $this->db;
            $types = array_merge(['image', 'weblink', 'extendedweblink'], self::HTML_TYPES);
            $query = $db->getQuery(true)
                ->select($db->quoteName(['id', 'name', 'field_type', 'untranslatable', 'attribs']))
                ->from($db->quoteName('#__flexicontent_fields'))
                ->where($db->quoteName('iscore') . ' = 0')
                ->where($db->quoteName('field_type') . ' IN (' . implode(',', array_map([$db, 'quote'], $types)) . ')');

            $this->fields = $db->setQuery($query)->loadObjectList('id');

            foreach ($this->fields as $field) {
                $field->params = json_decode((string) $field->attribs, true) ?: [];
                unset($field->attribs);
            }
        }

        return $this->fields;
    }

    /**
     * @return  array<string, mixed>
     */
    private function getComponent(): array
    {
        if ($this->component === null) {
            $db    = $this->db;
            $query = $db->getQuery(true)
                ->select($db->quoteName('params'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('com_flexicontent'));

            $this->component = json_decode((string) $db->setQuery($query)->loadResult(), true) ?: [];
        }

        return $this->component;
    }
}
