<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

use Gss\Component\Mediaaudit\Administrator\Helper\ChartHelper;
use Gss\Component\Mediaaudit\Administrator\Helper\HtmlHelper;
use Gss\Component\Mediaaudit\Administrator\Helper\SourceHelper;
use Joomla\CMS\HTML\HTMLHelper as JHtml;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

\defined('_JEXEC') or die;

/** @var \Gss\Component\Mediaaudit\Administrator\View\Dashboard\HtmlView $this */

$summary = $this->summary + array_fill_keys(
    ['files', 'in_use', 'semi_orphan', 'orphan', 'broken', 'duplicate_groups', 'wasted_bytes', 'orphan_bytes', 'in_use_bytes', 'semi_orphan_bytes', 'references', 'external_references'],
    0
);
$since   = Text::_('COM_MEDIAAUDIT_SINCE_LAST_SCAN');

// A link sets every filter of the list: what was chosen there before does not narrow what the mark counts
$link = static function (string $view, array $filter = []): string {
    $filter += match ($view) {
        'localized' => ['search' => '', 'host' => '', 'state' => 'copied'],
        'inuse', 'orphans', 'semiorphans' => ['search' => '', 'source_type' => ''],
        default => [],
    };

    // Escaped where it is written
    return Route::_('index.php?option=com_mediaaudit&view=' . $view . ($filter ? '&' . http_build_query(['filter' => $filter]) : ''), false);
};

// What is wrong, and how much of it
$tiles = [
    ['orphan', 'COM_MEDIAAUDIT_VIEW_ORPHANS', 'orphans', HtmlHelper::bytes($summary['orphan_bytes'])],
    ['semi_orphan', 'COM_MEDIAAUDIT_VIEW_SEMIORPHANS', 'semiorphans', HtmlHelper::bytes($summary['semi_orphan_bytes'])],
    ['broken', 'COM_MEDIAAUDIT_VIEW_BROKEN', 'broken', Text::_('COM_MEDIAAUDIT_TILE_BROKEN')],
    ['duplicate_groups', 'COM_MEDIAAUDIT_VIEW_DUPLICATES', 'duplicates', Text::sprintf('COM_MEDIAAUDIT_WASTED', HtmlHelper::bytes($summary['wasted_bytes']))],
];

// The images by what refers to them: the series of the first chart, in a fixed order
$classes = [
    ['in_use', 'COM_MEDIAAUDIT_DASHBOARD_IN_USE', 'inuse'],
    ['semi_orphan', 'COM_MEDIAAUDIT_VIEW_SEMIORPHANS', 'semiorphans'],
    ['orphan', 'COM_MEDIAAUDIT_VIEW_ORPHANS', 'orphans'],
];

$byNumber = [];
$bySize   = [];

foreach ($classes as [$key, $title, $view]) {
    $byNumber[] = ['label' => Text::_($title), 'value' => $summary[$key], 'display' => number_format($summary[$key]), 'link' => $link($view)];
    $bySize[]   = ['label' => Text::_($title), 'value' => $summary[$key . '_bytes'], 'display' => HtmlHelper::bytes($summary[$key . '_bytes']), 'link' => $link($view)];
}

// The kinds of sources: the eight with the most references, the rest together
$sources = [];
$other   = ['references' => 0, 'sources' => 0];

foreach ($this->sources as $type => $source) {
    if (count($sources) < 8) {
        $sources[] = [
            'label'   => SourceHelper::label($type),
            'value'   => $source['references'],
            'display' => number_format($source['references']),
            'detail'  => Text::plural('COM_MEDIAAUDIT_N_SOURCES', $source['sources']),
            'sources' => $source['sources'],
            'link'    => $link('inuse', ['source_type' => $type]),
        ];
    } else {
        $other['references'] += $source['references'];
        $other['sources']    += $source['sources'];
    }
}

if ($other['references'] > 0) {
    $sources[] = [
        'label'   => Text::_('COM_MEDIAAUDIT_CHART_OTHER'),
        'value'   => $other['references'],
        'display' => number_format($other['references']),
        'detail'  => Text::plural('COM_MEDIAAUDIT_N_SOURCES', $other['sources']),
        'sources' => $other['sources'],
    ];
}

