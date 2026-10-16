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
 * For extractors whose sources hold their references in values of fields: rows that belong to an
 * item and have no id of their own, like the custom fields of Joomla and the fields of
 * FLEXIcontent.
 *
 * Not every field can be rewritten. A field that names a file for a component to make other files
 * of, or to pick from a list of files, is left as it is: the component would not find its files.
 */
interface RewritableValuesInterface
{
    /**
     * The values of a source that can be rewritten.
     *
     * @return  list<array{key: string, value: string}>  The key tells the values of a source apart
     *          and is what the reference calls its field, like "fields.photo".
     */
    public function getRewritableValues(int $sourceId): array;

    /**
     * Writes a value in the place of the one that is there.
     *
     * @param   string  $from  The value as it was read. Nothing is written when it is another by now.
     *
     * @return  bool  Whether a value was written.
     */
    public function setRewritableValue(int $sourceId, string $key, string $from, string $to): bool;

    /**
     * The fields whose values are never rewritten, as the references call them.
     *
     * @return  string[]
     */
    public function getFixedFields(): array;
}
