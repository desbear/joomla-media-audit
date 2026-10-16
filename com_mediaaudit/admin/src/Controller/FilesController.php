<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Controller;

use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

/**
 * Actions on the images of the lists.
 */
class FilesController extends ActionController
{
    /**
     * Moves the selected images to the quarantine.
     */
    public function quarantine(): void
    {
        $this->checkToken();
        $this->back('orphans');

        if (!$this->allowed('mediaaudit.quarantine')) {
            return;
        }

        $ids   = (array) $this->input->get('cid', [], 'int');
        $paths = $this->getModel('Orphans', 'Administrator', ['ignore_request' => true])->getPaths($ids);

        if (!$paths) {
            $this->app->enqueueMessage(Text::_('JGLOBAL_NO_ITEM_SELECTED'), 'warning');

            return;
        }

        $user   = $this->app->getIdentity();
        $result = $this->runner()->getQuarantine()->move($paths, (int) $user->id, '', (string) $user->username);

        if ($result['moved']) {
            $this->app->enqueueMessage(Text::plural('COM_MEDIAAUDIT_N_QUARANTINED', \count($result['moved'])), 'message');
        }

        $this->explain($result['refused']);
    }
}
