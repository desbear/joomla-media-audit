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

/** @var \Gss\Component\Mediaaudit\Administrator\View\Broken\HtmlView $this */

$external = $this->getModel()->isExternal();
?>
<form action="<?php echo Route::_('index.php?option=com_mediaaudit&view=broken'); ?>" method="post" name="adminForm" id="adminForm">
    <div id="j-main-container" class="j-main-container">
        <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this, 'options' => ['filtersHidden' => false]]); ?>

        <?php if (!$this->items) : ?>
            <div class="alert alert-info">
                <span class="icon-info-circle" aria-hidden="true"></span>
                <?php echo Text::_($external ? 'COM_MEDIAAUDIT_NO_BROKEN_EXTERNAL' : ($this->lastScan === null ? 'COM_MEDIAAUDIT_NO_SCAN_YET' : 'JGLOBAL_NO_MATCHING_RESULTS')); ?>
            </div>
        <?php else : ?>
            <table class="table" id="mediaauditList">
                <caption class="visually-hidden"><?php echo Text::_($this->title); ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?php echo Text::_($external ? 'COM_MEDIAAUDIT_HEADING_URL' : 'COM_MEDIAAUDIT_HEADING_PATH'); ?></th>
                        <th scope="col" class="w-15"><?php echo Text::_('COM_MEDIAAUDIT_HEADING_REASON'); ?></th>
                        <th scope="col" class="w-10 text-end"><?php echo Text::_('COM_MEDIAAUDIT_HEADING_PUBLISHED_REFERENCES'); ?></th>
                        <th scope="col" class="w-30"><?php echo Text::_('COM_MEDIAAUDIT_HEADING_REFERENCES'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($this->items as $item) : ?>
                        <tr>
                            <th scope="row" class="fw-normal">
                                <?php if ($external) : ?>
                                    <a class="mediaaudit-path" href="<?php echo HtmlHelper::escape($item->target); ?>" target="_blank" rel="noopener noreferrer"><?php echo HtmlHelper::escape($item->target); ?></a>
                                <?php else : ?>
                                    <?php echo HtmlHelper::path($item->target); ?>
                                <?php endif; ?>
                            </th>
                            <td>
                                <?php if ($external) : ?>
                                    <?php echo (int) $item->http_status ? 'HTTP ' . (int) $item->http_status : Text::_('COM_MEDIAAUDIT_REASON_NO_ANSWER'); ?>
                                    <?php if ($item->error !== '') : ?>
                                        <div class="small text-muted"><?php echo HtmlHelper::escape($item->error); ?></div>
                                    <?php endif; ?>
                                <?php elseif ($item->quarantined) : ?>
                                    <span class="badge bg-warning text-dark"><?php echo Text::_('COM_MEDIAAUDIT_REASON_QUARANTINED'); ?></span>
                                <?php elseif ($item->case_suspect !== null) : ?>
                                    <span class="badge bg-warning text-dark"><?php echo Text::_('COM_MEDIAAUDIT_REASON_CASE'); ?></span>
                                    <div class="small"><?php echo Text::sprintf('COM_MEDIAAUDIT_CASE_SUSPECT_REFERENCE', HtmlHelper::escape($item->case_suspect)); ?></div>
                                <?php else : ?>
                                    <?php echo Text::_('COM_MEDIAAUDIT_REASON_MISSING'); ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><?php echo (int) $item->live_refs; ?></td>
                            <td><?php echo HtmlHelper::references($item->references, (int) $item->refs); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php echo $this->pagination->getListFooter(); ?>
        <?php endif; ?>

        <input type="hidden" name="task" value="">
        <input type="hidden" name="boxchecked" value="0">
        <input type="hidden" name="return_view" value="broken">
        <?php echo JHtml::_('form.token'); ?>
    </div>
</form>
