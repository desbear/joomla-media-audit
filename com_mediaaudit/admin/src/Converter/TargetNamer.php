<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Converter;

use Gss\Component\Mediaaudit\Administrator\Scanner\FileScanner;

\defined('_JEXEC') or die;

/**
 * Which images can be converted, and what the converted file is called.
 *
 * The converted file is in the folder of the image, next to it: whatever gives a folder its
 * meaning, to a component or to a person, goes on to hold.
 */
final class TargetNamer
{
    public const FORMATS = ['avif', 'webp'];

    /** What is converted. Not GIF: it can move, and the formats of GD cannot */
    public const SOURCES = ['jpg', 'jpeg', 'png'];

    /**
     * Whether the path is one of an image that can be converted: a JPEG or PNG below images/,
     * outside the quarantine.
     */
    public static function isConvertible(string $path): bool
    {
        return FileScanner::covers($path)
            && !preg_match('#(^|/)\.\.?(/|$)#', $path)
            && \in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::SOURCES, true);
    }

    /**
     * The names that the converted file can have, the first choice first.
     *
     * "photo.png" becomes "photo.avif". When that is taken, by "photo.jpg" that was converted
     * before or by a file of the site, it becomes "photo-png.avif", and at last a name with a part
     * of the hash of the content, which is free unless this very image was converted before.
     *
     * @param   string  $source  Path of the image.
     * @param   string  $format  One of FORMATS.
     * @param   string  $sha1    Of the content of the image.
     *
     * @return  string[]
     */
    public static function candidates(string $source, string $format, string $sha1 = ''): array
    {
        $folder    = \dirname($source) === '.' ? '' : \dirname($source) . '/';
        $name      = pathinfo($source, PATHINFO_FILENAME);
        $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        $names     = [$folder . $name . '.' . $format, $folder . $name . '-' . $extension . '.' . $format];

        if ($sha1 !== '') {
            $names[] = $folder . $name . '-' . substr($sha1, 0, 8) . '.' . $format;
        }

        return $names;
    }
}
