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
 * Writes rows as CSV for a spreadsheet to open.
 *
 * The cells hold paths, titles and URLs that authors and other sites chose. A cell that starts
 * like a formula is one for a spreadsheet, whatever it is for us: it gets an apostrophe in front,
 * which makes it text. Spaces, tabs and line ends in front are passed over by some spreadsheets,
 * so they do not hide a formula.
 */
final class CsvWriter
{
    /**
     * @param  list<list<string|int|float|null>>  $rows
     */
    public static function write(array $rows): string
    {
        $stream = fopen('php://memory', 'w+');

        foreach ($rows as $row) {
            $row = array_map(
                static fn ($cell) => \is_string($cell) && preg_match('/^[\s\x00]*[=+\-@]|^[\t\r\n]/', $cell) ? "'" . $cell : $cell,
                $row
            );

            fputcsv($stream, $row, ',', '"', '');
        }

        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }
}
