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

/** @var \Gss\Component\Mediaaudit\Administrator\View\Quarantine\HtmlView $this */

$select = $this->canQuarantine || $this->canDelete;
$now    = time();
?>
<form action="<?php echo Route::_('index.php?option=com_mediaaudit&view=quarantine'); ?>" method="post" name="adminForm" id="adminForm">
    <div id="j-main-container" class="j-main-container">
        <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this, 'options' => ['filtersHidden' => false]]); ?>

        <?php if (!$this->items) : ?>
            <div class="alert alert-info">
                <span class="icon-info-circle" aria-hidden="true"></span>
                <?php echo Text::_('COM_MEDIAAUDIT_QUARANTINE_EMPTY'); ?>
            </div>
        <?php else : ?>
            <p class="text-muted"><?php echo Text::_('COM_MEDIAAUDIT_QUARANTINE_HINT'); ?></p>
            <table class="table" id="mediaauditList">
                <caption class="visually-hidden"><?php echo Text::_($this->title); ?></caption>
                <thead>
                    <tr>
                        <td class="w-1 text-center"><?php echo $select ? JHtml::_('grid.checkall') : ''; ?></td>
                        <th scope="col"><?php echo Text::_('COM_MEDIAAUDIT_HEADING_ORIGINAL_PATH'); ?></th>
                        <th scope="col" class="w-10 text-end"><?php echo Text::_('COM_MEDIAAUDIT_HEADING_SIZE'); ?></th>
                        <th scope="col" class="w-20"><?php echo Text::_('COM_MEDIAAUDIT_HEADING_MOVED'); ?></th>
                        <th scope="col" class="w-20"><?php echo Text::_('COM_MEDIAAUDIT_HEADING_OUTCOME'); ?></th>
                        <th scope="col" class="w-1 text-end"><?php echo Text::_('JGRID_HEADING_ID'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($this->items as $i => $item) : ?>
                        <tr>
                            <td class="text-center">
                                <?php echo $select && $item->restored_at === null && $item->deleted_at === null ? JHtml::_('grid.id', $i, $item->id, false, 'cid', 'cb', $item->original_path) : ''; ?>
                            </td>
                            <th scope="row" class="fw-normal">
                                <?php echo HtmlHelper::path($item->original_path); ?>
                                <?php if ($item->restored_at === null && $item->deleted_at === null) : ?>
                                    <div class="small text-muted"><?php echo HtmlHelper::escape($item->quarantine_path); ?></div>
                                <?php endif; ?>
                                <?php if (trim((string) $item->note) !== '') : ?>
                                    <div class="small"><?php echo HtmlHelper::escape($item->note); ?></div>
                                <?php endif; ?>
                            </th>
                            <td class="text-end text-nowrap"><?php echo $item->size === null ? '' : HtmlHelper::bytes((int) $item->size); ?></td>
                            <td>
                                <?php echo JHtml::_('date', $item->moved_at, Text::_('DATE_FORMAT_LC4')); ?>
                                <div class="small text-muted"><?php echo HtmlHelper::escape($item->moved_by_name ?: Text::_('COM_MEDIAAUDIT_BY_SYSTEM')); ?></div>
                            </td>
                            <td>
                                <?php if ($item->deleted_at !== null) : ?>
                                    <span class="badge bg-danger"><?php echo Text::_('COM_MEDIAAUDIT_FILTER_STATE_DELETED'); ?></span>
                                    <?php echo JHtml::_('date', $item->deleted_at, Text::_('DATE_FORMAT_LC4')); ?>
                                    <div class="small text-muted"><?php echo HtmlHelper::escape($item->deleted_by_name ?: Text::_('COM_MEDIAAUDIT_BY_SYSTEM')); ?></div>
                                <?php elseif ($item->restored_at !== null) : ?>
                                    <?php echo JHtml::_('date', $item->restored_at, Text::_('DATE_FORMAT_LC4')); ?>
                                    <div class="small text-muted"><?php echo HtmlHelper::escape($item->restored_by_name ?: Text::_('COM_MEDIAAUDIT_BY_SYSTEM')); ?></div>
                                <?php else : ?>
                                    <span class="badge bg-warning text-dark"><?php echo Text::_('COM_MEDIAAUDIT_FILTER_STATE_HELD'); ?></span>
                                    <?php $deletableAt = strtotime($item->moved_at . ' UTC') + $this->deleteAfterDays * 86400; ?>
                                    <div class="small text-muted">
                                        <?php echo $deletableAt <= $now
                                            ? Text::_('COM_MEDIAAUDIT_CAN_BE_DELETED')
                                            : Text::sprintf('COM_MEDIAAUDIT_CAN_BE_DELETED_FROM', JHtml::_('date', $deletableAt, Text::_('DATE_FORMAT_LC4'))); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><?php echo (int) $item->id; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php echo $this->pagination->getListFooter(); ?>
        <?php endif; ?>

        <input type="hidden" name="task" value="">
        <input type="hidden" name="boxchecked" value="0">
        <input type="hidden" name="return_view" value="quarantine">
        <?php echo JHtml::_('form.token'); ?>
    </div>
</form>
