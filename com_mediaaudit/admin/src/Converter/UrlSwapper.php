<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Converter;

\defined('_JEXEC') or die;

/**
 * Puts the name of another file of the same folder into a URL, and leaves the URL as it was for
 * the rest: relative or with a domain, with its query, with what Joomla adds for its media fields.
 */
final class UrlSwapper
{
    private const MEDIA_FIELD = '#joomlaImage://';

    /**
     * @param   string  $url   As the author wrote it, entities decoded.
     * @param   string  $from  Name of the file that the URL is of.
     * @param   string  $to    Name of the file that the URL is to be of.
     *
     * @return  string|null  Null when the URL does not end in the name: better unchanged than wrong.
     */
    public static function swap(string $url, string $from, string $to): ?string
    {
        $from = basename($from);
        $to   = basename($to);

        [$path, $fragment] = self::split($url, '#');
        [$path, $query]    = self::split($path, '?');

        $path = self::swapName($path, $from, $to);

        if ($path === null) {
            return null;
        }

        // "images/a.png#joomlaImage://local-images/a.png?width=800&height=600"
        if (str_starts_with($fragment, self::MEDIA_FIELD)) {
            [$inner, $size] = self::split(substr($fragment, \strlen(self::MEDIA_FIELD)), '?');

            $fragment = self::MEDIA_FIELD . (self::swapName($inner, $from, $to) ?? $inner) . $size;
        }

        return $path . $query . $fragment;
    }

    /**
     * @return  array{0: string, 1: string}  What is before the mark, and the rest with the mark.
     */
    private static function split(string $text, string $mark): array
    {
        $position = strpos($text, $mark);

        return $position === false ? [$text, ''] : [substr($text, 0, $position), substr($text, $position)];
    }

    private static function swapName(string $path, string $from, string $to): ?string
    {
        $position = strrpos($path, '/');
        $folder   = $position === false ? '' : substr($path, 0, $position + 1);
        $name     = $position === false ? $path : substr($path, $position + 1);

        if ($name === $from) {
            return $folder . $to;
        }

        // Written with escapes: the new name is written the same way
        if (rawurldecode($name) === $from) {
            return $folder . rawurlencode($to);
        }

        return null;
    }
}
