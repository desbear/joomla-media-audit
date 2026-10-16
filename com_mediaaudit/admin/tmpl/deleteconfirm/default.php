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
use Joomla\CMS\Router\Route;

\defined('_JEXEC') or die;

/** @var \Gss\Component\Mediaaudit\Administrator\View\Deleteconfirm\HtmlView $this */

$deletable = $this->deletable();
$refused   = $this->refused();
$bytes     = array_sum(array_column($deletable, 'size'));
?>
<form action="<?php echo Route::_('index.php?option=com_mediaaudit&view=deleteconfirm'); ?>" method="post" name="adminForm" id="adminForm">
    <div id="j-main-container" class="j-main-container">
        <?php if (!$this->records) : ?>
            <div class="alert alert-info"><?php echo Text::_('COM_MEDIAAUDIT_DELETE_NOTHING_SELECTED'); ?></div>
        <?php endif; ?>

        <?php if ($deletable) : ?>
            <div class="alert alert-danger">
                <h2 class="alert-heading h4"><?php echo Text::plural('COM_MEDIAAUDIT_DELETE_ASK', count($deletable)); ?></h2>
                <p class="mb-0"><?php echo Text::sprintf('COM_MEDIAAUDIT_DELETE_WARNING', HtmlHelper::bytes($bytes)); ?></p>
            </div>

            <table class="table">
                <caption class="visually-hidden"><?php echo Text::_('COM_MEDIAAUDIT_DELETE_LIST'); ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?php echo Text::_('COM_MEDIAAUDIT_HEADING_ORIGINAL_PATH'); ?></th>
                        <th scope="col" class="w-10 text-end"><?php echo Text::_('COM_MEDIAAUDIT_HEADING_SIZE'); ?></th>
                        <th scope="col" class="w-20"><?php echo Text::_('COM_MEDIAAUDIT_HEADING_MOVED'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($deletable as $record) : ?>
                        <tr>
                            <th scope="row" class="fw-normal">
                                <?php echo HtmlHelper::path($record['path']); ?>
                                <input type="hidden" name="cid[]" value="<?php echo (int) $record['id']; ?>">
                            </th>
                            <td class="text-end text-nowrap"><?php echo HtmlHelper::bytes($record['size']); ?></td>
                            <td><?php echo JHtml::_('date', $record['moved_at'], Text::_('DATE_FORMAT_LC4')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ($refused) : ?>
            <h2 class="h4 mt-4"><?php echo Text::plural('COM_MEDIAAUDIT_DELETE_REFUSED', count($refused)); ?></h2>
            <table class="table">
                <caption class="visually-hidden"><?php echo Text::_('COM_MEDIAAUDIT_DELETE_REFUSED_LIST'); ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?php echo Text::_('COM_MEDIAAUDIT_HEADING_ORIGINAL_PATH'); ?></th>
                        <th scope="col" class="w-50"><?php echo Text::_('COM_MEDIAAUDIT_HEADING_REASON'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($refused as $record) : ?>
                        <tr>
                            <th scope="row" class="fw-normal"><?php echo HtmlHelper::path($record['path']); ?></th>
                            <td>
                                <?php if ($record['reason'] === 'too_recent') : ?>
                                    <?php echo Text::sprintf('COM_MEDIAAUDIT_WHY_TOO_RECENT', $this->days, JHtml::_('date', $record['deletable_at'], Text::_('DATE_FORMAT_LC4'))); ?>
                                <?php else : ?>
                                    <?php echo Text::sprintf('COM_MEDIAAUDIT_WHY_' . strtoupper($record['reason']), HtmlHelper::escape($record['detail'])); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <div class="mt-4">
            <?php if ($deletable) : ?>
                <button type="button" class="btn btn-danger" onclick="this.form.task.value='quarantine.delete'; this.form.confirmed.value='1'; this.form.submit();">
                    <span class="icon-trash" aria-hidden="true"></span>
                    <?php echo Text::plural('COM_MEDIAAUDIT_DELETE_CONFIRM', count($deletable)); ?>
                </button>
            <?php endif; ?>
            <button type="button" class="btn btn-secondary" onclick="this.form.task.value='quarantine.cancelDelete'; this.form.submit();">
                <?php echo Text::_('JCANCEL'); ?>
            </button>
        </div>

        <input type="hidden" name="task" value="">
        <input type="hidden" name="confirmed" value="0">
        <input type="hidden" name="return_view" value="quarantine">
        <?php echo JHtml::_('form.token'); ?>
    </div>
</form>
