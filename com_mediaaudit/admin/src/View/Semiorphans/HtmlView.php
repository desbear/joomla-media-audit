<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\View\Semiorphans;

use Gss\Component\Mediaaudit\Administrator\View\ListView;
use Joomla\CMS\Toolbar\ToolbarHelper;

\defined('_JEXEC') or die;

class HtmlView extends ListView
{
    protected string $title = 'COM_MEDIAAUDIT_VIEW_SEMIORPHANS';

    protected string $template = 'files';

    protected string $export = 'semiorphans';

    protected function addActions(): void
    {
        if ($this->canQuarantine) {
            ToolbarHelper::custom('files.quarantine', 'archive', '', 'COM_MEDIAAUDIT_TOOLBAR_QUARANTINE', true);
        }
    }
}
