<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Localizer;

\defined('_JEXEC') or die;

/**
 * Names the local copy of an external image.
 *
 * The names are ASCII only. Chinese names are correct, but they are written in two ways by the file
 * systems, and a name that only differs in that is what this whole component is there to find.
 */
final class FileNamer
{
    private const NAME_PARAMETERS = ['filename', 'file', 'name', 'f'];
    private const MAX_LENGTH      = 60;

    /**
     * @param   string  $url        The URL of the image.
     * @param   string  $extension  Of the type that the content of the image has.
     *
     * @return  string  Like "cdn.example.org/3f2a9c81d0-2022-03-23-11_44_10.png".
     */
    public static function pathOf(string $url, string $extension): string
    {
        return self::folderOf($url) . '/' . substr(sha1($url), 0, 10) . '-' . self::nameOf($url) . '.' . strtolower($extension);
    }

    public static function folderOf(string $url): string
    {
        $host = preg_replace('/[^a-z0-9.-]+/', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
        $host = trim((string) $host, '.-');

        return $host === '' ? 'unknown' : $host;
    }

    /**
     * What the image is called, as far as the URL tells: without folder, extension and hash.
     */
    public static function nameOf(string $url): string
    {
        $name = '';

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        foreach ($query as $key => $value) {
            if (\is_string($value) && \in_array(strtolower((string) $key), self::NAME_PARAMETERS, true) && trim($value) !== '') {
                $name = $value;

                break;
            }
        }

        if ($name === '') {
            $name = rawurldecode((string) parse_url($url, PHP_URL_PATH));
        }

        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $name);
        $name = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $name);
        $name = trim(preg_replace('/-{2,}/', '-', (string) $name), '-_');
        $name = trim(substr($name, 0, self::MAX_LENGTH), '-_');

        return $name === '' ? 'image' : $name;
    }
}
