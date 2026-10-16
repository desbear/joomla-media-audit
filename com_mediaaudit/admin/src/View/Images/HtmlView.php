<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\View\Images;

use Gss\Component\Mediaaudit\Administrator\Helper\ChartHelper;
use Gss\Component\Mediaaudit\Administrator\Helper\WaitDialog;
use Gss\Component\Mediaaudit\Administrator\View\ListView;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Toolbar\ToolbarHelper;

\defined('_JEXEC') or die;

/**
 * Every image of the site by type, and the place to convert them from: the selected ones, or the
 * next batch of the ones that content refers to.
 */
class HtmlView extends ListView
{
    /** The most images that one batch can be asked to take */
    public const MOST = 200;

    protected string $title = 'COM_MEDIAAUDIT_VIEW_IMAGES';

    protected string $export = 'images';

    /** @var list<array{type: string, files: int, bytes: int, used: int, used_bytes: int}> */
    public array $types = [];

    /** @var array{waiting: int, converted: int, not_smaller: int, failed: int, bytes_before: int, bytes_after: int} */
    public array $totals = [];

    /** @var array<string, string[]> */
    public array $capabilities = [];

    /** "avif", "webp", or nothing when converting is off */
    public string $format = '';

    public int $quality = 0;

    public int $batch = 20;

    public bool $canConvert = false;

    public function display($tpl = null): void
    {
        $model  = $this->getModel();
        $params = ComponentHelper::getParams('com_mediaaudit');

        $this->types        = $model->getTypes();
        $this->totals       = $model->getTotals();
        $this->capabilities = $model->getCapabilities();
        $this->format       = $model->getFormat();
        $this->quality      = (int) $params->get('convert_quality_' . ($this->format ?: 'avif'), $this->format === 'webp' ? 82 : 60);
        $this->batch        = max(1, min(self::MOST, (int) $params->get('convert_batch', 20)));

        $this->canConvert = $this->format !== ''
            && !empty($this->capabilities[$this->format])
            && $this->getCurrentUser()->authorise('mediaaudit.convert', 'com_mediaaudit');

        $document = $this->getDocument();

        $document->addStyleDeclaration(ChartHelper::styles() . WaitDialog::styles());
        $document->getWebAssetManager()->addInlineScript(ChartHelper::script());

        WaitDialog::add($document, ['convert.run', 'convert.selected']);

        parent::display($tpl);
    }

    protected function addActions(): void
    {
        if ($this->canConvert) {
            ToolbarHelper::custom('convert.selected', 'images', '', 'COM_MEDIAAUDIT_TOOLBAR_CONVERT_SELECTED', true);
            ToolbarHelper::custom('convert.run', 'play', '', 'COM_MEDIAAUDIT_TOOLBAR_CONVERT_NEXT', false);
        }
    }
}
