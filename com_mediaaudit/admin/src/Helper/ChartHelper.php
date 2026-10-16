<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Helper;

\defined('_JEXEC') or die;

/**
 * The charts of the component, as HTML and inline SVG. No library, nothing loaded from elsewhere.
 *
 * Every chart comes with its numbers as text: in the legend, at the end of a bar, and in a table
 * that can be opened below it. The colours tell the series apart for who sees them; nobody has to.
 *
 * A mark with a link leads to the list of what it counts.
 *
 * The three colours of the series are the first three of a palette that was checked for the common
 * kinds of colour blindness, in the order that the check asks for.
 */
final class ChartHelper
{
    /**
     * A number that the page leads with: what it counts, the change since the scan before, and the
     * course of the last scans.
     *
     * @param  string      $label    What is counted.
     * @param  int         $value
     * @param  string      $detail   Something to go with the number, like a size.
     * @param  int|null    $change   Since the scan before. Null when there is none.
     * @param  string      $since    What the change is compared with, for who cannot see the arrow.
     * @param  list<array{label: string, value: int}>  $history  The last scans, the oldest first.
     * @param  string      $link     Where the list behind the number is.
     */
    public static function tile(string $label, int $value, string $detail, ?int $change, string $since, array $history, string $link): string
    {
        $html = '<a class="viz-tile" href="' . self::e($link) . '">'
            . '<span class="viz-tile-label">' . self::e($label) . '</span>'
            . '<span class="viz-tile-value">' . number_format($value) . '</span>'
            . '<span class="viz-tile-meta">';

        if ($change !== null && $change !== 0) {
            // More of what is wrong is worse: every number of the tiles is one to bring down
            $html .= '<span class="viz-delta ' . ($change > 0 ? 'viz-delta-worse' : 'viz-delta-better') . '" title="' . self::e($since) . '">'
                . '<span aria-hidden="true">' . ($change > 0 ? '▲' : '▼') . '</span> '
                . ($change > 0 ? '+' : '−') . number_format(abs($change))
                . '<span class="visually-hidden"> ' . self::e($since) . '</span></span> ';
        }

        $html .= self::e($detail) . '</span>' . ($history ? self::sparkline($history) : '') . '</a>';

        return $html;
    }

    /**
     * One bar, cut into the parts of a whole.
     *
     * @param  string  $label     What the bar measures, like "By number".
     * @param  list<array{label: string, value: int|float, display: string, link?: string}>  $segments  In the order of the series.
     */
    public static function stackedBar(string $label, array $segments): string
    {
        $total = array_sum(array_column($segments, 'value'));
        $html  = '<div class="viz-row"><div class="viz-row-label">' . self::e($label) . '</div><div class="viz-stack" ' . self::role($segments) . ' aria-label="'
            . self::e($label . ': ' . implode(', ', array_map(static fn (array $s): string => $s['label'] . ' ' . $s['display'], $segments))) . '">';

        foreach ($segments as $i => $segment) {
            if ($segment['value'] <= 0 || $total <= 0) {
                continue;
            }

            $share = $segment['value'] / $total * 100;

            $html .= self::mark(
                'viz-segment viz-series-' . ($i + 1),
                'flex-grow:' . round($share, 3),
                $segment['label'],
                $segment['display'] . ' · ' . self::share($share),
                $segment['link'] ?? ''
            );
        }

        if ($total <= 0) {
            $html .= '<span class="viz-segment viz-empty" style="flex-grow:1"></span>';
        }

        return $html . '</div></div>';
    }

    /**
     * The key to the colours, with the numbers: nobody has to match a colour to read the chart.
     *
     * @param  list<array{label: string, display: string, link?: string}>  $series
     */
    public static function legend(array $series): string
    {
        $html = '<ul class="viz-legend">';

        foreach ($series as $i => $entry) {
            $html .= '<li><span class="viz-swatch viz-series-' . ($i + 1) . '" aria-hidden="true"></span>'
                . '<span class="viz-legend-label">' . self::linked($entry['label'], $entry['link'] ?? '') . '</span>'
                . ($entry['display'] === '' ? '' : ' <span class="viz-legend-value">' . self::e($entry['display']) . '</span>') . '</li>';
        }

        return $html . '</ul>';
    }

