<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Helper;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

\defined('_JEXEC') or die;

/**
 * Pieces of HTML that the lists share.
 */
final class HtmlHelper
{
    public static function escape(?string $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }

    public static function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unit  = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $unit < \count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return ($unit === 0 ? (string) $bytes : number_format($value, 1)) . ' ' . $units[$unit];
    }

    /**
     * The address of a file of the site, with every segment of the path encoded.
     */
    public static function url(string $path): string
    {
        return Uri::root() . implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    public static function thumbnail(string $path): string
    {
        $url = self::escape(self::url($path));

        return '<a href="' . $url . '" target="_blank" rel="noopener">'
            . '<img src="' . $url . '" alt="" loading="lazy" decoding="async" class="mediaaudit-thumb"></a>';
    }

    /**
     * The path, with the folders dimmed so that the name stands out.
     */
    public static function path(string $path): string
    {
        $position = strrpos($path, '/');

        if ($position === false) {
            return '<span class="mediaaudit-path">' . self::escape($path) . '</span>';
        }

        return '<span class="mediaaudit-path"><span class="text-muted">' . self::escape(substr($path, 0, $position + 1)) . '</span>'
            . self::escape(substr($path, $position + 1)) . '</span>';
    }

    public static function state(?int $state): string
    {
        $states = [
            1  => ['bg-success', 'JPUBLISHED'],
            0  => ['bg-secondary', 'JUNPUBLISHED'],
            2  => ['bg-info', 'JARCHIVED'],
            -2 => ['bg-danger', 'JTRASHED'],
        ];

        if ($state === null || !isset($states[$state])) {
            return '';
        }

        return '<span class="badge ' . $states[$state][0] . '">' . Text::_($states[$state][1]) . '</span>';
    }

    /**
     * Who refers to the image, folded away until it is asked for.
     *
     * @param  list<array{type: string, id: int, field: string, state: ?int, title: string, link: ?string}>  $references
     */
    public static function references(array $references, int $total): string
    {
        if ($total === 0) {
            return '<span class="text-muted">0</span>';
        }

        $html = '<details class="mediaaudit-references"><summary>' . Text::plural('COM_MEDIAAUDIT_N_REFERENCES', $total) . '</summary><ul class="list-unstyled mb-0 mt-1">';

        foreach ($references as $reference) {
            $label = self::escape($reference['type'] . ' ' . $reference['id'])
                . ($reference['title'] === '' ? '' : ': ' . self::escape($reference['title']));

            $html .= '<li class="small">'
                . ($reference['link'] === null
                    ? $label
                    : '<a href="' . self::escape(Route::_($reference['link'], false)) . '" target="_blank" rel="noopener">' . $label . '</a>')
                . ' <code>' . self::escape($reference['field']) . '</code> ' . self::state($reference['state']) . '</li>';
        }

        if ($total > \count($references)) {
            $html .= '<li class="small text-muted">' . Text::sprintf('COM_MEDIAAUDIT_AND_MORE', $total - \count($references)) . '</li>';
        }

        return $html . '</ul></details>';
    }

    public static function styles(): string
    {
        return '.mediaaudit-thumb{max-width:72px;max-height:54px;border:1px solid var(--border-color,#dee2e6);border-radius:3px;background:repeating-conic-gradient(#eee 0 25%,#fff 0 50%) 0 0/12px 12px}'
            . '.mediaaudit-path{word-break:break-all}'
            . '.mediaaudit-references summary{cursor:pointer}'
            . '.mediaaudit-card .display-6{font-variant-numeric:tabular-nums}'
            . '.mediaaudit-group td{background:var(--bs-tertiary-bg,#f8f9fa)}';
    }
}
