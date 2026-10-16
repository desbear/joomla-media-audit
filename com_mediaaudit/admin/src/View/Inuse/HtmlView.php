<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\View\Inuse;

use Gss\Component\Mediaaudit\Administrator\View\ListView;

\defined('_JEXEC') or die;

/**
 * The images that published content refers to. A list to look at: nothing of it is to be moved.
 */
class HtmlView extends ListView
{
    protected string $title = 'COM_MEDIAAUDIT_VIEW_INUSE';

    protected string $template = 'files';

    protected string $export = 'inuse';

    public bool $selectable = false;
}