    /**
     * Bars of one series, the longest first. The names have no order of their own, so every bar has
     * the same colour: the length says how much.
     *
     * @param  list<array{label: string, value: int, display: string, detail?: string, link?: string}>  $rows
     */
    public static function bars(array $rows): string
    {
        $max  = max(1, ...array_map(static fn (array $row): int => (int) $row['value'], $rows ?: [['value' => 1]]));
        $html = '<div class="viz-bars">';

        foreach ($rows as $row) {
            $html .= '<div class="viz-row"><div class="viz-row-label" title="' . self::e($row['label']) . '">' . self::linked($row['label'], $row['link'] ?? '') . '</div>'
                . '<div class="viz-track">'
                . self::mark(
                    'viz-bar viz-series-1',
                    'width:calc((100% - var(--viz-value-room)) * ' . round($row['value'] / $max, 4) . ')',
                    $row['label'],
                    $row['display'] . (empty($row['detail']) ? '' : ' · ' . $row['detail']),
                    $row['link'] ?? ''
                )
                . '<span class="viz-bar-value">' . self::e($row['display']) . '</span></div></div>';
        }

        return $html . '</div>';
    }

    /**
     * Bars that are cut into the same parts, on one scale: the longest is the whole width.
     *
     * @param  list<array{label: string, link?: string, segments: list<array{label: string, value: int, link?: string}>}>  $rows
     */
    public static function stackedBars(array $rows): string
    {
        $max = 1;

        foreach ($rows as $row) {
            $max = max($max, array_sum(array_column($row['segments'], 'value')));
        }

        $html = '<div class="viz-bars">';

        foreach ($rows as $row) {
            $total = array_sum(array_column($row['segments'], 'value'));
            $html .= '<div class="viz-row"><div class="viz-row-label" title="' . self::e($row['label']) . '">' . self::linked($row['label'], $row['link'] ?? '') . '</div>'
                . '<div class="viz-track"><span class="viz-stack viz-stack-inline" style="width:calc((100% - var(--viz-value-room)) * ' . round($total / $max, 4) . ')">';

            foreach ($row['segments'] as $i => $segment) {
                if ($segment['value'] <= 0) {
                    continue;
                }

                $html .= self::mark(
                    'viz-segment viz-series-' . ($i + 1),
                    'flex-grow:' . (int) $segment['value'],
                    $row['label'] . ' · ' . $segment['label'],
                    number_format($segment['value']),
                    $segment['link'] ?? ''
                );
            }

            $html .= '</span><span class="viz-bar-value">' . number_format($total) . '</span></div></div>';
        }

        return $html . '</div>';
    }

