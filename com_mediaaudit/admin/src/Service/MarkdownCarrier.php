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
 * The Markdown that the GSS Markdown editor keeps inside the content, next to the HTML it made of
 * it:
 *
 *     <div class="gssmd-source" hidden="hidden" data-gssmd="v1:{fingerprint}:{base64}"></div>
 *
 * and, of its first versions, <!--GSSMD:v1:base64:{base64}-->.
 *
 * The editor shows the Markdown, and makes the HTML anew of it with every save: an address that is
 * changed in the HTML and not in the Markdown is back at the next save. The fingerprint is one of
 * the text that a reader sees, which an address is no part of: it stays as it is.
 */
final class MarkdownCarrier
{
    private const CARRIERS = [
        '#(<div\b[^>]*\bdata-gssmd="v1:[0-9a-f]{16}:)([A-Za-z0-9+/=]*)("[^>]*>)#i',
        '#(<!--GSSMD:v1:base64:)([A-Za-z0-9+/=]*)(-->)#',
    ];

    /** The end of "[text](" or "![text](", and what it leads to: <in brackets>, or without spaces */
    private const DESTINATION = '/(\]\([ \t]*)(?:<([^<>\n]*)>|((?:\\\\.|[^\s()\\\\]|\((?:\\\\.|[^\s()\\\\])*\))+))/u';

    /** "[name]: address", at the start of a line */
    private const DEFINITION = '/^( {0,3}\[[^\]\n]+\]:[ \t]*)(?:<([^<>\n]*)>|(\S+))/mu';

    public static function has(string $content): bool
    {
        return str_contains($content, 'data-gssmd="v1:') || str_contains($content, '<!--GSSMD:v1:base64:');
    }

    /**
     * @return  string[]  The Markdown of every carrier of the content.
     */
    public static function sources(string $content): array
    {
        $sources = [];

        if (!self::has($content)) {
            return $sources;
        }

        foreach (self::CARRIERS as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                foreach ($matches[2] as $encoded) {
                    $markdown = self::decode($encoded);

                    if ($markdown !== null && $markdown !== '') {
                        $sources[] = $markdown;
                    }
                }
            }
        }

