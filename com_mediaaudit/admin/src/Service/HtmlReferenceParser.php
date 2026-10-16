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
 * Finds the references to images in HTML and in style sheets.
 *
 * Returns the references as they are written. Every result is a pair of the reference and of where
 * it was found, like "img[src]". References found in the text only, outside any attribute the parser
 * knows, are marked "text": a false "in use" costs nothing, a false "orphan" can cost a file.
 */
final class HtmlReferenceParser
{
    public const VIA_TEXT = 'text';

    /** Attributes that hold one URL of an image, on any element */
    private const URL_ATTRIBUTES = ['data-src', 'data-lazy-src', 'data-original', 'data-bg', 'data-background', 'poster', 'background'];

    /** Attributes that hold a list of candidates, on any element */
    private const SRCSET_ATTRIBUTES = ['srcset', 'data-srcset', 'data-lazy-srcset'];

    /** Elements whose src is an image */
    private const SRC_ELEMENTS = ['img', 'source', 'input', 'image'];

    /**
     * @return  list<array{raw: string, via: string}>
     */
    public function parse(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $found = $this->parseWithDom($html);

        if ($found === null) {
            $found = $this->parseWithRegex($html);
        }

        // What the attributes gave, read the way the text is read: the same reference again, without
        // the query string that the attribute has. Looked up, not compared one with every other:
        // content is written by authors, and thousands of references must not take minutes
        $known = array_fill_keys($this->scanText(implode("\n", array_column($found, 'raw'))), true);

        foreach ($this->scanText($html) as $raw) {
            if (!isset($known[$raw])) {
                $found[] = ['raw' => $raw, 'via' => self::VIA_TEXT];
            }
        }

        return self::unique($found);
    }

    /**
     * @return  list<array{raw: string, via: string}>
     */
    public function parseCss(string $css): array
    {
        $found = [];

        foreach (self::cssUrls($css) as $raw) {
            $found[] = ['raw' => $raw, 'via' => 'css url()'];
        }

        return self::unique($found);
    }

