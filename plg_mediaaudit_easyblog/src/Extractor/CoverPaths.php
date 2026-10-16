<?php

/**
 * @package     Media Audit
 * @subpackage  plg_mediaaudit_easyblog
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mediaaudit\Easyblog\Extractor;

\defined('_JEXEC') or die;

/**
 * Where EasyBlog keeps the cover of a post.
 *
 * The cover is written as a place and a path: "post:1847/cover.jpg" is in the folder of post 1847,
 * "user:42/cover.jpg" in the folder of user 42, "shared/office/desk.jpg" in the shared folder.
 */
final class CoverPaths
{
    /** Place => setting of EasyBlog, folder when the setting is empty */
    private const PLACES = [
        'post'   => ['main_articles_path', 'images/easyblog_articles'],
        'user'   => ['main_image_path', 'images/easyblog_images'],
        'shared' => ['main_shared_path', 'images/easyblog_shared'],
    ];

    /** What EasyBlog puts before the name of the sizes that it makes of an image */
    public const VARIATIONS = ['b2ap3_icon_', 'b2ap3_thumbnail_', 'b2ap3_small_', 'b2ap3_medium_', 'b2ap3_large_', 'b2ap3_amp_'];

    /**
     * @param   string                $cover   The "image" of the post.
     * @param   array<string, mixed>  $config  Settings of EasyBlog.
     *
     * @return  string|null  Relative to the root of the site, or an absolute URL. Null when there is
     *                       no cover, or none that can be understood.
     */
    public static function of(string $cover, array $config = []): ?string
    {
        $cover = trim(str_replace('\\', '/', $cover));

        if ($cover === '') {
            return null;
        }

        // Written by an old version: the address itself
        if (preg_match('#^(?:https?:)?//#i', $cover)) {
            return $cover;
        }

        // Written by an older version still: an object with the address in it
        if ($cover[0] === '{') {
            $object = json_decode($cover, true);
            $url    = \is_array($object) ? (string) ($object['url'] ?? '') : '';

            return $url === '' ? null : $url;
        }

        if (preg_match('#^(post|user):(.*)$#s', $cover, $match)) {
            $place = $match[1];
            $path  = $match[2];
        } elseif (str_starts_with($cover, 'shared/')) {
            $place = 'shared';
            $path  = substr($cover, 7);
        } else {
            // A path of the site
            return preg_match('#(^|/)\.\.(/|$)#', $cover) ? null : ltrim($cover, '/');
        }

        $path = trim($path, '/');

        if ($path === '' || preg_match('#(^|/)\.\.(/|$)#', $path)) {
            return null;
        }

        [$setting, $default] = self::PLACES[$place];

        $folder = trim(str_replace('\\', '/', (string) ($config[$setting] ?? '')), '/ ');

        return ($folder === '' ? $default : $folder) . '/' . $path;
    }

    /**
     * The sizes that EasyBlog makes of an image, whether they are there or not.
     *
     * @return  string[]
     */
    public static function variationsOf(string $path): array
    {
        if (preg_match('#^(?:https?:)?//#i', $path)) {
            return [];
        }

        $folder = \dirname($path) === '.' ? '' : \dirname($path) . '/';
        $file   = basename($path);

        return array_map(static fn (string $prefix): string => $folder . $prefix . $file, self::VARIATIONS);
    }
}
