<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Localizer;

use Gss\Component\Mediaaudit\Administrator\Service\HtmlReferenceParser;
use Gss\Component\Mediaaudit\Administrator\Service\MarkdownCarrier;

\defined('_JEXEC') or die;

/**
 * Replaces the URLs of images in HTML and in JSON, and nothing else of them.
 *
 * Works on the source as it is, without parsing it into a document and writing it out again: what
 * the author wrote stays byte for byte, except for the URLs that are replaced.
 *
 * Content of the GSS Markdown editor holds the Markdown that the HTML was made of. Its URLs are
 * replaced as well: the editor shows the Markdown, and makes the HTML of it again when it saves.
 *
 * The lookup is function (string $url): ?string. It gets the URL as the browser reads it, with the
 * entities decoded, and returns the new URL, or null to leave the URL as it is.
 */
final class ContentRewriter
{
    private const ATTRIBUTES = 'src|href|poster|background|srcset|style|data-[a-z0-9_-]+';

    /**
     * @return  array{0: string, 1: int}  The HTML, and how many URLs were replaced.
     */
    public function rewrite(string $html, callable $lookup): array
    {
        if ($html === '') {
            return [$html, 0];
        }

        [$rewritten, $count] = $this->rewriteHtml($html, $lookup);

        if (!MarkdownCarrier::has($rewritten)) {
            return [$rewritten, $count];
        }

        $inMarkdown = 0;
        $rewritten  = MarkdownCarrier::change($rewritten, function (string $markdown) use ($lookup, &$inMarkdown): string {
            $markdown = MarkdownCarrier::replace($markdown, $lookup, $inMarkdown);

            // HTML in the Markdown
            return MarkdownCarrier::outsideCode($markdown, function (string $text) use ($lookup, &$inMarkdown): string {
                [$text, $replaced] = str_contains($text, '<') ? $this->rewriteHtml($text, $lookup) : [$text, 0];
                $inMarkdown       += $replaced;

                return $text;
            });
        });

        // The same URLs twice, as a rule: they count once. Markdown that was left behind counts
        return [$rewritten, max($count, $inMarkdown)];
    }

    /**
     * @return  array{0: string, 1: int}
     */
    private function rewriteHtml(string $html, callable $lookup): array
    {
        $count = 0;

        // Tag by tag; a quoted value can hold a ">"
        $rewritten = preg_replace_callback(
            '/<[a-z][a-z0-9]*\b(?:[^<>"\']++|"[^"]*+"|\'[^\']*+\')*+>/i',
            function (array $tag) use ($lookup, &$count): string {
                return $this->rewriteTag($tag[0], $lookup, $count);
            },
            $html
        );

        // Null when the text is more than the pattern can take: better unchanged than half changed
        if ($rewritten === null) {
            return [$html, 0];
        }

        // Style sheets in the content
        $rewritten = preg_replace_callback(
            '#(<style\b[^>]*>)(.*?)(</style>)#is',
            function (array $match) use ($lookup, &$count): string {
                return $match[1] . $this->replaceCssUrls($match[2], $lookup, $count) . $match[3];
            },
            $rewritten
        ) ?? $rewritten;

        return [$rewritten, $count];
    }

    /**
     * Rewrites the attributes of one tag that hold URLs.
     *
     * The attributes are read one after the other, the way a browser reads them, each from where the
     * one before ended. Looking for `src="…"` anywhere in the tag would find it inside the value of
     * another attribute as well, where an author can write whatever looks like one: rewriting that
     * would let the text of an "alt" close its own quotes and become attributes of its own.
     */
    private function rewriteTag(string $tag, callable $lookup, int &$count): string
    {
        if (!preg_match('/^<[a-z][a-z0-9]*/i', $tag, $name)) {
            return $tag;
        }

        $result = $name[0];
        $offset = \strlen($name[0]);
        $length = \strlen($tag);

        while ($offset < $length) {
            // Whitespace or a slash, a name, and a value in double quotes, in single quotes or in none
            $found = preg_match(
                '/\G([\s\/]*)([^\s"\'<>\/=]+)(?:(\s*=\s*)(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/s',
                $tag,
                $part,
                PREG_UNMATCHED_AS_NULL,
                $offset
            );

            if (!$found) {
                break;
            }

            $offset += \strlen($part[0]);

            [$whole, $before, $attribute, $equals] = [$part[0], $part[1], $part[2], $part[3] ?? null];

            $value = $part[4] ?? $part[5] ?? $part[6] ?? null;

            if ($equals === null || $value === null || !preg_match('/^(?:' . self::ATTRIBUTES . ')$/i', $attribute)) {
                $result .= $whole;

                continue;
            }

            $replaced = $this->replaceIn(strtolower($attribute), html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $lookup, $count);

            if ($replaced === null) {
                $result .= $whole;

                continue;
            }

            // The quote that was there, or one where there was none. Both kinds of quotes are encoded,
            // whatever the value is in: nothing of it can end the attribute
            $quote   = ($part[5] ?? null) !== null ? "'" : '"';
            $result .= $before . $attribute . $equals . $quote . self::encode($replaced) . $quote;
        }

        // What is left: the end of the tag, or what no browser would read as an attribute
        return $result . substr($tag, $offset);
    }

