<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\View\Dashboard;

use Gss\Component\Mediaaudit\Administrator\Helper\ChartHelper;
use Gss\Component\Mediaaudit\Administrator\Helper\HtmlHelper;
use Joomla\CMS\HTML\HTMLHelper as JHtml;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

\defined('_JEXEC') or die;

/**
 * The first page: the numbers of the last scan, and the way to the lists.
 */
class HtmlView extends BaseHtmlView
{
    /** @var array<string, int> */
    public array $summary = [];

    public ?array $lastScan = null;

    public ?array $previousScan = null;

    public array $hosts = [];

    public int $quarantined = 0;

    /** @var array<string, int>  Hosts that are most likely this site, and are not listed as it */
    public array $ownHosts = [];

    /** @var array{copies: int, files: int, bytes: int, failed: int, rewritten: int} */
    public array $localized = [];

    /** @var list<array{scanned_at: string, seconds: float, source: string, summary: array<string, int>}> */
    public array $history = [];

    /** @var array<string, array{references: int, sources: int, published: int}> */
    public array $sources = [];

    /** @var list<array{host: string, copied: int, failed: int, waiting: int}> */
    public array $externalByHost = [];

    public function display($tpl = null): void
    {
        $model = $this->getModel();

        $this->summary      = $model->getSummary();
        $this->lastScan     = $model->getLastScan();
        $this->previousScan = $model->getPreviousScan();
        $this->hosts        = $model->getExternalHosts();
        $this->quarantined  = $model->getQuarantined();
        $this->ownHosts     = $model->getLikelyOwnHosts();
        $this->localized    = $model->getLocalized();
        $this->history      = $model->getHistory();
        $this->sources      = $model->getSources();

        $this->externalByHost = $model->getExternalByHost();

        $document = $this->getDocument();

        $document->addStyleDeclaration(HtmlHelper::styles() . ChartHelper::styles());
        $document->getWebAssetManager()->addInlineScript(ChartHelper::script());

        ToolbarHelper::title(Text::_('COM_MEDIAAUDIT'), 'images');
        ToolbarHelper::custom('scan.run', 'refresh', '', 'COM_MEDIAAUDIT_TOOLBAR_SCAN', false);

        if ($this->getCurrentUser()->authorise('core.admin', 'com_mediaaudit')) {
            ToolbarHelper::preferences('com_mediaaudit');
        }

        parent::display($tpl);
    }

    /**
     * The change of a number since the scan before the last. Null when there was none before.
     */
    public function change(string $key): ?int
    {
        if ($this->lastScan === null || $this->previousScan === null || !isset($this->previousScan['summary'][$key])) {
            return null;
        }

        return (int) ($this->lastScan['summary'][$key] ?? 0) - (int) $this->previousScan['summary'][$key];
    }

    /**
     * The course of a number over the last scans.
     *
     * @return  list<array{label: string, value: int}>
     */
    public function course(string $key): array
    {
        $course = [];

        foreach ($this->history as $scan) {
            if (isset($scan['summary'][$key])) {
                $course[] = [
                    'label' => JHtml::_('date', $scan['scanned_at'], Text::_('DATE_FORMAT_LC5')),
                    'value' => (int) $scan['summary'][$key],
                ];
            }
        }

        return $course;
    }
}
