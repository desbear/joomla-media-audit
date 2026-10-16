<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\View\Quarantine;

use Gss\Component\Mediaaudit\Administrator\View\ListView;
use Joomla\CMS\Toolbar\ToolbarHelper;

\defined('_JEXEC') or die;

class HtmlView extends ListView
{
    protected string $title = 'COM_MEDIAAUDIT_VIEW_QUARANTINE';

    protected string $template = '';

    protected string $export = 'quarantine';

    protected function addActions(): void
    {
        if ($this->canQuarantine) {
            ToolbarHelper::custom('quarantine.restore', 'undo-2', '', 'COM_MEDIAAUDIT_TOOLBAR_RESTORE', true);
        }

        // Leads to the page that asks; nothing is deleted by this button
        if ($this->canDelete) {
            ToolbarHelper::custom('quarantine.confirmDelete', 'trash', '', 'COM_MEDIAAUDIT_TOOLBAR_DELETE', true);
        }
    }
}
