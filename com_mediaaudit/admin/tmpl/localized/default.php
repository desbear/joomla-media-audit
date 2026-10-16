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
use Joomla\CMS\HTML\HTMLHelper as JHtml;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

\defined('_JEXEC') or die;

/** @var \Gss\Component\Mediaaudit\Administrator\View\Localized\HtmlView $this */

$failed  = $this->getModel()->isFailed();
$waiting = $this->getModel()->isWaiting();

// The three kinds of images of other sites, in the order and the colours of the first page.
// The links are escaped where they are written
$list   = static fn (string $state): string => Route::_('index.php?option=com_mediaaudit&view=localized&filter[search]=&filter[host]=&filter[state]=' . $state, false);
$series = [
    'copied'  => ['label' => Text::_('COM_MEDIAAUDIT_CHART_COPIED'), 'detail' => Text::_('COM_MEDIAAUDIT_PROGRESS_COPIED')],
    'waiting' => ['label' => Text::_('COM_MEDIAAUDIT_CHART_WAITING'), 'detail' => Text::_('COM_MEDIAAUDIT_PROGRESS_WAITING')],
    'failed'  => ['label' => Text::_('COM_MEDIAAUDIT_CHART_FAILED'), 'detail' => Text::_('COM_MEDIAAUDIT_PROGRESS_FAILED')],
];
$whole   = $this->progress['copied'] + $this->progress['waiting'] + $this->progress['failed'];
$columns = [];