    /**
     * The URLs of a srcset attribute, without the descriptors.
     *
     * @return  string[]
     */
    public static function srcsetUrls(string $srcset): array
    {
        $urls   = [];
        $length = \strlen($srcset);
        $i      = 0;

        while ($i < $length) {
            // Whitespace and commas between candidates
            $i += strspn($srcset, " \t\n\r\f,", $i);

            if ($i >= $length) {
                break;
            }

            $size = strcspn($srcset, " \t\n\r\f", $i);
            $url  = substr($srcset, $i, $size);
            $i   += $size;

            if (str_ends_with($url, ',')) {
                // No descriptor: the comma closes the candidate
                $url = rtrim($url, ',');
            } else {
                // Skip the descriptor, up to the comma that closes the candidate
                $i += strcspn($srcset, ',', $i);
            }

            // "a.jpg,b.jpg" is one URL for a browser, but never what the author meant
            $parts = explode(',', $url);

            if (\count($parts) > 1 && \count(array_filter($parts, [PathNormalizer::class, 'looksLikeImage'])) === \count($parts)) {
                array_push($urls, ...$parts);

                continue;
            }

            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * @return  list<array{raw: string, via: string}>|null  Null when the HTML cannot be parsed.
     */
    private function parseWithDom(string $html): ?array
    {
        if (!class_exists(\DOMDocument::class)) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $document = new \DOMDocument();
            $options  = LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT | LIBXML_PARSEHUGE;

            // The XML declaration is the way to tell libxml that the fragment is UTF-8
            if (!@$document->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', $options)) {
                return null;
            }

            $found = [];

            // One list, made once, in the order of the document. The list that getElementsByTagName()
            // gives is made anew for every step, which takes minutes for a page of a hundred thousand tags
            $nodes = (new \DOMXPath($document))->query('//style | //@*');

            if ($nodes === false) {
                return null;
            }

            foreach ($nodes as $node) {
                if ($node instanceof \DOMAttr) {
                    $tag = strtolower((string) $node->ownerElement?->nodeName);

                    if ($tag === 'style') {
                        continue;
                    }

                    foreach ($this->readAttribute($tag, strtolower($node->nodeName), $node->nodeValue ?? '') as $raw) {
                        $found[] = ['raw' => $raw, 'via' => $tag . '[' . strtolower($node->nodeName) . ']'];
                    }

                    continue;
                }

                foreach (self::cssUrls($node->textContent) as $raw) {
                    $found[] = ['raw' => $raw, 'via' => 'style url()'];
                }
            }

            return $found;
        } catch (\Throwable $e) {
            return null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * For HTML that libxml gives up on. Reads the attributes without looking at the structure.
     *
     * @return  list<array{raw: string, via: string}>
     */
    private function parseWithRegex(string $html): array
    {
        $found   = [];
        $pattern = '/<([a-z][a-z0-9]*)\b([^>]*)>/is';

        if (!preg_match_all($pattern, $html, $elements, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($elements as $element) {
            $tag = strtolower($element[1]);

            if (!preg_match_all('/([a-z][a-z0-9_:-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/is', $element[2], $attributes, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL)) {
                continue;
            }

            foreach ($attributes as $attribute) {
                $name  = strtolower($attribute[1]);
                $value = html_entity_decode($attribute[2] ?? $attribute[3] ?? $attribute[4] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');

                foreach ($this->readAttribute($tag, $name, $value) as $raw) {
                    $found[] = ['raw' => $raw, 'via' => $tag . '[' . $name . ']'];
                }
            }
        }

        if (preg_match_all('#<style\b[^>]*>(.*?)</style>#is', $html, $styles)) {
            foreach ($styles[1] as $style) {
                foreach (self::cssUrls($style) as $raw) {
                    $found[] = ['raw' => $raw, 'via' => 'style url()'];
                }
            }
        }

        return $found;
    }

    /**
     * @return  string[]  The references in one attribute.
     */
    private function readAttribute(string $tag, string $name, string $value): array
    {
        $value = trim($value);

        if ($value === '') {
            return [];
        }

        if ($name === 'style') {
            return self::cssUrls($value);
        }

        if (\in_array($name, self::SRCSET_ATTRIBUTES, true)) {
            return self::srcsetUrls($value);
        }

        if ($name === 'src') {
            return \in_array($tag, self::SRC_ELEMENTS, true) ? [$value] : [];
        }

        if (\in_array($name, self::URL_ATTRIBUTES, true)) {
            return [$value];
        }

        // Links to an image, and what lazy loaders and galleries keep in attributes of their own
        if ($name === 'href' || $name === 'xlink:href' || str_starts_with($name, 'data-')) {
            return PathNormalizer::looksLikeImage($value) ? [$value] : [];
        }

        return [];
    }

    /**
     * @return  string[]
     */
    private static function cssUrls(string $css): array
    {
        if (stripos($css, 'url(') === false) {
            return [];
        }

        preg_match_all('/url\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^)\s]*))\s*\)/i', $css, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

        $urls = [];

        foreach ($matches as $match) {
            $url = trim($match[1] ?? $match[2] ?? $match[3] ?? '');

            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * Paths below images/ anywhere in the source: in the tags of content plugins, in scripts, in text.
     *
     * @return  string[]
     */
    private function scanText(string $html): array
    {
        if (stripos($html, 'images/') === false) {
            return [];
        }

        $extensions = implode('|', PathNormalizer::IMAGE_EXTENSIONS);

        // Not after a character that makes it a part of a longer path or of a URL of another site.
        // No longer than a path is: a text without end is not read to its end from every "images/" in it
        preg_match_all('~(?<![\w/.%-])images/[^\s"\'<>(){}\[\]|\\\\?#,;]{1,400}?\.(?:' . $extensions . ')(?![\w])~iu', $html, $matches);

        return $matches[0] ?? [];
    }

    /**
     * Drops the repeats. What an attribute gave wins over what the text gave.
     *
     * @param   list<array{raw: string, via: string}>  $found
     *
     * @return  list<array{raw: string, via: string}>
     */
    private static function unique(array $found): array
    {
        $unique = [];

        foreach ($found as $reference) {
            $key = $reference['raw'];

            if (!isset($unique[$key]) || ($unique[$key]['via'] === self::VIA_TEXT && $reference['via'] !== self::VIA_TEXT)) {
                $unique[$key] = $reference;
            }
        }

        return array_values($unique);
    }
}
