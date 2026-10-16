<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Controller;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

/**
 * The scan, from the toolbar.
 */
class ScanController extends ActionController
{
    public function run(): void
    {
        $this->checkToken();
        $this->back();

        if (!$this->allowed('core.manage')) {
            return;
        }

        try {
            // A large site takes longer than a request is given by default
            if (\function_exists('set_time_limit')) {
                @set_time_limit(0);
            }

            $runner = $this->runner();
            $scan   = $runner->run((bool) ComponentHelper::getParams('com_mediaaudit')->get('hash_files', 1));

            $runner->getHistory()->record('admin', (float) $scan['seconds'], $runner->getReport()->summary());

            $this->app->enqueueMessage(Text::sprintf(
                'COM_MEDIAAUDIT_SCAN_DONE',
                $scan['seconds'],
                number_format($scan['files']['found']),
                number_format(array_sum(array_column($scan['sources'], 'references')))
            ), 'message');
        } catch (\Throwable $e) {
            $this->app->enqueueMessage(Text::sprintf('COM_MEDIAAUDIT_SCAN_FAILED', htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')), 'error');
        }
    }
}