        return $sources;
    }

    /**
     * Gives the Markdown of every carrier to the function, and keeps what it returns.
     *
     * @param   callable  $change  function (string $markdown): string
     *
     * @return  string  The content. The one that was given when no Markdown changed.
     */
    public static function change(string $content, callable $change): string
    {
        if (!self::has($content)) {
            return $content;
        }

        foreach (self::CARRIERS as $pattern) {
            $changed = preg_replace_callback(
                $pattern,
                static function (array $match) use ($change): string {
                    $markdown = self::decode($match[2]);

                    if ($markdown === null) {
                        return $match[0];
                    }

                    $new = (string) $change($markdown);

                    return $new === $markdown ? $match[0] : $match[1] . base64_encode($new) . $match[3];
                },
                $content
            );

            $content = $changed ?? $content;
        }

        return $content;
    }

    /**
     * What the links and the images of the Markdown lead to, as they are written.
     *
     * @return  list<array{url: string, image: bool}>  An image is what is written as one: "![text](address)".
     */
    public static function destinations(string $markdown): array
    {
        $found = [];

        // One match at a time, and every address once: a text of nothing but "](" is no reason to
        // keep a list of all of them
        self::outsideCode($markdown, static function (string $text) use (&$found): string {
            preg_replace_callback(self::DESTINATION, static function (array $match) use (&$found, $text): string {
                $url = self::written($match);

                if ($url !== '') {
                    $image       = self::isImage($text, $match[0][1]);
                    $found[$url] = ['url' => $url, 'image' => $image || ($found[$url]['image'] ?? false)];
                }

                return '';
            }, $text, -1, $count, PREG_OFFSET_CAPTURE);

            preg_replace_callback(self::DEFINITION, static function (array $match) use (&$found): string {
                $url = self::written($match);

                if ($url !== '') {
                    $found[$url] ??= ['url' => $url, 'image' => false];
                }

                return '';
            }, $text, -1, $count, PREG_OFFSET_CAPTURE);

            return $text;
        });

        return array_values($found);
    }

    /**
     * Replaces what the links and the images of the Markdown lead to.
     *
     * @param   callable  $lookup  function (string $url): ?string. Null to leave the address.
     * @param   int       $count   Is raised by one for every address that was replaced.
     */
    public static function replace(string $markdown, callable $lookup, int &$count): string
    {
        $swap = static function (array $match) use ($lookup, &$count): string {
            $bracketed = ($match[2] ?? '') !== '';
            $written   = $bracketed ? $match[2] : ($match[3] ?? '');
            $url       = $bracketed ? $written : self::unescape($written);
            $new       = $url === '' ? null : $lookup($url);

            if ($new === null || $new === $url) {
                return $match[0];
            }

            $count++;

            // Brackets for what would end the address: a space, a bracket without its other half
            $plain = !preg_match('/\s/', $new) && preg_match('/^(?:[^()]|\((?:[^()])*\))*$/', $new);

            return $match[1] . ($bracketed || !$plain ? '<' . str_replace(['<', '>'], ['%3C', '%3E'], $new) . '>' : $new);
        };

        return self::outsideCode($markdown, static function (string $text) use ($swap): string {
            $text = preg_replace_callback(self::DESTINATION, $swap, $text) ?? $text;

            return preg_replace_callback(self::DEFINITION, $swap, $text) ?? $text;
        });
    }

    /**
     * Gives the Markdown to the function in the parts that are not code, and puts them together.
     * Code between fences and between backticks is shown, not followed: it stays as it is.
     *
     * Read line by line and run by run, each character once. Authors write the Markdown: a pattern
     * that looks for the end of a run of backticks from every one of them takes minutes for a text
     * that is made for it.
     *
     * @param  callable  $change  function (string $text): string
     */
    public static function outsideCode(string $markdown, callable $change): string
    {
        $result = '';
        $text   = '';
        $fence  = null;

        foreach (preg_split('/(?<=\n)/', $markdown) ?: [$markdown] as $line) {
            if ($fence !== null) {
                $result .= $line;

                // Closed by a fence of the same character that is as long, or longer
                if (preg_match('/^ {0,3}(`{3,}|~{3,})[ \t]*\r?\n?$/', $line, $close) && $close[1][0] === $fence[0] && \strlen($close[1]) >= \strlen($fence)) {
                    $fence = null;
                }

                continue;
            }

            if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $open)) {
                $result .= self::outsideSpans($text, $change) . $line;
                $text    = '';
                $fence   = $open[1];

                continue;
            }

            $text .= $line;
        }

        return $result . self::outsideSpans($text, $change);
    }

    /**
     * The same for code in a line: what is between two runs of backticks that are as long.
     */
    private static function outsideSpans(string $text, callable $change): string
    {
        if ($text === '') {
            return '';
        }

        if (!str_contains($text, '`')) {
            return (string) $change($text);
        }

        // Where the runs of backticks start and how long they are: two numbers for a run
        $starts   = [];
        $lengths  = [];
        $byLength = [];
        $end      = \strlen($text);

        for ($at = strpos($text, '`'); $at !== false && $at < $end; $at = strpos($text, '`', $at)) {
            $run = strspn($text, '`', $at);

            $byLength[$run][] = \count($starts);
            $starts[]         = $at;
            $lengths[]        = $run;
            $at              += $run;

            if ($at >= $end) {
                break;
            }
        }

        // For every length, how far its runs were looked at: no run is looked at twice
        $cursor   = array_fill_keys(array_keys($byLength), 0);
        $result   = '';
        $position = 0;

        for ($i = 0, $count = \count($starts); $i < $count; $i++) {
            $length = $lengths[$i];
            $same   = $byLength[$length];

            while ($cursor[$length] < \count($same) && $same[$cursor[$length]] <= $i) {
                $cursor[$length]++;
            }

            // No run that closes it: backticks, and no code
            if ($cursor[$length] >= \count($same)) {
                continue;
            }

            $close = $same[$cursor[$length]];
            $start = $starts[$i];
            $stop  = $starts[$close] + $length;

            $result  .= $change(substr($text, $position, $start - $position)) . substr($text, $start, $stop - $start);
            $position = $stop;
            $i        = $close;
        }

        return $result . $change(substr($text, $position));
    }

    /**
     * @param  array<int, array{0: string, 1: int}>  $match  With the offsets.
     */
    private static function written(array $match): string
    {
        return ($match[2][0] ?? '') !== '' ? $match[2][0] : self::unescape($match[3][0] ?? '');
    }

    /**
     * Whether the "](" at the offset ends the text of an image: its "[" comes after a "!".
     */
    private static function isImage(string $text, int $offset): bool
    {
        $depth = 0;

        // As far back as the text of an image goes, and no further: this is done for every "]("
        for ($i = $offset - 1; $i >= 0 && $offset - $i < 400; $i--) {
            if ($i > 0 && $text[$i - 1] === '\\') {
                continue;
            }

            if ($text[$i] === ']') {
                $depth++;
            } elseif ($text[$i] === '[') {
                if ($depth === 0) {
                    return $i > 0 && $text[$i - 1] === '!';
                }

                $depth--;
            }
        }

        return false;
    }

    /**
     * Without the backslashes that Markdown writes before punctuation.
     */
    private static function unescape(string $written): string
    {
        return preg_replace('/\\\\([!-\/:-@\[-`{-~])/', '$1', $written) ?? $written;
    }

    private static function decode(string $encoded): ?string
    {
        $decoded = base64_decode($encoded, true);

        return $decoded === false || !mb_check_encoding($decoded, 'UTF-8') ? null : $decoded;
    }
}