$series = [Text::_('COM_MEDIAAUDIT_CHART_COPIED'), Text::_('COM_MEDIAAUDIT_CHART_WAITING'), Text::_('COM_MEDIAAUDIT_CHART_FAILED')];
$hosts  = [];

foreach (array_slice($this->externalByHost, 0, 8) as $host) {
    // The name leads to what is left to do of the host, the marks to what they count
    $first = $host['waiting'] > 0 ? 'waiting' : ($host['copied'] > 0 ? 'copied' : 'failed');

    $hosts[] = [
        'label'    => $host['host'],
        'link'     => $link('localized', ['host' => $host['host'], 'state' => $first]),
        'segments' => [
            ['label' => $series[0], 'value' => $host['copied'], 'link' => $link('localized', ['host' => $host['host'], 'state' => 'copied'])],
            ['label' => $series[1], 'value' => $host['waiting'], 'link' => $link('localized', ['host' => $host['host'], 'state' => 'waiting'])],
            ['label' => $series[2], 'value' => $host['failed'], 'link' => $link('localized', ['host' => $host['host'], 'state' => 'failed'])],
        ],
    ];
}

$external = ['copied' => array_sum(array_column($this->externalByHost, 'copied')), 'waiting' => array_sum(array_column($this->externalByHost, 'waiting')), 'failed' => array_sum(array_column($this->externalByHost, 'failed'))];
?>
<form action="<?php echo Route::_('index.php?option=com_mediaaudit&view=dashboard'); ?>" method="post" name="adminForm" id="adminForm">
    <div id="j-main-container" class="j-main-container viz-root">
        <?php if ($this->ownHosts) : ?>
            <div class="alert alert-warning">
                <span class="icon-warning" aria-hidden="true"></span>
                <?php echo Text::sprintf(
                    'COM_MEDIAAUDIT_OWN_HOSTS',
                    implode(', ', array_map(static fn (string $host, int $files): string => '<code>' . HtmlHelper::escape($host) . '</code> (' . number_format($files) . ')', array_keys($this->ownHosts), $this->ownHosts))
                ); ?>
            </div>
        <?php endif; ?>

        <?php if ($this->lastScan === null) : ?>
            <div class="alert alert-info">
                <span class="icon-info-circle" aria-hidden="true"></span>
                <?php echo Text::_('COM_MEDIAAUDIT_NO_SCAN_YET'); ?>
            </div>
        <?php else : ?>
            <p class="text-muted">
                <?php echo Text::sprintf(
                    'COM_MEDIAAUDIT_LAST_SCAN',
                    JHtml::_('date', $this->lastScan['scanned_at'], Text::_('DATE_FORMAT_LC2')),
                    $this->lastScan['seconds'],
                    number_format($summary['files']),
                    number_format($summary['references'])
                ); ?>
            </p>
        <?php endif; ?>

        <div class="viz-tiles">
            <?php foreach ($tiles as [$key, $title, $view, $detail]) : ?>
                <?php echo ChartHelper::tile(Text::_($title), $summary[$key], $detail, $this->change($key), $since, $this->course($key), $link($view)); ?>
            <?php endforeach; ?>
        </div>

        <figure class="viz-figure">
            <h2><?php echo Text::_('COM_MEDIAAUDIT_CHART_CLASSES'); ?></h2>
            <p class="viz-caption"><?php echo Text::sprintf('COM_MEDIAAUDIT_CHART_CLASSES_CAPTION', number_format($summary['files']), HtmlHelper::bytes($summary['in_use_bytes'] + $summary['semi_orphan_bytes'] + $summary['orphan_bytes'])); ?></p>
            <?php if ($summary['files'] === 0) : ?>
                <p class="viz-none"><?php echo Text::_('COM_MEDIAAUDIT_CHART_NO_IMAGES'); ?></p>
            <?php else : ?>
                <?php echo ChartHelper::stackedBar(Text::_('COM_MEDIAAUDIT_CHART_BY_NUMBER'), $byNumber); ?>
                <?php echo ChartHelper::stackedBar(Text::_('COM_MEDIAAUDIT_CHART_BY_SIZE'), $bySize); ?>
                <?php echo ChartHelper::legend(array_map(
                    static fn (array $number, array $size): array => ['label' => $number['label'], 'display' => $number['display'] . ' · ' . $size['display'], 'link' => $number['link']],
                    $byNumber,
                    $bySize
                )); ?>
                <?php echo ChartHelper::table(
                    Text::_('COM_MEDIAAUDIT_CHART_TABLE'),
                    [Text::_('COM_MEDIAAUDIT_COL_CLASS'), Text::_('COM_MEDIAAUDIT_CHART_BY_NUMBER'), Text::_('COM_MEDIAAUDIT_CHART_BY_SIZE')],
                    array_map(static fn (array $number, array $size): array => [$number['label'], $number['display'], $size['display']], $byNumber, $bySize)
                ); ?>
            <?php endif; ?>
        </figure>

        <div class="viz-grid">
            <figure class="viz-figure">
                <h2><?php echo Text::_('COM_MEDIAAUDIT_CHART_SOURCES'); ?></h2>
                <p class="viz-caption"><?php echo Text::_('COM_MEDIAAUDIT_CHART_SOURCES_CAPTION'); ?> <?php echo Text::_('COM_MEDIAAUDIT_CHART_LINKS'); ?></p>
                <?php if (!$sources) : ?>
                    <p class="viz-none"><?php echo Text::_('COM_MEDIAAUDIT_CHART_NO_REFERENCES'); ?></p>
                <?php else : ?>
                    <?php echo ChartHelper::bars($sources); ?>
                    <?php echo ChartHelper::table(
                        Text::_('COM_MEDIAAUDIT_CHART_TABLE'),
                        [Text::_('COM_MEDIAAUDIT_FILTER_SOURCE_TYPE'), Text::_('COM_MEDIAAUDIT_CHART_REFERENCES'), Text::_('COM_MEDIAAUDIT_CHART_SOURCES_WITH_IMAGES')],
                        array_map(static fn (array $row): array => [$row['label'], $row['display'], number_format($row['sources'])], $sources)
                    ); ?>
                <?php endif; ?>
            </figure>

            <figure class="viz-figure">
                <h2><?php echo Text::_('COM_MEDIAAUDIT_CHART_EXTERNAL'); ?></h2>
                <p class="viz-caption">
                    <?php echo Text::sprintf('COM_MEDIAAUDIT_CHART_EXTERNAL_CAPTION', number_format($this->localized['rewritten']), HtmlHelper::bytes($this->localized['bytes'])); ?>
                    <a href="<?php echo HtmlHelper::escape($link('localized')); ?>"><?php echo Text::_('COM_MEDIAAUDIT_VIEW_LOCALIZED'); ?></a>
                </p>
                <?php if (!$hosts) : ?>
                    <p class="viz-none"><?php echo Text::_('COM_MEDIAAUDIT_DASHBOARD_NO_HOSTS'); ?></p>
                <?php else : ?>
                    <?php echo ChartHelper::stackedBars($hosts); ?>
                    <?php echo ChartHelper::legend([
                        ['label' => $series[0], 'display' => number_format($external['copied']), 'link' => $link('localized', ['state' => 'copied'])],
                        ['label' => $series[1], 'display' => number_format($external['waiting']), 'link' => $link('localized', ['state' => 'waiting'])],
                        ['label' => $series[2], 'display' => number_format($external['failed']), 'link' => $link('localized', ['state' => 'failed'])],
                    ]); ?>
                    <?php echo ChartHelper::table(
                        Text::_('COM_MEDIAAUDIT_CHART_TABLE'),
                        array_merge([Text::_('COM_MEDIAAUDIT_HEADING_HOST')], $series),
                        array_map(
                            static fn (array $host): array => [$host['host'], number_format($host['copied']), number_format($host['waiting']), number_format($host['failed'])],
                            $this->externalByHost
                        )
                    ); ?>
                <?php endif; ?>
            </figure>
        </div>

        <p class="text-muted small mt-4">
            <a href="<?php echo HtmlHelper::escape($link('quarantine')); ?>"><?php echo Text::_('COM_MEDIAAUDIT_VIEW_QUARANTINE'); ?></a>:
            <?php echo Text::plural('COM_MEDIAAUDIT_N_HELD', $this->quarantined); ?>
            &middot; <?php echo Text::_('COM_MEDIAAUDIT_REPORT_NOTE'); ?>
        </p>

        <input type="hidden" name="task" value="">
        <input type="hidden" name="boxchecked" value="0">
        <input type="hidden" name="return_view" value="dashboard">
        <?php echo JHtml::_('form.token'); ?>
    </div>
</form>
