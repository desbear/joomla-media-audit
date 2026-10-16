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
 * Puts a file of the folder for temporary files at a place of the site where there is none.
 *
 * The name is taken first, by creating the file there and failing when anything is there already,
 * a link included. Only then is the content put in, by renaming over the file that was made, which
 * replaces it and follows no link: nothing that came to be at the name between looking and writing
 * is written over. Whoever can make links in images/ on the server itself could still race the look
 * and the creation; that needs access to the files of the site.
 */
final class FilePlacer
{
    /**
     * @return  bool  False when the name is taken, or the file could not be written. The part stays
     *                where it is then, for the caller to remove.
     */
    public static function place(string $part, string $target): bool
    {
        // PHP resolves a link before it opens, so "x" alone would create the file the link leads to
        if (is_link($target) || file_exists($target)) {
            return false;
        }

        $handle = @fopen($target, 'xb');

        if ($handle === false) {
            return false;
        }

        fclose($handle);

        // The name is ours: put the content in its place
        if (@rename($part, $target)) {
            @chmod($target, 0644);

            return true;
        }

        // Another file system: copied into the file that was made, which is no link
        $from = @fopen($part, 'rb');
        $to   = @fopen($target, 'r+b');

        $copied = $from !== false && $to !== false && stream_copy_to_stream($from, $to) === filesize($part);

        if ($from !== false) {
            fclose($from);
        }

        if ($to !== false) {
            fclose($to);
        }

        if (!$copied) {
            @unlink($target);

            return false;
        }

        @chmod($target, 0644);

        return true;
    }
}
