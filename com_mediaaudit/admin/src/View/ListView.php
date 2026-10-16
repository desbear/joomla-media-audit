<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\View;

use Gss\Component\Mediaaudit\Administrator\Helper\HtmlHelper;
use Gss\Component\Mediaaudit\Administrator\Report\ScanHistory;
use Gss\Component\Mediaaudit\Administrator\Service\QuarantineService;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\GenericDataException;
use Joomla\CMS\MVC\View\HtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * What the lists of the component share.
 */
abstract class ListView extends HtmlView
{
    public $items = [];

    public $pagination;

    public $state;

    public $filterForm;

    public $activeFilters = [];

    public bool $canQuarantine = false;

    public bool $canDelete = false;

    /** Whether the files of the list can be selected to move them */
    public bool $selectable = true;

    /** Days that a file has to be in quarantine before it can be deleted */
    public int $deleteAfterDays = 30;

    /** Whether the last scan is young enough to move files by it */
    public bool $scanFresh = false;

    public int $maxScanAge = 24;

    /** @var array{scanned_at: string, seconds: float, source: string, summary: array<string, int>}|null */
    public ?array $lastScan = null;

    /** Language key of the title of the page */
    protected string $title = 'COM_MEDIAAUDIT';

    /** Folder of the template below tmpl/, for views that share one */
    protected string $template = '';

    /** Name of the list for the export */
    protected string $export = '';

    public function display($tpl = null): void
    {
        $model = $this->getModel();

        $this->items         = $model->getItems();
        $this->pagination    = $model->getPagination();
        $this->state         = $model->getState();
        $this->filterForm    = $model->getFilterForm();
        $this->activeFilters = $model->getActiveFilters();

        if (\count($errors = $model->getErrors())) {
            throw new GenericDataException(implode("\n", $errors), 500);
        }

        $db      = Factory::getContainer()->get(DatabaseInterface::class);
        $history = new ScanHistory($db);
        $params  = ComponentHelper::getParams('com_mediaaudit');
        $service = new QuarantineService($db, JPATH_ROOT, $history, max(0, (int) $params->get('quarantine_scan_age', 24)));

        $this->lastScan      = $history->last();
        $this->scanFresh     = $service->isScanFresh();
        $this->maxScanAge    = $service->getMaxScanAge();
        $this->canQuarantine = $this->getCurrentUser()->authorise('mediaaudit.quarantine', 'com_mediaaudit');
        $this->canDelete     = $this->getCurrentUser()->authorise('mediaaudit.delete', 'com_mediaaudit');

        $this->deleteAfterDays = max(1, (int) $params->get('quarantine_delete_days', 30));

        if ($this->template !== '') {
            $this->addTemplatePath(JPATH_ADMINISTRATOR . '/components/com_mediaaudit/tmpl/' . $this->template);
        }

        $this->getDocument()->addStyleDeclaration(HtmlHelper::styles());

        $this->addToolbar();

        parent::display($tpl);
    }

    protected function addToolbar(): void
    {
        ToolbarHelper::title(Text::_('COM_MEDIAAUDIT') . ': ' . Text::_($this->title), 'images');

        $this->addActions();

        ToolbarHelper::custom('scan.run', 'refresh', '', 'COM_MEDIAAUDIT_TOOLBAR_SCAN', false);

        if ($this->export !== '') {
            ToolbarHelper::link(
                Route::_('index.php?option=com_mediaaudit&task=export.csv&type=' . $this->export . '&' . Session::getFormToken() . '=1', false),
                'COM_MEDIAAUDIT_TOOLBAR_EXPORT',
                'download'
            );
        }

        if ($this->getCurrentUser()->authorise('core.admin', 'com_mediaaudit')) {
            ToolbarHelper::preferences('com_mediaaudit');
        }
    }

    /**
     * The buttons that act on the selection.
     */
    protected function addActions(): void
    {
    }

    /**
     * The warning that files cannot be moved before a new scan, or nothing.
     */
    public function scanNotice(): string
    {
        if ($this->scanFresh || !$this->canQuarantine || !$this->selectable) {
            return '';
        }

        return '<div class="alert alert-warning">' . Text::sprintf(
            $this->lastScan === null ? 'COM_MEDIAAUDIT_NOTICE_NO_SCAN' : 'COM_MEDIAAUDIT_NOTICE_SCAN_OLD',
            $this->maxScanAge
        ) . '</div>';
    }
}