    /**
     * The attributes of a tag that have a value, read one after the other like rewriteTag() reads
     * them, with the entities decoded. Of a name that is there twice, the first, as for a browser.
     *
     * @return  array<string, string>  By name, in lower case
     */
    private static function attributes(string $tag): array
    {
        $attributes = [];

        if (!preg_match('/^<[a-z][a-z0-9]*/i', $tag, $name)) {
            return $attributes;
        }

        $offset = \strlen($name[0]);

        while (preg_match('/\G([\s\/]*)([^\s"\'<>\/=]+)(?:(\s*=\s*)(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/s', $tag, $part, PREG_UNMATCHED_AS_NULL, $offset)) {
            $offset += \strlen($part[0]);
            $value   = $part[4] ?? $part[5] ?? $part[6] ?? null;

            if (($part[3] ?? null) !== null && $value !== null) {
                $attributes[strtolower($part[2])] ??= html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        return $attributes;
    }

    private static function encode(string $value): string
    {
        return str_replace(['&', '<', '>', '"', "'"], ['&amp;', '&lt;', '&gt;', '&quot;', '&#039;'], $value);
    }

    /**
     * Replaces images by a note that says that the image is lost.
     *
     * @param   callable  $isLost  function (string $url): bool, for the URL as the browser reads it.
     * @param   string    $text    The note.
     *
     * @return  array{0: string, 1: int}  The HTML, and how many images were replaced.
     */
    public function removeImages(string $html, callable $isLost, string $text): array
    {
        $count = 0;

        if ($html === '' || stripos($html, '<img') === false) {
            return [$html, 0];
        }

        // Tag by tag, like rewrite(): an "<img" that is written inside the value of another tag is text
        $rewritten = preg_replace_callback(
            '/<[a-z][a-z0-9]*\b(?:[^<>"\']++|"[^"]*+"|\'[^\']*+\')*+>/i',
            static function (array $tag) use ($isLost, $text, &$count): string {
                if (!preg_match('/^<img\b/i', $tag[0])) {
                    return $tag[0];
                }

                $attributes = self::attributes($tag[0]);

                if (!isset($attributes['src']) || !$isLost(trim($attributes['src']))) {
                    return $tag[0];
                }

                $count++;

                // What the image showed, for the reader who wonders what is lost
                $title = trim($attributes['alt'] ?? '');

                return '<span class="mediaaudit-missing-image"'
                    . ($title === '' ? '' : ' title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"')
                    . '>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</span>';
            },
            $html
        );

        return $rewritten === null ? [$html, 0] : [$rewritten, $count];
    }

    /**
     * Rewrites the value of a field, whatever it holds: JSON, HTML, or an address and nothing else.
     *
     * @return  array{0: string, 1: int}  The value, and how many URLs were replaced.
     */
    public function rewriteValue(string $value, callable $lookup): array
    {
        $text = trim($value);

        if ($text === '') {
            return [$value, 0];
        }

        if ($text[0] === '{' || $text[0] === '[') {
            return $this->rewriteJson($value, $lookup);
        }

        if (str_contains($text, '<') || stripos($text, 'url(') !== false) {
            return $this->rewrite($value, $lookup);
        }

        $new = $lookup($text);

        return $new === null || $new === $text ? [$value, 0] : [$new, 1];
    }

    /**
     * @return  array{0: string, 1: int}  The JSON, and how many URLs were replaced. The JSON is the
     *                                    one that was given when nothing was replaced.
     */
    public function rewriteJson(string $json, callable $lookup): array
    {
        // Objects stay objects and lists stay lists: "{}" is not "[]" to who reads it
        $values = json_decode($json);

        if (!\is_array($values) && !\is_object($values)) {
            return [$json, 0];
        }

        $count  = 0;
        $values = $this->rewriteValues($values, $lookup, $count);

        if ($count === 0) {
            return [$json, 0];
        }

        // Written the way it was written, as far as that can be told
        $flags = JSON_PRESERVE_ZERO_FRACTION
            | (preg_match('/[^\x00-\x7f]/', $json) ? JSON_UNESCAPED_UNICODE : 0)
            | (str_contains($json, '\\/') ? 0 : JSON_UNESCAPED_SLASHES);

        $encoded = json_encode($values, $flags);

        return $encoded === false ? [$json, 0] : [$encoded, $count];
    }

    /**
     * @param   array|object  $values
     *
     * @return  array|object
     */
    private function rewriteValues($values, callable $lookup, int &$count, int $depth = 0)
    {
        foreach ($values as $key => $value) {
            if (\is_array($value) || \is_object($value)) {
                if ($depth < 12) {
                    $value = $this->rewriteValues($value, $lookup, $count, $depth + 1);
                }
            } elseif (\is_string($value) && $value !== '') {
                if (str_contains($value, '<')) {
                    [$value, $replaced] = $this->rewrite($value, $lookup);
                    $count             += $replaced;
                } else {
                    $new = $lookup(trim($value));

                    if ($new !== null && $new !== trim($value)) {
                        $value = $new;
                        $count++;
                    }
                }
            }

            if (\is_object($values)) {
                $values->{$key} = $value;
            } else {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    /**
     * @return  string|null  Null when nothing of the value was replaced.
     */
    private function replaceIn(string $attribute, string $value, callable $lookup, int &$count): ?string
    {
        if ($attribute === 'style') {
            $replaced = $this->replaceCssUrls($value, $lookup, $count);

            return $replaced === $value ? null : $replaced;
        }

        if ($attribute === 'srcset' || str_ends_with($attribute, '-srcset')) {
            $replaced = $this->replaceSrcset($value, $lookup, $count);

            return $replaced === $value ? null : $replaced;
        }

        $new = $lookup(trim($value));

        if ($new === null || $new === trim($value)) {
            return null;
        }

        $count++;

        return $new;
    }

    /**
     * Replaces the URLs of the candidates of a srcset, each where it stands: a URL that is part of
     * another one is not touched.
     */
    private function replaceSrcset(string $srcset, callable $lookup, int &$count): string
    {
        $result = '';
        $length = \strlen($srcset);
        $i      = 0;

        $swap = static function (string $url) use ($lookup, &$count): string {
            $new = $url === '' ? null : $lookup($url);

            if ($new === null || $new === $url) {
                return $url;
            }

            $count++;

            // A space would end the candidate, a comma at the end as well
            return rtrim(str_replace([' ', "\t", "\n", "\r", "\f"], ['%20', '%09', '%0A', '%0D', '%0C'], $new), ',');
        };

        while ($i < $length) {
            // Whitespace and commas between candidates
            $skip    = strspn($srcset, " \t\n\r\f,", $i);
            $result .= substr($srcset, $i, $skip);
            $i      += $skip;

            if ($i >= $length) {
                break;
            }

            $size  = strcspn($srcset, " \t\n\r\f", $i);
            $token = substr($srcset, $i, $size);
            $i    += $size;
            $url   = rtrim($token, ',');
            $tail  = substr($token, \strlen($url));

            if ($tail === '') {
                // The descriptor, up to the comma that closes the candidate
                $descriptor = strcspn($srcset, ',', $i);
                $tail       = substr($srcset, $i, $descriptor);
                $i         += $descriptor;
            }

            // "a.jpg,b.jpg" is one URL for a browser, and two for who wrote it: read as the parser reads it
            $parts = explode(',', $url);

            if (\count($parts) > 1 && \count(array_filter($parts, [\Gss\Component\Mediaaudit\Administrator\Service\PathNormalizer::class, 'looksLikeImage'])) === \count($parts)) {
                $url = implode(',', array_map($swap, $parts));
            } else {
                $url = $swap($url);
            }

            $result .= $url . $tail;
        }

        return $result;
    }

    /**
     * Replaces what url() of a style sheet leads to. Strings and comments are passed over: a
     * "url(" that is written inside a string is text, and making it something else would let the
     * string end where its author did not end it.
     */
    private function replaceCssUrls(string $css, callable $lookup, int &$count): string
    {
        if (stripos($css, 'url(') === false) {
            return $css;
        }

        return preg_replace_callback(
            '/"(?:[^"\\\\]|\\\\.)*+"|\'(?:[^\'\\\\]|\\\\.)*+\'|\/\*.*?\*\/|(url\(\s*)(?:"([^"]*)"|\'([^\']*)\'|([^)\s"\']*))(\s*\))/is',
            static function (array $match) use ($lookup, &$count): string {
                if (($match[1] ?? '') === '') {
                    return $match[0];
                }

                $quote = ($match[2] ?? '') !== '' ? '"' : ((($match[3] ?? '') !== '') ? "'" : '');
                $url   = $quote === '"' ? $match[2] : ($quote === "'" ? $match[3] : ($match[4] ?? ''));
                $new   = $url === '' ? null : $lookup(trim($url));

                if ($new === null || $new === trim($url)) {
                    return $match[0];
                }

                $count++;

                // Written the way it was, with nothing in it that could end it
                $safe = str_replace(
                    ['"', "'", '\\', ' ', '(', ')', "\n", "\r", "\t", '<', '>'],
                    ['%22', '%27', '%5C', '%20', '%28', '%29', '%0A', '%0D', '%09', '%3C', '%3E'],
                    $new
                );

                return $match[1] . $quote . $safe . $quote . $match[5];
            },
            $css
        ) ?? $css;
    }
}
