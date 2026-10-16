<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\View\Deleteconfirm;

use Gss\Component\Mediaaudit\Administrator\Controller\QuarantineController;
use Gss\Component\Mediaaudit\Administrator\Helper\HtmlHelper;
use Gss\Component\Mediaaudit\Administrator\Scanner\ScanRunner;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\GenericDataException;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Asks before files are deleted for good: what would be deleted, and what would not and why.
 */
class HtmlView extends BaseHtmlView
{
    /** @var list<array{id: int, path: string, quarantine_path: string, size: int, moved_at: string, deletable_at: string, deletable: bool, reason: string, detail: string}> */
    public array $records = [];

    public int $days = 30;

    public function display($tpl = null): void
    {
        $app = Factory::getApplication();

        if (!$this->getCurrentUser()->authorise('mediaaudit.delete', 'com_mediaaudit')) {
            throw new GenericDataException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $service = ScanRunner::create(
            Factory::getContainer()->get(DatabaseInterface::class),
            ComponentHelper::getParams('com_mediaaudit'),
            JPATH_ROOT
        )->getQuarantine();

        $this->records = $service->preview((array) $app->getUserState(QuarantineController::STATE, []));
        $this->days    = $service->getDeleteAfterDays();

        $this->getDocument()->addStyleDeclaration(HtmlHelper::styles());

        ToolbarHelper::title(Text::_('COM_MEDIAAUDIT') . ': ' . Text::_('COM_MEDIAAUDIT_VIEW_DELETECONFIRM'), 'trash');

        parent::display($tpl);
    }

    /**
     * @return  array<int, array<string, mixed>>
     */
    public function deletable(): array
    {
        return array_values(array_filter($this->records, static fn (array $record): bool => $record['deletable']));
    }

    /**
     * @return  array<int, array<string, mixed>>
     */
    public function refused(): array
    {
        return array_values(array_filter($this->records, static fn (array $record): bool => !$record['deletable']));
    }
}
