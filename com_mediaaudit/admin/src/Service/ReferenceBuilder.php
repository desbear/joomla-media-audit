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
 * What the extractors use to turn the columns of a record into references.
 */
final class ReferenceBuilder
{
    /** End of the field of a reference that only the Markdown of the content holds */
    public const MARKDOWN = '#markdown';

    public function __construct(
        private PathNormalizer $normalizer,
        private HtmlReferenceParser $htmlParser,
        private JsonReferenceWalker $jsonWalker
    ) {
    }

    /**
     * @return  Reference[]
     */
    public function fromHtml(?string $html, string $sourceType, int $sourceId, string $field, ?int $state): array
    {
        $references = [];

        foreach ($this->htmlParser->parse((string) $html) as $found) {
            $references[] = $this->build($found['raw'], $sourceType, $sourceId, self::fieldOf($field, $found['via']), $state);
        }

        // The Markdown that the HTML was made of: what it loads is what the next save loads
        foreach (MarkdownCarrier::sources((string) $html) as $markdown) {
            foreach (MarkdownCarrier::destinations($markdown) as $found) {
                if ($found['image'] || PathNormalizer::looksLikeImage($found['url'])) {
                    $references[] = $this->build($found['url'], $sourceType, $sourceId, $field . self::MARKDOWN, $state);
                }
            }

            MarkdownCarrier::outsideCode($markdown, function (string $text) use (&$references, $sourceType, $sourceId, $field, $state): string {
                foreach (str_contains($text, '<') ? $this->htmlParser->parse($text) : [] as $found) {
                    if ($found['via'] !== HtmlReferenceParser::VIA_TEXT) {
                        $references[] = $this->build($found['raw'], $sourceType, $sourceId, $field . self::MARKDOWN, $state);
                    }
                }

                return $text;
            });
        }

        return array_values(array_filter($references));
    }

    /**
     * @param   string|array|object|null  $json
     *
     * @return  Reference[]
     */
    public function fromJson($json, string $sourceType, int $sourceId, string $column, ?int $state): array
    {
        $references = [];

        foreach ($this->jsonWalker->walk($json, $column) as $found) {
            $references[] = $this->build($found['raw'], $sourceType, $sourceId, self::fieldOf($found['field'], $found['via']), $state);
        }

        return array_values(array_filter($references));
    }

    /**
     * @return  Reference[]
     */
    public function fromValue(?string $value, string $sourceType, int $sourceId, string $field, ?int $state): array
    {
        return array_values(array_filter([$this->build((string) $value, $sourceType, $sourceId, $field, $state)]));
    }

    /**
     * @param   string  $folder  Folder of the style sheet, relative to the root of the site.
     *
     * @return  Reference[]
     */
    public function fromCss(string $css, string $folder, string $sourceType, int $sourceId, string $field, ?int $state): array
    {
        $references = [];

        foreach ($this->htmlParser->parseCss($css) as $found) {
            $references[] = $this->build($found['raw'], $sourceType, $sourceId, $field, $state, $folder);
        }

        return array_values(array_filter($references));
    }

    /**
     * One reference for every file that a field of a record points to.
     *
     * @param   Reference[]  $references
     *
     * @return  Reference[]
     */
    public static function unique(array $references): array
    {
        $unique = [];
        $inHtml = [];

        foreach ($references as $reference) {
            if (!str_ends_with($reference->field, self::MARKDOWN)) {
                $inHtml[$reference->sourceType . '|' . $reference->sourceId . '|' . $reference->target->path] = true;
            }
        }

        foreach ($references as $reference) {
            // The Markdown says what the HTML says, as a rule, in whatever column: once is enough
            if (str_ends_with($reference->field, self::MARKDOWN) && isset($inHtml[$reference->sourceType . '|' . $reference->sourceId . '|' . $reference->target->path])) {
                continue;
            }

            $field = preg_replace('/#text$/', '', $reference->field);
            $key   = $reference->sourceType . '|' . $reference->sourceId . '|' . $field . '|' . $reference->target->path;

            // What an attribute gave wins over what the text gave
            if (!isset($unique[$key]) || str_ends_with($unique[$key]->field, '#text')) {
                $unique[$key] = $reference;
            }
        }

        return array_values($unique);
    }

    private function build(string $raw, string $sourceType, int $sourceId, string $field, ?int $state, string $folder = ''): ?Reference
    {
        $target = $this->normalizer->normalize($raw, $folder);

        return $target === null ? null : new Reference($target, $raw, $sourceType, $sourceId, $field, $state);
    }

    private static function fieldOf(string $field, string $via): string
    {
        return $via === HtmlReferenceParser::VIA_TEXT ? $field . '#text' : $field;
    }
}
