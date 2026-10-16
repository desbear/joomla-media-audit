<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

use Gss\Component\Mediaaudit\Administrator\Converter\ImageConverter;
use Gss\Component\Mediaaudit\Administrator\Helper\ChartHelper;
use Gss\Component\Mediaaudit\Administrator\Helper\HtmlHelper;
use Gss\Component\Mediaaudit\Administrator\View\Images\HtmlView;
use Joomla\CMS\HTML\HTMLHelper as JHtml;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

\defined('_JEXEC') or die;

/** @var HtmlView $this */

$names = ['jpg' => 'JPEG', 'png' => 'PNG', 'gif' => 'GIF', 'webp' => 'WebP', 'avif' => 'AVIF', 'svg' => 'SVG'];
$type  = (string) $this->state->get('filter.type');

// A link sets every filter of the list. Escaped where it is written
$link = static fn (array $filter): string => Route::_(
    'index.php?option=com_mediaaudit&view=images&' . http_build_query(['filter' => $filter + ['search' => '', 'type' => '', 'used' => '', 'state' => '']]),
    false
);

$series = [Text::_('COM_MEDIAAUDIT_FILTER_USED_YES'), Text::_('COM_MEDIAAUDIT_FILTER_USED_NO')];
$rows   = [];
$bytes  = ['used' => 0, 'unused' => 0];
$files  = ['used' => 0, 'unused' => 0];

foreach ($this->types as $entry) {
    $name = $names[$entry['type']] ?? strtoupper($entry['type']);

    $rows[] = [
        // The type that the list is narrowed to stands out
        'label'    => ($entry['type'] === $type ? '▸ ' : '') . $name,
        'link'     => $link(['type' => $entry['type']]),
        'segments' => [
            ['label' => $series[0], 'value' => $entry['used'], 'link' => $link(['type' => $entry['type'], 'used' => '1'])],
            ['label' => $series[1], 'value' => $entry['files'] - $entry['used'], 'link' => $link(['type' => $entry['type'], 'used' => '0'])],
        ],
    ];

    $files['used']   += $entry['used'];
    $files['unused'] += $entry['files'] - $entry['used'];
    $bytes['used']   += $entry['used_bytes'];
    $bytes['unused'] += $entry['bytes'] - $entry['used_bytes'];
}

$saved = $this->totals['bytes_before'] - $this->totals['bytes_after'];
$tiles = [
    ['COM_MEDIAAUDIT_CONVERSION_WAITING', $this->totals['waiting'], Text::_('COM_MEDIAAUDIT_TILE_WAITING'), 'waiting'],
    [
        'COM_MEDIAAUDIT_CONVERSION_CONVERTED',
        $this->totals['converted'],
        $this->totals['converted'] > 0
            ? Text::sprintf('COM_MEDIAAUDIT_TILE_SAVED', HtmlHelper::bytes(max(0, $saved)), (string) round($saved / max(1, $this->totals['bytes_before']) * 100))
            : '',
        ImageConverter::CONVERTED,
    ],
    ['COM_MEDIAAUDIT_CONVERSION_NOT_SMALLER', $this->totals['not_smaller'], Text::_('COM_MEDIAAUDIT_TILE_NOT_SMALLER'), ImageConverter::NOT_SMALLER],
    ['COM_MEDIAAUDIT_CONVERSION_FAILED', $this->totals['failed'], '', ImageConverter::FAILED],
];

$writers = [];

foreach ($this->capabilities as $format => $encoders) {
    $writers[] = ($names[$format] ?? $format) . ': '
        . ($encoders ? implode(', ', array_map(static fn (string $encoder): string => Text::_('COM_MEDIAAUDIT_ENCODER_' . strtoupper($encoder)), $encoders)) : Text::_('COM_MEDIAAUDIT_ENCODER_NONE'));
}

