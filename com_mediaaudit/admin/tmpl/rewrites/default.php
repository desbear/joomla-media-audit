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

/** @var \Gss\Component\Mediaaudit\Administrator\View\Rewrites\HtmlView $this */
?>
<form action="<?php echo Route::_('index.php?option=com_mediaaudit&view=rewrites'); ?>" method="post" name="adminForm" id="adminForm">
    <div id="j-main-container" class="j-main-container">
        <p class="text-muted small"><?php echo Text::_('COM_MEDIAAUDIT_REWRITES_HINT'); ?></p>

        <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this, 'options' => ['filtersHidden' => false]]); ?>

        <?php if (!$this->items) : ?>
            <div class="alert alert-info">
                <span class="icon-info-circle" aria-hidden="true"></span>
                <?php echo Text::_('COM_MEDIAAUDIT_REWRITES_EMPTY'); ?>
            </div>
        <?php else : ?>
            <table class="table" id="mediaauditList">
                <caption class="visually-hidden"><?php echo Text::_($this->title); ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?php echo Text::_('COM_MEDIAAUDIT_HEADING_SOURCE'); ?></th>
                        <th scope="col" class="w-10"><?php echo Text::_('COM_MEDIAAUDIT_HEADING_FIELD'); ?></th>
                        <th scope="col" class="w-25"><?php echo Text::_('COM_MEDIAAUDIT_HEADING_CHANGE'); ?></th>
                        <th scope="col" class="w-20"><?php echo Text::_('COM_MEDIAAUDIT_HEADING_CHANGED'); ?></th>
                        <th scope="col" class="w-1 text-end"><?php echo Text::_('JGRID_HEADING_ID'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($this->items as $item) : ?>
                        <tr>
                            <th scope="row" class="fw-normal">
                                <?php $label = HtmlHelper::escape($item->source_type . ' ' . $item->source_id) . ($item->title === null ? '' : ': ' . HtmlHelper::escape($item->title)); ?>
                                <?php if ($item->link !== null) : ?>
                                    <a href="<?php echo HtmlHelper::escape(Route::_($item->link, false)); ?>" target="_blank" rel="noopener"><?php echo $label; ?></a>
                                    <?php echo HtmlHelper::state($item->state); ?>
                                <?php else : ?>
                                    <?php echo $label; ?>
                                    <span class="badge bg-secondary"><?php echo Text::_('COM_MEDIAAUDIT_SOURCE_GONE'); ?></span>
                                <?php endif; ?>
                            </th>
                            <td><code><?php echo HtmlHelper::escape($item->field); ?></code></td>
                            <td>
                                <?php echo Text::_($item->kind === 'remove_lost' ? 'COM_MEDIAAUDIT_KIND_REMOVE_LOST' : 'COM_MEDIAAUDIT_KIND_LOCALIZE'); ?>
                                <div class="small text-muted"><?php echo Text::plural('COM_MEDIAAUDIT_N_IMAGES', (int) $item->replaced); ?></div>
                            </td>
                            <td>
                                <?php echo JHtml::_('date', $item->rewritten_at, Text::_('DATE_FORMAT_LC4')); ?>
                                <div class="small text-muted"><?php echo HtmlHelper::escape($item->rewritten_by_name ?: Text::_('COM_MEDIAAUDIT_BY_SYSTEM')); ?></div>
                                <?php if ($item->reverted_at !== null) : ?>
                                    <span class="badge bg-secondary"><?php echo Text::sprintf('COM_MEDIAAUDIT_REWRITE_REVERTED', JHtml::_('date', $item->reverted_at, Text::_('DATE_FORMAT_LC4'))); ?></span>
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
        <input type="hidden" name="return_view" value="rewrites">
        <?php echo JHtml::_('form.token'); ?>
    </div>
</form>
