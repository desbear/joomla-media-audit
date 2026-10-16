<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\View\Broken;

use Gss\Component\Mediaaudit\Administrator\View\ListView;
use Joomla\CMS\Toolbar\ToolbarHelper;

\defined('_JEXEC') or die;

class HtmlView extends ListView
{
    protected string $title = 'COM_MEDIAAUDIT_VIEW_BROKEN';

    protected string $template = '';

    protected string $export = 'broken';

    protected function addActions(): void
    {
        // Nothing to select: what is broken is mended in the content
    }
}
