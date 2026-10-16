<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\View\Rewrites;

use Gss\Component\Mediaaudit\Administrator\View\ListView;

\defined('_JEXEC') or die;

/**
 * Only to look at: what was changed is put back by mediaaudit:localize --revert.
 */
class HtmlView extends ListView
{
    protected string $title = 'COM_MEDIAAUDIT_VIEW_REWRITES';

    protected string $export = 'rewrites';

}