foreach ($this->course as $state) {
    $column = [
        'label'    => $state['at'] === null
            ? Text::_('COM_MEDIAAUDIT_PROGRESS_BEFORE')
            : JHtml::_('date', $state['at'], $state['by'] === 'hour' ? 'm-d H:i' : 'Y-m-d'),
        'segments' => [],
    ];

    foreach ($series as $key => $entry) {
        $column['segments'][] = ['label' => $entry['label'], 'value' => $state[$key], 'link' => $list($key)];
    }

    $columns[] = $column;
}
?>
<form action="<?php echo Route::_('index.php?option=com_mediaaudit&view=localized'); ?>" method="post" name="adminForm" id="adminForm">
    <div id="j-main-container" class="j-main-container">
        <?php if ($whole > 0) : ?>
            <div class="viz-root viz-split">
                <div class="viz-tiles">
                    <?php foreach ($series as $key => $entry) : ?>
                        <?php echo ChartHelper::tile($entry['label'], $this->progress[$key], $entry['detail'], null, '', [], $list($key)); ?>
                    <?php endforeach; ?>
                </div>
                <figure class="viz-figure">
                    <h2><?php echo Text::_('COM_MEDIAAUDIT_PROGRESS'); ?></h2>
                    <p class="viz-caption">
                        <?php echo Text::sprintf(
                            $this->progress['waiting'] > 0 ? 'COM_MEDIAAUDIT_PROGRESS_CAPTION' : 'COM_MEDIAAUDIT_PROGRESS_CAPTION_DONE',
                            number_format($whole),
                            number_format($this->progress['waiting'])
                        ); ?>
                        <?php if ($this->progress['unchanged'] > 0) : ?>
                            <?php echo Text::sprintf('COM_MEDIAAUDIT_PROGRESS_UNCHANGED', number_format($this->progress['unchanged'])); ?>
                        <?php endif; ?>
                    </p>
                    <?php echo ChartHelper::columns(Text::_('COM_MEDIAAUDIT_PROGRESS'), $columns); ?>
                    <?php echo ChartHelper::legend(array_map(
                        fn (string $key, array $entry): array => ['label' => $entry['label'], 'display' => number_format($this->progress[$key]), 'link' => $list($key)],
                        array_keys($series),
                        $series
                    )); ?>
                    <?php echo ChartHelper::table(
                        Text::_('COM_MEDIAAUDIT_CHART_TABLE'),
                        array_merge([Text::_('COM_MEDIAAUDIT_PROGRESS_TIME')], array_column($series, 'label')),
                        array_map(
                            static fn (array $column): array => array_merge([$column['label']], array_map('number_format', array_column($column['segments'], 'value'))),
                            $columns
                        )
                    ); ?>
                </figure>
            </div>
        <?php endif; ?>

        <p>
            <?php echo Text::sprintf(
                'COM_MEDIAAUDIT_LOCALIZED_TOTALS',
                number_format($this->totals['copies']),
                number_format($this->totals['files']),
                HtmlHelper::bytes($this->totals['bytes']),
                number_format($this->totals['rewritten']),
                number_format($this->totals['failed'])
            ); ?>
        </p>
        <p class="text-muted small"><?php echo Text::_('COM_MEDIAAUDIT_LOCALIZED_HINT'); ?></p>

        <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this, 'options' => ['filtersHidden' => false]]); ?>

        <?php if (!$this->items) : ?>
            <div class="alert alert-info">
                <span class="icon-info-circle" aria-hidden="true"></span>
                <?php echo Text::_($failed ? 'COM_MEDIAAUDIT_LOCALIZED_FAILED_EMPTY' : ($waiting ? 'COM_MEDIAAUDIT_LOCALIZED_WAITING_EMPTY' : 'COM_MEDIAAUDIT_LOCALIZED_EMPTY')); ?>
            </div>
        <?php elseif ($failed || $waiting) : ?>
            <table class="table" id="mediaauditList">
                <caption class="visually-hidden"><?php echo Text::_($this->title); ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?php echo Text::_('COM_MEDIAAUDIT_HEADING_URL'); ?></th>
                        <th scope="col" class="w-20"><?php echo Text::_($waiting ? 'COM_MEDIAAUDIT_HEADING_STATE' : 'COM_MEDIAAUDIT_HEADING_REASON'); ?></th>
                        <th scope="col" class="w-15 d-none d-md-table-cell"><?php echo Text::_('COM_MEDIAAUDIT_HEADING_TRIED'); ?></th>
                        <th scope="col" class="w-25"><?php echo Text::_('COM_MEDIAAUDIT_HEADING_REFERENCES'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($this->items as $item) : ?>
                        <tr>
                            <th scope="row" class="fw-normal">
                                <a class="mediaaudit-path" href="<?php echo HtmlHelper::escape($item->url); ?>" target="_blank" rel="noopener noreferrer"><?php echo HtmlHelper::escape($item->url); ?></a>
                            </th>
                            <td>
                                <?php if ($waiting) : ?>
                                    <?php echo Text::_($item->checked_at ? 'COM_MEDIAAUDIT_REASON_NOT_COPIED' : 'COM_MEDIAAUDIT_REASON_NOT_TRIED'); ?>
                                <?php else : ?>
                                    <?php echo (int) $item->http_status ? 'HTTP ' . (int) $item->http_status : Text::_('COM_MEDIAAUDIT_REASON_NO_ANSWER'); ?>
                                    <div class="small text-muted"><?php echo HtmlHelper::escape($item->error); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="d-none d-md-table-cell text-nowrap">
                                <?php echo $item->checked_at ? JHtml::_('date', $item->checked_at, Text::_('DATE_FORMAT_LC4')) : ''; ?>
                            </td>
                            <td><?php echo HtmlHelper::references($item->references, count($item->references)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php echo $this->pagination->getListFooter(); ?>
        <?php else : ?>
            <table class="table" id="mediaauditList">
                <caption class="visually-hidden"><?php echo Text::_($this->title); ?></caption>
                <thead>
                    <tr>
                        <th scope="col" class="w-1"></th>
                        <th scope="col"><?php echo Text::_('COM_MEDIAAUDIT_HEADING_COPY'); ?></th>
                        <th scope="col" class="w-10 text-end"><?php echo Text::_('COM_MEDIAAUDIT_HEADING_SIZE'); ?></th>
                        <th scope="col" class="w-10 d-none d-md-table-cell"><?php echo Text::_('COM_MEDIAAUDIT_HEADING_COPIED'); ?></th>
                        <th scope="col" class="w-25"><?php echo Text::_('COM_MEDIAAUDIT_HEADING_USED_BY'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($this->items as $item) : ?>
                        <tr>
                            <td><?php echo $item->status === 'active' ? HtmlHelper::thumbnail($item->local_path) : ''; ?></td>
                            <th scope="row" class="fw-normal">
                                <?php echo HtmlHelper::path($item->local_path); ?>
                                <div class="small text-muted">
                                    <?php echo Text::_('COM_MEDIAAUDIT_HEADING_ORIGIN'); ?>:
                                    <a class="mediaaudit-path" href="<?php echo HtmlHelper::escape($item->url); ?>" target="_blank" rel="noopener noreferrer"><?php echo HtmlHelper::escape($item->url); ?></a>
                                </div>
                                <?php if (!empty($item->converted)) : ?>
                                    <div class="small text-muted"><?php echo Text::sprintf('COM_MEDIAAUDIT_COPY_CONVERTED', HtmlHelper::escape(basename($item->converted))); ?></div>
                                <?php endif; ?>
                                <?php if ($item->status === 'quarantined') : ?>
                                    <span class="badge bg-warning text-dark"><?php echo Text::_('COM_MEDIAAUDIT_COPY_QUARANTINED'); ?></span>
                                <?php elseif ($item->status !== 'active') : ?>
                                    <span class="badge bg-danger"><?php echo Text::_('COM_MEDIAAUDIT_COPY_GONE'); ?></span>
                                <?php endif; ?>
                            </th>
                            <td class="text-end text-nowrap"><?php echo HtmlHelper::bytes((int) $item->size); ?></td>
                            <td class="d-none d-md-table-cell text-nowrap">
                                <?php echo $item->fetched_at ? JHtml::_('date', $item->fetched_at, Text::_('DATE_FORMAT_LC4')) : ''; ?>
                            </td>
                            <td>
                                <?php if (!$item->references) : ?>
                                    <span class="text-muted"><?php echo Text::_('COM_MEDIAAUDIT_COPY_UNUSED'); ?></span>
                                <?php else : ?>
                                    <?php echo HtmlHelper::references($item->references, count($item->references)); ?>
                                <?php endif; ?>
                                <?php if ($item->external) : ?>
                                    <div class="small mt-1">
                                        <span class="icon-warning text-warning" aria-hidden="true"></span>
                                        <?php echo Text::_('COM_MEDIAAUDIT_HEADING_STILL_LOADED_BY'); ?>
                                        <?php echo HtmlHelper::references($item->external, count($item->external)); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php echo $this->pagination->getListFooter(); ?>
        <?php endif; ?>

        <input type="hidden" name="task" value="">
        <input type="hidden" name="boxchecked" value="0">
        <input type="hidden" name="return_view" value="localized">
        <?php echo JHtml::_('form.token'); ?>
    </div>
</form>