    /**
     * Columns that are cut into the same parts, side by side on one scale: a whole, time after time.
     * The first series stands on the line.
     *
     * @param  string  $label  What the chart shows, for who cannot see it.
     * @param  list<array{label: string, segments: list<array{label: string, value: int, link?: string}>}>  $columns  The oldest first.
     */
    public static function columns(string $label, array $columns): string
    {
        $max = 1;

        foreach ($columns as $column) {
            $max = max($max, array_sum(array_column($column['segments'], 'value')));
        }

        [$top, $step] = self::scale($max);

        $html = '<div class="viz-columns"><div class="viz-y" style="width:' . \strlen(number_format($top)) . 'ch" aria-hidden="true">';

        for ($tick = 0; $tick <= $top; $tick += $step) {
            $html .= '<span style="bottom:' . round($tick / $top * 100, 3) . '%">' . number_format($tick) . '</span>';
        }

        $html .= '</div><div class="viz-plot" ' . self::role(array_merge(...array_column($columns, 'segments'))) . ' aria-label="' . self::e($label) . '">';

        for ($tick = $step; $tick <= $top; $tick += $step) {
            $html .= '<span class="viz-gridline" style="bottom:' . round($tick / $top * 100, 3) . '%"></span>';
        }

        foreach ($columns as $column) {
            $total = array_sum(array_column($column['segments'], 'value'));
            $html .= '<span class="viz-column"><span class="viz-column-stack" style="height:' . round($total / $top * 100, 3) . '%">';

            foreach ($column['segments'] as $i => $segment) {
                if ($segment['value'] <= 0) {
                    continue;
                }

                $html .= self::mark(
                    'viz-segment viz-series-' . ($i + 1),
                    'flex-grow:' . (int) $segment['value'],
                    $column['label'] . ' · ' . $segment['label'],
                    number_format($segment['value']) . ' · ' . self::share($segment['value'] / $total * 100),
                    $segment['link'] ?? ''
                );
            }

            $html .= '</span></span>';
        }

        $html .= '</div><div class="viz-x" aria-hidden="true">';

        foreach ($columns as $column) {
            $html .= '<span>' . self::e($column['label']) . '</span>';
        }

        return $html . '</div></div>';
    }

    /**
     * The numbers of a chart as a table, folded away below it.
     *
     * @param  string          $summary  What to call the way to the table.
     * @param  string[]        $headers
     * @param  list<string[]>  $rows     The first cell of a row is its name.
     */
    public static function table(string $summary, array $headers, array $rows): string
    {
        $html = '<details class="viz-table"><summary>' . self::e($summary) . '</summary><table class="table table-sm"><thead><tr>';

        foreach ($headers as $i => $header) {
            $html .= '<th scope="col"' . ($i ? ' class="text-end"' : '') . '>' . self::e($header) . '</th>';
        }

        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>';

            foreach (array_values($row) as $i => $cell) {
                $html .= $i ? '<td class="text-end">' . self::e($cell) . '</td>' : '<th scope="row" class="fw-normal">' . self::e($cell) . '</th>';
            }

            $html .= '</tr>';
        }

