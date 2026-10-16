<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

use Gss\Component\Mediaaudit\Administrator\Helper\HtmlHelper;
use Joomla\CMS\HTML\HTMLHelper as JHtml;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

\defined('_JEXEC') or die;

/** @var \Gss\Component\Mediaaudit\Administrator\View\Duplicates\HtmlView $this */

$select = $this->canQuarantine && $this->scanFresh;
$row    = 0;

// Selecting all leaves one file of every group: when nothing refers to any of them, all of them
// could go, and the image would be gone with its copies. The one that is left can be selected by hand.
$this->getDocument()->getWebAssetManager()->addInlineScript(<<<'JS'
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var form = document.getElementById('adminForm');

    if (!form) {
        return;
    }

    form.addEventListener('click', function (event) {
        var button = event.target.closest('[data-mediaaudit-group]');

        if (!button) {
            return;
        }

        var boxes = form.querySelectorAll('tr[data-mediaaudit-of="' + button.getAttribute('data-mediaaudit-group') + '"] input[type="checkbox"][id^="cb"]');
        var all = boxes.length > 0 && Array.prototype.every.call(boxes, function (box) {
            return box.checked;
        });

        Array.prototype.forEach.call(boxes, function (box) {
            box.checked = !all;
        });

        // What the buttons of the toolbar look at
        form.boxchecked.value = form.querySelectorAll('input[type="checkbox"][name="cid[]"]:checked').length;
        form.boxchecked.dispatchEvent(new CustomEvent('change', {bubbles: true, cancelable: true}));
    });
});
JS);
?>
<form action="<?php echo Route::_('index.php?option=com_mediaaudit&view=duplicates'); ?>" method="post" name="adminForm" id="adminForm">
    <div id="j-main-container" class="j-main-container">
        <?php echo $this->scanNotice(); ?>
        <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this, 'options' => ['filtersHidden' => false]]); ?>

        <?php $derived = $this->getModel()->getDerivedCount(); ?>
        <?php if ($derived > 0 && !$this->getModel()->withDerived()) : ?>
            <p class="text-muted small">
                <span class="icon-info-circle" aria-hidden="true"></span>
                <?php echo Text::sprintf('COM_MEDIAAUDIT_DUPLICATES_DERIVED', number_format($derived)); ?>
            </p>
        <?php endif; ?>

        <?php if (!$this->items) : ?>
            <div class="alert alert-info">
                <span class="icon-info-circle" aria-hidden="true"></span>
                <?php echo Text::_($this->lastScan === null ? 'COM_MEDIAAUDIT_NO_SCAN_YET' : 'JGLOBAL_NO_MATCHING_RESULTS'); ?>
            </div>
        <?php else : ?>
            <p class="text-muted">
                <?php echo Text::_('COM_MEDIAAUDIT_DUPLICATES_HINT'); ?>
                <?php echo $select ? Text::_('COM_MEDIAAUDIT_DUPLICATES_SELECT_HINT') : ''; ?>
            </p>
            <table class="table" id="mediaauditList">
                <caption class="visually-hidden"><?php echo Text::_($this->title); ?></caption>
                <thead>
                    <tr>
                        <td class="w-1 text-center">
                            <?php echo $select ? JHtml::_('grid.checkall') : ''; ?>
                        </td>
                        <th scope="col" class="w-1"></th>
                        <th scope="col"><?php echo Text::_('COM_MEDIAAUDIT_HEADING_PATH'); ?></th>
                        <th scope="col" class="w-15 d-none d-md-table-cell"><?php echo Text::_('COM_MEDIAAUDIT_HEADING_MODIFIED'); ?></th>
                        <th scope="col" class="w-15 text-end"><?php echo Text::_('COM_MEDIAAUDIT_HEADING_REFERENCES'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($this->items as $group) : ?>
                        <?php
                        // The file that stays when all are selected, unless content refers to one of the group:
                        // the oldest, which is the one that the others were copied from as a rule
                        $referred = array_filter($group->files, static fn (object $file): bool => (int) $file->refs > 0);
                        $oldest   = $group->files;

                        usort($oldest, static fn (object $a, object $b): int => [(string) ($a->mtime ?: '9999'), \strlen($a->path), $a->path] <=> [(string) ($b->mtime ?: '9999'), \strlen($b->path), $b->path]);

                        $kept = $referred || !$oldest ? null : (int) $oldest[0]->id;
                        $extras   = \count($group->files) - \count($referred) - ($kept === null ? 0 : 1);
                        ?>
                        <tr class="mediaaudit-group">
                            <td class="text-center">
                                <?php if ($select && $extras > 0) : ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary px-2 py-0" data-mediaaudit-group="<?php echo HtmlHelper::escape($group->sha1); ?>"
                                        title="<?php echo Text::_('COM_MEDIAAUDIT_DUPLICATES_SELECT_GROUP'); ?>">
                                        <span class="icon-check-square" aria-hidden="true"></span>
                                        <span class="visually-hidden"><?php echo Text::_('COM_MEDIAAUDIT_DUPLICATES_SELECT_GROUP'); ?></span>
                                    </button>
                                <?php endif; ?>
                            </td>
                            <td colspan="4">
                                <strong><?php echo Text::plural('COM_MEDIAAUDIT_N_COPIES', (int) $group->copies); ?></strong>
                                &middot; <?php echo HtmlHelper::bytes((int) $group->size); ?>
                                &middot; <?php echo Text::sprintf('COM_MEDIAAUDIT_WASTED', HtmlHelper::bytes((int) $group->wasted)); ?>
                                <code class="ms-2 text-muted"><?php echo HtmlHelper::escape(substr($group->sha1, 0, 12)); ?></code>
                            </td>
                        </tr>
                        <?php foreach ($group->files as $file) : ?>
                            <tr data-mediaaudit-of="<?php echo HtmlHelper::escape($group->sha1); ?>">
                                <td class="text-center">
                                    <?php // Only what nothing refers to can go. "kb" is not "cb": selecting all passes it by ?>
                                    <?php echo $select && (int) $file->refs === 0 ? JHtml::_('grid.id', $row, $file->id, false, 'cid', (int) $file->id === $kept ? 'kb' : 'cb', $file->path) : ''; ?>
                                    <?php $row++; ?>
                                </td>
                                <td><?php echo HtmlHelper::thumbnail($file->path); ?></td>
                                <th scope="row" class="fw-normal">
                                    <?php echo HtmlHelper::path($file->path); ?>
                                    <?php if (!empty($file->derived)) : ?>
                                        <span class="badge bg-secondary"><?php echo Text::_('COM_MEDIAAUDIT_DERIVED'); ?></span>
                                    <?php endif; ?>
                                    <?php if ($select && (int) $file->id === $kept) : ?>
                                        <span class="badge bg-info"><?php echo Text::_('COM_MEDIAAUDIT_DUPLICATES_KEPT'); ?></span>
                                    <?php endif; ?>
                                </th>
                                <td class="d-none d-md-table-cell text-nowrap">
                                    <?php echo $file->mtime ? JHtml::_('date', $file->mtime, Text::_('DATE_FORMAT_LC4')) : ''; ?>
                                </td>
                                <td class="text-end">
                                    <?php echo (int) $file->refs; ?>
                                    <?php if ((int) $file->refs > 0 && (int) $file->live_refs === 0) : ?>
                                        <span class="badge bg-secondary"><?php echo Text::_('COM_MEDIAAUDIT_UNPUBLISHED_ONLY'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php echo $this->pagination->getListFooter(); ?>
        <?php endif; ?>

        <input type="hidden" name="task" value="">
        <input type="hidden" name="boxchecked" value="0">
        <input type="hidden" name="return_view" value="duplicates">
        <?php echo JHtml::_('form.token'); ?>
    </div>
</form>
