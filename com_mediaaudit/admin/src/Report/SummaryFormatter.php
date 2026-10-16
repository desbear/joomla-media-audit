<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Report;

\defined('_JEXEC') or die;

/**
 * Writes the summary of a scan as text, with what changed since the scan before.
 */
final class SummaryFormatter
{
    /** The numbers of the summary that are reported, in this order */
    public const KEYS = ['files', 'in_use', 'semi_orphan', 'orphan', 'broken', 'duplicate_groups', 'external_references'];

    /**
     * @param   array<string, int>       $current
     * @param   array<string, int>|null  $previous  Null for the first scan.
     * @param   array<string, string>    $labels    Key of the summary => what to call it. Numbers
     *                                              without a label are left out.
     *
     * @return  string  One line for every number, like "Orphaned: 12 (+3)".
     */
    public static function format(array $current, ?array $previous, array $labels): string
    {
        // Only what has a name is reported
        $keys  = array_values(array_filter(self::KEYS, static fn (string $key): bool => isset($labels[$key])));
        $width = 0;

        foreach ($keys as $key) {
            $width = max($width, mb_strwidth($labels[$key], 'UTF-8'));
        }

        $lines = [];

        foreach ($keys as $key) {
            $label = $labels[$key];
            $value = (int) ($current[$key] ?? 0);
            $line  = $label . str_repeat(' ', $width - mb_strwidth($label, 'UTF-8')) . '  ' . number_format($value);

            if ($previous !== null && isset($previous[$key])) {
                $change = $value - (int) $previous[$key];

                if ($change !== 0) {
                    $line .= ' (' . ($change > 0 ? '+' : '-') . number_format(abs($change)) . ')';
                }
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * @param   array<string, int>       $current
     * @param   array<string, int>|null  $previous
     */
    public static function hasChanged(array $current, ?array $previous): bool
    {
        if ($previous === null) {
            return true;
        }

        foreach (self::KEYS as $key) {
            if ((int) ($current[$key] ?? 0) !== (int) ($previous[$key] ?? 0)) {
                return true;
            }
        }

        return false;
    }
}