        return $html . '</tbody></table></details>';
    }

    /**
     * The rules of the looks. Colours by their part in the chart, for the light and the dark page.
     */
    public static function styles(): string
    {
        $dark = '--viz-surface:#1a1a19;--viz-ink:#fff;--viz-ink-2:#c3c2b7;--viz-muted:#898781;--viz-grid:#2c2c2a;--viz-track:#2c2c2a;'
            . '--viz-border:rgba(255,255,255,.10);--viz-series-1:#3987e5;--viz-series-2:#d95926;--viz-series-3:#199e70;'
            . '--viz-better:#0ca30c;--viz-worse:#e66767;color-scheme:dark';

        return '.viz-root{--viz-surface:#fcfcfb;--viz-ink:#0b0b0b;--viz-ink-2:#52514e;--viz-muted:#898781;--viz-grid:#e1e0d9;--viz-track:#f0efec;'
            . '--viz-border:rgba(11,11,11,.10);--viz-series-1:#2a78d6;--viz-series-2:#eb6834;--viz-series-3:#1baf7a;'
            . '--viz-better:#006300;--viz-worse:#d03b3b;--viz-value-room:72px;color:var(--viz-ink);font-family:system-ui,-apple-system,"Segoe UI",sans-serif;position:relative}'
            . '@media (prefers-color-scheme:dark){:root:where(:not([data-color-scheme="light"]):not([data-bs-theme="light"])) .viz-root{' . $dark . '}}'
            . ':root[data-color-scheme="dark"] .viz-root,:root[data-bs-theme="dark"] .viz-root{' . $dark . '}'
            . '.viz-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:16px;margin-bottom:16px}'
            . '.viz-tile,.viz-figure{background:var(--viz-surface);border:1px solid var(--viz-border);border-radius:8px;padding:16px 20px;margin:0}'
            . '.viz-tile{display:flex;flex-direction:column;gap:2px;color:var(--viz-ink);text-decoration:none}'
            . '.viz-tile:hover,.viz-tile:focus-visible{color:var(--viz-ink);border-color:var(--viz-muted);text-decoration:none}'
            . '.viz-tile-label{color:var(--viz-ink-2);font-size:.875rem}'
            . '.viz-tile-value{font-size:2rem;font-weight:600;line-height:1.15}'
            . '.viz-tile-meta{color:var(--viz-ink-2);font-size:.8125rem;min-height:1.25em}'
            . '.viz-delta{font-weight:600}.viz-delta-worse{color:var(--viz-worse)}.viz-delta-better{color:var(--viz-better)}'
            . '.viz-spark{display:block;width:120px;height:32px;margin-top:8px;overflow:visible}'
            . '.viz-spark polyline{fill:none;stroke:var(--viz-muted);stroke-width:2;stroke-linejoin:round;stroke-linecap:round}'
            . '.viz-spark circle{fill:var(--viz-series-1);stroke:var(--viz-surface);stroke-width:2}'
            . '.viz-spark rect{fill:transparent}'
            . '.viz-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;margin-top:16px}'
            . '.viz-figure h2{font-size:1rem;font-weight:600;margin:0 0 2px;color:var(--viz-ink)}'
            . '.viz-figure .viz-caption{color:var(--viz-ink-2);font-size:.8125rem;margin:0 0 14px}'
            . '.viz-row{display:grid;grid-template-columns:minmax(84px,24%) minmax(0,1fr);align-items:center;gap:12px;margin:8px 0}'
            . '.viz-row-label{color:var(--viz-ink-2);font-size:.8125rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
            . '.viz-stack{display:flex;gap:2px;height:20px;min-width:0}'
            . '.viz-stack-inline{display:inline-flex;vertical-align:middle;min-width:3px;height:16px;flex:none}'
            . '.viz-segment{min-width:3px;height:100%}'
            . '.viz-segment:first-child{border-radius:0}.viz-segment:last-child{border-radius:0 4px 4px 0}'
            . '.viz-empty{background:var(--viz-track);border-radius:0 4px 4px 0}'
            . '.viz-track{display:flex;align-items:center;gap:8px;min-width:0}'
            . '.viz-bar{display:inline-block;height:16px;min-width:3px;border-radius:0 4px 4px 0;flex:none}'
            . '.viz-bar-value{color:var(--viz-ink);font-size:.8125rem;font-variant-numeric:tabular-nums;white-space:nowrap}'
            . '.viz-split{display:grid;grid-template-columns:minmax(200px,260px) minmax(0,1fr);gap:16px;margin-bottom:24px}'
            . '.viz-split .viz-tiles{grid-template-columns:1fr;margin:0;align-content:start}'
            . '@media (max-width:767px){.viz-split{grid-template-columns:1fr}.viz-split .viz-tiles{grid-template-columns:repeat(auto-fit,minmax(150px,1fr))}}'
            . '.viz-columns{display:grid;grid-template-columns:auto minmax(0,1fr);gap:6px 8px;margin-top:8px}'
            . '.viz-y{position:relative;height:160px;color:var(--viz-muted);font-size:.75rem;font-variant-numeric:tabular-nums}'
            . '.viz-y span{position:absolute;right:0;transform:translateY(50%);white-space:nowrap;line-height:1}'
            . '.viz-plot{position:relative;height:160px;display:flex;align-items:flex-end;justify-content:space-around;gap:8px;border-bottom:1px solid var(--viz-grid)}'
            . '.viz-gridline{position:absolute;left:0;right:0;border-top:1px solid var(--viz-grid);pointer-events:none}'
            . '.viz-column{position:relative;flex:1 1 0;max-width:56px;height:100%;display:flex;align-items:flex-end}'
            . '.viz-column-stack{display:flex;flex-direction:column-reverse;gap:2px;width:100%;min-height:3px}'
            . '.viz-column-stack .viz-segment{min-height:3px;height:auto;flex-basis:0;border-radius:0}'
            . '.viz-column-stack .viz-segment:last-child{border-radius:4px 4px 0 0}'
            . '.viz-x{grid-column:2;display:flex;justify-content:space-around;gap:8px;color:var(--viz-ink-2);font-size:.75rem}'
            . '.viz-x span{flex:1 1 0;min-width:0;text-align:center;overflow-wrap:anywhere;font-variant-numeric:tabular-nums}'
            . '.viz-row-label a,.viz-legend-label a{color:inherit;text-decoration:underline;text-decoration-color:var(--viz-muted);text-underline-offset:2px}'
            . '.viz-row-label a:hover,.viz-legend-label a:hover{color:var(--viz-ink)}'
            . 'a.viz-segment,a.viz-bar{cursor:pointer}'
            . '.viz-series-1{background:var(--viz-series-1)}.viz-series-2{background:var(--viz-series-2)}.viz-series-3{background:var(--viz-series-3)}'
            . '.viz-segment:hover,.viz-segment:focus-visible,.viz-bar:hover,.viz-bar:focus-visible{filter:brightness(1.12);outline:2px solid var(--viz-ink);outline-offset:1px}'
            . '.viz-legend{display:flex;flex-wrap:wrap;gap:4px 20px;list-style:none;padding:0;margin:12px 0 0;font-size:.8125rem;color:var(--viz-ink-2)}'
            . '.viz-swatch{display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:6px}'
            . '.viz-legend-value{color:var(--viz-ink);font-weight:600;font-variant-numeric:tabular-nums}'
            . '.viz-figure>:last-child{margin-bottom:0}.viz-table{margin-top:12px;font-size:.8125rem}.viz-table summary{color:var(--viz-ink-2);cursor:pointer}'
            . '.viz-table table{margin:8px 0 0;color:var(--viz-ink);--bs-table-bg:transparent;--bs-table-color:var(--viz-ink)}'
            . '.viz-none{color:var(--viz-ink-2);font-size:.875rem;margin:0}'
            . '.viz-tip{position:absolute;z-index:5;pointer-events:none;background:var(--viz-ink);color:var(--viz-surface);border-radius:6px;padding:6px 10px;'
            . 'font-size:.8125rem;line-height:1.3;max-width:280px;box-shadow:0 2px 8px rgba(0,0,0,.2)}'
            . '.viz-tip[hidden]{display:none}.viz-tip strong{display:block;font-size:.9375rem}.viz-tip span{opacity:.8}'
            . '@media (forced-colors:active){.viz-segment,.viz-bar,.viz-swatch{forced-color-adjust:none}}';
    }

    /**
     * What shows the name and the number of the mark under the pointer, or of the mark that has the
     * focus. The numbers are on the page without it.
     */
    public static function script(): string
    {
        return <<<'JS'
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.viz-root').forEach(function (root) {
        var tip = document.createElement('div');
        var value = document.createElement('strong');
        var label = document.createElement('span');

        tip.className = 'viz-tip';
        tip.hidden = true;
        tip.setAttribute('role', 'status');
        tip.appendChild(value);
        tip.appendChild(label);
        root.appendChild(tip);

        function show(mark, x, y) {
            var box = root.getBoundingClientRect();

            // Names come from the content of the site: as text, never as markup
            value.textContent = mark.getAttribute('data-viz-value');
            label.textContent = mark.getAttribute('data-viz-tip');
            tip.hidden = false;

            var left = Math.min(Math.max(8, x - box.left + 12), box.width - tip.offsetWidth - 8);
            var top = y - box.top - tip.offsetHeight - 12;

            tip.style.left = left + 'px';
            tip.style.top = (top < 0 ? y - box.top + 18 : top) + 'px';
        }

        function hide() {
            tip.hidden = true;
        }

        root.addEventListener('pointermove', function (event) {
            var mark = event.target.closest('[data-viz-tip]');

            return mark ? show(mark, event.clientX, event.clientY) : hide();
        });
        root.addEventListener('pointerleave', hide);
        root.addEventListener('focusin', function (event) {
            var mark = event.target.closest('[data-viz-tip]');

            if (mark) {
                var box = mark.getBoundingClientRect();

                show(mark, box.left + box.width / 2, box.top);
            }
        });
        root.addEventListener('focusout', hide);
    });
});
JS;
    }

    /**
     * A mark of a chart: what the pointer and the keyboard find. With a link it leads to the list.
     */
    private static function mark(string $class, string $style, string $label, string $value, string $link): string
    {
        $attributes = ' class="' . $class . '" style="' . $style . '" data-viz-tip="' . self::e($label) . '" data-viz-value="' . self::e($value) . '"';

        if ($link === '') {
            return '<span' . $attributes . ' tabindex="0"></span>';
        }

        return '<a' . $attributes . ' href="' . self::e($link) . '" aria-label="' . self::e($label . ': ' . $value) . '"></a>';
    }

    private static function linked(string $label, string $link): string
    {
        return $link === '' ? self::e($label) : '<a href="' . self::e($link) . '">' . self::e($label) . '</a>';
    }

    /**
     * One picture to who cannot see it, unless its marks lead somewhere: then they have to be found.
     *
     * @param  list<array<string, mixed>>  $segments
     */
    private static function role(array $segments): string
    {
        return 'role="' . (array_filter(array_column($segments, 'link')) ? 'group' : 'img') . '"';
    }

    /**
     * The top of the scale and the distance of its lines: round numbers, four lines at most.
     *
     * @return  array{0: int, 1: int}
     */
    private static function scale(int $max): array
    {
        $power = (int) floor(log10(max(1, $max)));
        $step  = 1;

        foreach ([$power - 1, $power] as $exponent) {
            foreach ([1, 2, 5] as $factor) {
                $step = max(1, $factor * 10 ** $exponent);

                if ($max / $step <= 4) {
                    break 2;
                }
            }
        }

        return [(int) (ceil($max / $step) * $step), (int) $step];
    }

    /**
     * The course of a number over the last scans. The line is quiet; the dot is the scan of now.
     *
     * @param  list<array{label: string, value: int}>  $history
     */
    private static function sparkline(array $history): string
    {
        $count = \count($history);

        // One scan is a point, not a course
        if ($count < 2) {
            return '<span class="viz-spark" aria-hidden="true"></span>';
        }

        $values = array_column($history, 'value');
        $min    = min($values);
        $range  = max(1, max($values) - $min);
        $width  = 120;
        $height = 32;
        $points = [];

        foreach ($values as $i => $value) {
            $points[] = [round($i / ($count - 1) * $width, 2), round($height - 4 - ($value - $min) / $range * ($height - 8), 2)];
        }

        $svg = '<svg class="viz-spark" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="'
            . self::e(implode(', ', array_map(static fn (array $scan): string => $scan['label'] . ': ' . number_format($scan['value']), $history))) . '">'
            . '<polyline points="' . implode(' ', array_map(static fn (array $p): string => $p[0] . ',' . $p[1], $points)) . '"/>';

        [$x, $y] = $points[$count - 1];

        $svg .= '<circle cx="' . $x . '" cy="' . $y . '" r="4"/>';

        // Wide targets: the pointer has to be near a scan, not on the line
        $step = $width / ($count - 1);

        foreach ($history as $i => $scan) {
            $svg .= '<rect x="' . round($points[$i][0] - $step / 2, 2) . '" y="0" width="' . round($step, 2) . '" height="' . $height . '"'
                . ' data-viz-tip="' . self::e($scan['label']) . '" data-viz-value="' . number_format($scan['value']) . '"/>';
        }

        return $svg . '</svg>';
    }

    private static function share(float $percent): string
    {
        return ($percent > 0 && $percent < 0.1 ? '<0.1' : number_format($percent, $percent < 10 ? 1 : 0)) . '%';
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
