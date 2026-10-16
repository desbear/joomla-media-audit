<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Service;

\defined('_JEXEC') or die;

/**
 * Finds the references to images in params and in other JSON, whatever the names of the keys are.
 */
final class JsonReferenceWalker
{
    private const MAX_DEPTH = 12;

    public function __construct(private HtmlReferenceParser $htmlParser)
    {
    }

    /**
     * @param   string|array|object|null  $json    JSON, or what it decodes to.
     * @param   string                    $prefix  Name of the column, the start of the name of the field.
     *
     * @return  list<array{raw: string, via: string, field: string}>  The field is the path of the
     *                                                                keys, like "params.menu_image".
     */
    public function walk($json, string $prefix): array
    {
        if (\is_string($json)) {
            $json = json_decode($json, true);
        } elseif (\is_object($json)) {
            $json = json_decode(json_encode($json), true);
        }

        if (!\is_array($json)) {
            return [];
        }

        $found = [];
        $this->visit($json, $prefix, 0, $found);

        return $found;
    }

    private function visit(array $values, string $field, int $depth, array &$found): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }

        foreach ($values as $key => $value) {
            $name = $field . '.' . $key;

            if (\is_array($value)) {
                $this->visit($value, $name, $depth + 1, $found);

                continue;
            }

            if (!\is_string($value) || trim($value) === '') {
                continue;
            }

            $value = trim($value);

            // JSON inside JSON, which is how subforms and some modules keep their values
            if (($value[0] === '{' || $value[0] === '[') && \is_array($nested = json_decode($value, true))) {
                $this->visit($nested, $name, $depth + 1, $found);

                continue;
            }

            if (str_contains($value, '<') || stripos($value, 'url(') !== false) {
                foreach ($this->htmlParser->parse($value) as $reference) {
                    $found[] = $reference + ['field' => $name];
                }

                continue;
            }

            if (PathNormalizer::looksLikeImage($value)) {
                $found[] = ['raw' => $value, 'via' => 'value', 'field' => $name];
            }
        }
    }
}
