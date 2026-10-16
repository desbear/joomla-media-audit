<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\View\Localized;

use Gss\Component\Mediaaudit\Administrator\Helper\ChartHelper;
use Gss\Component\Mediaaudit\Administrator\View\ListView;
use Joomla\CMS\Toolbar\ToolbarHelper;

\defined('_JEXEC') or die;

/**
 * Copies are made by the button of the toolbar, by the scheduled task, by saving content, and by
 * mediaaudit:localize.
 */
class HtmlView extends ListView
{
    protected string $title = 'COM_MEDIAAUDIT_VIEW_LOCALIZED';

    protected string $export = 'localized';

    /** @var array{copies: int, files: int, bytes: int, failed: int, rewritten: int} */
    public array $totals = [];

    /** @var array{copied: int, waiting: int, failed: int, unchanged: int} */
    public array $progress = [];

    /** @var list<array{at: string|null, by: string, copied: int, waiting: int, failed: int}> */
    public array $course = [];

    public function display($tpl = null): void
    {
        $model = $this->getModel();

        $this->totals   = $model->getTotals();
        $this->progress = $model->getProgress();
        $this->course   = $model->getCourse();

        $document = $this->getDocument();

        $document->addStyleDeclaration(ChartHelper::styles());
        $document->getWebAssetManager()->addInlineScript(ChartHelper::script());

        parent::display($tpl);
    }

    protected function addActions(): void
    {
        if ($this->getCurrentUser()->authorise('mediaaudit.localize', 'com_mediaaudit')) {
            ToolbarHelper::custom('localize.run', 'download', '', 'COM_MEDIAAUDIT_TOOLBAR_LOCALIZE', false);
        }
    }
}
