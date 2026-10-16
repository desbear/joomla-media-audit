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

/** @var \Gss\Component\Mediaaudit\Administrator\View\ListView $this */

$view   = $this->getName();
$select = $this->selectable && $this->canQuarantine && $this->scanFresh;
?>
<form action="<?php echo Route::_('index.php?option=com_mediaaudit&view=' . $view); ?>" method="post" name="adminForm" id="adminForm">
    <div id="j-main-container" class="j-main-container">
        <?php echo $this->scanNotice(); ?>
        <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>

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
                        <td class="w-1 text-center">
                            <?php echo $select ? JHtml::_('grid.checkall') : ''; ?>
                        </td>
                        <th scope="col" class="w-1"></th>
                        <th scope="col"><?php echo Text::_('COM_MEDIAAUDIT_HEADING_PATH'); ?></th>
                        <th scope="col" class="w-10 text-end"><?php echo Text::_('COM_MEDIAAUDIT_HEADING_SIZE'); ?></th>
                        <th scope="col" class="w-15 d-none d-md-table-cell"><?php echo Text::_('COM_MEDIAAUDIT_HEADING_MODIFIED'); ?></th>
                        <th scope="col" class="w-30"><?php echo Text::_('COM_MEDIAAUDIT_HEADING_REFERENCES'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($this->items as $i => $item) : ?>
                        <tr>
                            <td class="text-center">
                                <?php echo $select ? JHtml::_('grid.id', $i, $item->id, false, 'cid', 'cb', $item->path) : ''; ?>
                            </td>
                            <td><?php echo HtmlHelper::thumbnail($item->path); ?></td>
                            <th scope="row" class="fw-normal">
                                <?php echo HtmlHelper::path($item->path); ?>
                                <?php if ($item->case_suspect !== null) : ?>
                                    <div class="small text-warning-emphasis">
                                        <span class="icon-warning" aria-hidden="true"></span>
                                        <?php echo Text::sprintf('COM_MEDIAAUDIT_CASE_SUSPECT_FILE', HtmlHelper::escape($item->case_suspect)); ?>
                                    </div>
                                <?php endif; ?>
                            </th>
                            <td class="text-end text-nowrap"><?php echo HtmlHelper::bytes((int) $item->size); ?></td>
                            <td class="d-none d-md-table-cell text-nowrap">
                                <?php echo $item->mtime ? JHtml::_('date', $item->mtime, Text::_('DATE_FORMAT_LC4')) : ''; ?>
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
        <input type="hidden" name="return_view" value="<?php echo $view; ?>">
        <?php echo JHtml::_('form.token'); ?>
    </div>
</form>
