<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Controller;

use Gss\Component\Mediaaudit\Administrator\Service\HttpFetcher;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

/**
 * Copies external images from the toolbar, a batch at a time.
 *
 * A request of a browser cannot go on for as long as thousands of images take. Every press of the
 * button scans, copies for a while, and rewrites what it has copies for; the answer tells how many
 * images are left.
 */
class LocalizeController extends ActionController
{
    /** Seconds that the downloads of one press may take */
    private const SECONDS = 20.0;

    public function run(): void
    {
        $this->checkToken();
        $this->back('localized');

        if (!$this->allowed('mediaaudit.localize')) {
            return;
        }

        $params = ComponentHelper::getParams('com_mediaaudit');
        $hosts  = preg_split('/[\s,]+/', (string) $params->get('localize_hosts', ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (!$hosts) {
            $this->app->enqueueMessage(Text::_('COM_MEDIAAUDIT_LOCALIZE_NO_HOSTS'), 'warning');

            return;
        }

        if (!HttpFetcher::isAvailable()) {
            $this->app->enqueueMessage(Text::_('COM_MEDIAAUDIT_LOCALIZE_NO_CURL'), 'error');

            return;
        }

        try {
            if (\function_exists('set_time_limit')) {
                @set_time_limit(120);
            }

            $runner = $this->runner();
            $hash   = (bool) $params->get('hash_files', 1);
            $named  = \in_array('*', $hosts, true) ? [] : $hosts;

            // What the content loads from other sites, as it is now
            $scan = $runner->run($hash);

            $copied = $runner->getLocalizer()->download(
                $named,
                (string) $params->get('localize_folder', 'images/localized'),
                0,
                false,
                200,
                null,
                7,
                self::SECONDS
            );

            $runner->refresh(true, $hash);

            $rewritten = $runner->getRewriter()->rewrite($named, false, (int) $this->app->getIdentity()->id);

            $runner->refresh(false);
            $runner->getHistory()->record('admin', (float) $scan['seconds'], $runner->getReport()->summary());

            $this->app->enqueueMessage(Text::sprintf(
                'COM_MEDIAAUDIT_LOCALIZE_DONE',
                number_format($copied['downloaded'] + $copied['reused']),
                number_format($copied['failed']),
                number_format($rewritten['rewritten']),
                number_format($rewritten['replaced'])
            ), 'message');

            if ($copied['left'] > 0) {
                $this->app->enqueueMessage(Text::sprintf('COM_MEDIAAUDIT_LOCALIZE_LEFT', number_format($copied['left'])), 'notice');
            }
        } catch (\Throwable $e) {
            $this->app->enqueueMessage(Text::sprintf('COM_MEDIAAUDIT_LOCALIZE_FAILED', htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')), 'error');
        }
    }
}