$select = $this->canConvert;
?>
<form action="<?php echo Route::_('index.php?option=com_mediaaudit&view=images'); ?>" method="post" name="adminForm" id="adminForm">
    <div id="j-main-container" class="j-main-container">
        <div class="viz-root viz-split">
            <div class="viz-tiles">
                <?php foreach ($tiles as [$title, $number, $detail, $state]) : ?>
                    <?php echo ChartHelper::tile(Text::_($title), $number, $detail, null, '', [], $link(['state' => $state])); ?>
                <?php endforeach; ?>
            </div>
            <figure class="viz-figure">
                <h2><?php echo Text::_('COM_MEDIAAUDIT_CHART_TYPES'); ?></h2>
                <p class="viz-caption">
                    <?php echo Text::sprintf('COM_MEDIAAUDIT_CHART_TYPES_CAPTION', number_format($files['used'] + $files['unused']), HtmlHelper::bytes($bytes['used'] + $bytes['unused'])); ?>
                    <?php if ($type !== '' || $this->activeFilters) : ?>
                        <a href="<?php echo HtmlHelper::escape($link([])); ?>"><?php echo Text::_('COM_MEDIAAUDIT_CHART_TYPES_ALL'); ?></a>
                    <?php endif; ?>
                </p>
                <?php if (!$rows) : ?>
                    <p class="viz-none"><?php echo Text::_('COM_MEDIAAUDIT_CHART_NO_IMAGES'); ?></p>
                <?php else : ?>
                    <?php echo ChartHelper::stackedBars($rows); ?>
                    <?php echo ChartHelper::legend([
                        ['label' => $series[0], 'display' => number_format($files['used']) . ' · ' . HtmlHelper::bytes($bytes['used']), 'link' => $link(['used' => '1'])],
                        ['label' => $series[1], 'display' => number_format($files['unused']) . ' · ' . HtmlHelper::bytes($bytes['unused']), 'link' => $link(['used' => '0'])],
                    ]); ?>
                    <?php echo ChartHelper::table(
                        Text::_('COM_MEDIAAUDIT_CHART_TABLE'),
                        [Text::_('COM_MEDIAAUDIT_FILTER_TYPE'), Text::_('COM_MEDIAAUDIT_HEADING_FILES'), Text::_('COM_MEDIAAUDIT_HEADING_SIZE'), $series[0], $series[1]],
                        array_map(
                            static fn (array $entry): array => [
                                $names[$entry['type']] ?? strtoupper($entry['type']),
                                number_format($entry['files']),
                                HtmlHelper::bytes($entry['bytes']),
                                number_format($entry['used']),
                                number_format($entry['files'] - $entry['used']),
                            ],
                            $this->types
                        )
                    ); ?>
                <?php endif; ?>
            </figure>
        </div>

        <?php if ($this->format === '') : ?>
            <div class="alert alert-info">
                <span class="icon-info-circle" aria-hidden="true"></span>
                <?php echo Text::_('COM_MEDIAAUDIT_CONVERT_OFF'); ?>
                <?php echo Text::sprintf('COM_MEDIAAUDIT_CONVERT_WRITERS', HtmlHelper::escape(implode('; ', $writers))); ?>
            </div>
        <?php elseif (empty($this->capabilities[$this->format])) : ?>
            <div class="alert alert-warning">
                <span class="icon-warning" aria-hidden="true"></span>
                <?php echo Text::sprintf('COM_MEDIAAUDIT_CONVERT_CANNOT', $names[$this->format]); ?>
                <?php echo Text::sprintf('COM_MEDIAAUDIT_CONVERT_WRITERS', HtmlHelper::escape(implode('; ', $writers))); ?>
            </div>
        <?php else : ?>
            <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
                <span>
                    <?php echo Text::sprintf(
                        'COM_MEDIAAUDIT_CONVERT_SETTING',
                        $names[$this->format],
                        $this->quality,
                        Text::_('COM_MEDIAAUDIT_ENCODER_' . strtoupper($this->capabilities[$this->format][0]))
                    ); ?>
                </span>
                <?php if ($this->canConvert) : ?>
                    <label class="d-inline-flex align-items-center gap-2 mb-0" for="convert_limit">
                        <?php echo Text::_('COM_MEDIAAUDIT_CONVERT_LIMIT'); ?>
                        <input type="number" class="form-control form-control-sm" style="width:6em" id="convert_limit" name="convert_limit"
                            value="<?php echo $this->batch; ?>" min="1" max="<?php echo HtmlView::MOST; ?>" step="1" inputmode="numeric">
                    </label>
                    <span class="text-muted small"><?php echo Text::sprintf('COM_MEDIAAUDIT_CONVERT_LIMIT_NOTE', HtmlView::MOST); ?></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <p class="text-muted small"><?php echo Text::_('COM_MEDIAAUDIT_CONVERT_HINT'); ?></p>

        <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this, 'options' => ['filtersHidden' => false]]); ?>

        <?php if (!$this->items) : ?>
            <div class="alert alert-info">
                <span class="icon-info-circle" aria-hidden="true"></span>
                <?php echo Text::_($this->lastScan === null ? 'COM_MEDIAAUDIT_NO_SCAN_YET' : 'JGLOBAL_NO_MATCHING_RESULTS'); ?>
            </div>
        <?php else : ?>
            <table class="table" id="mediaauditList">
                <caption class="visually-hidden"><?php echo Text::_($this->title); ?></caption>
                <thead>
                    <tr>
                        <td class="w-1 text-center"><?php echo $select ? JHtml::_('grid.checkall') : ''; ?></td>
                        <th scope="col" class="w-1"></th>
                        <th scope="col"><?php echo Text::_('COM_MEDIAAUDIT_HEADING_PATH'); ?></th>
                        <th scope="col" class="w-5"><?php echo Text::_('COM_MEDIAAUDIT_FILTER_TYPE'); ?></th>
                        <th scope="col" class="w-10 text-end"><?php echo Text::_('COM_MEDIAAUDIT_HEADING_SIZE'); ?></th>
                        <th scope="col" class="w-20"><?php echo Text::_('COM_MEDIAAUDIT_FILTER_CONVERSION'); ?></th>
                        <th scope="col" class="w-20"><?php echo Text::_('COM_MEDIAAUDIT_HEADING_REFERENCES'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($this->items as $i => $item) : ?>
                        <tr>
                            <td class="text-center">
                                <?php echo $select && $item->convertible ? JHtml::_('grid.id', $i, $item->id, false, 'cid', 'cb', $item->path) : ''; ?>
                            </td>
                            <td><?php echo HtmlHelper::thumbnail($item->path); ?></td>
                            <th scope="row" class="fw-normal">
                                <?php echo HtmlHelper::path($item->path); ?>
                                <?php if ($item->made_of !== null) : ?>
                                    <div class="small text-muted"><?php echo Text::sprintf('COM_MEDIAAUDIT_MADE_OF', HtmlHelper::escape($item->made_of)); ?></div>
                                <?php endif; ?>
                            </th>
                            <td><?php echo HtmlHelper::escape($names[$item->type] ?? strtoupper((string) $item->type)); ?></td>
                            <td class="text-end text-nowrap"><?php echo HtmlHelper::bytes((int) $item->size); ?></td>
                            <td>
                                <?php if ($item->derived) : ?>
                                    <span class="text-muted"><?php echo Text::_('COM_MEDIAAUDIT_CONVERSION_DERIVED'); ?></span>
                                <?php elseif ($item->conversion === null) : ?>
                                    <?php if ($item->convertible && $item->rewritten) : ?>
                                        <span class="badge bg-secondary"><?php echo Text::_('COM_MEDIAAUDIT_CONVERSION_WAITING'); ?></span>
                                    <?php elseif ($item->convertible && (int) $item->refs > 0) : ?>
                                        <span class="text-muted small"><?php echo Text::_('COM_MEDIAAUDIT_CONVERSION_NOT_REWRITTEN'); ?></span>
                                    <?php endif; ?>
                                <?php elseif ($item->stale) : ?>
                                    <span class="badge bg-secondary"><?php echo Text::_('COM_MEDIAAUDIT_CONVERSION_WAITING'); ?></span>
                                    <div class="small text-muted"><?php echo Text::_('COM_MEDIAAUDIT_CONVERSION_STALE'); ?></div>
                                <?php elseif ($item->conversion === ImageConverter::CONVERTED) : ?>
                                    <span class="badge bg-success"><?php echo Text::_('COM_MEDIAAUDIT_CONVERSION_CONVERTED'); ?></span>
                                    <div class="small">
                                        <?php echo HtmlHelper::escape(basename((string) $item->target_path)); ?>
                                        <span class="text-nowrap">
                                            <?php echo HtmlHelper::bytes((int) $item->target_size); ?>
                                            (−<?php echo (int) round(100 - (int) $item->target_size / max(1, (int) $item->converted_size) * 100); ?>%)
                                        </span>
                                    </div>
                                <?php elseif ($item->conversion === ImageConverter::NOT_SMALLER) : ?>
                                    <span class="badge bg-info"><?php echo Text::_('COM_MEDIAAUDIT_CONVERSION_NOT_SMALLER'); ?></span>
                                    <div class="small text-muted">
                                        <?php echo Text::sprintf('COM_MEDIAAUDIT_CONVERSION_WOULD_BE', $names[$item->format] ?? $item->format, HtmlHelper::bytes((int) $item->target_size)); ?>
                                    </div>
                                <?php else : ?>
                                    <span class="badge bg-danger"><?php echo Text::_('COM_MEDIAAUDIT_CONVERSION_FAILED'); ?></span>
                                    <div class="small text-muted"><?php echo HtmlHelper::escape($item->error); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo HtmlHelper::references($item->references, (int) $item->refs); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php echo $this->pagination->getListFooter(); ?>
        <?php endif; ?>

        <input type="hidden" name="task" value="">
        <input type="hidden" name="boxchecked" value="0">
        <input type="hidden" name="return_view" value="images">
        <?php echo JHtml::_('form.token'); ?>
    </div>
</form>
