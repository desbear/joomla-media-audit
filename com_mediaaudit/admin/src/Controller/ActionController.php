<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Controller;

use Gss\Component\Mediaaudit\Administrator\Scanner\ScanRunner;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * What the controllers of the actions share.
 */
abstract class ActionController extends BaseController
{
    protected const VIEWS = ['dashboard', 'images', 'inuse', 'orphans', 'semiorphans', 'broken', 'duplicates', 'quarantine', 'localized', 'rewrites', 'deleteconfirm'];

    protected function runner(): ScanRunner
    {
        return ScanRunner::create(
            Factory::getContainer()->get(DatabaseInterface::class),
            ComponentHelper::getParams('com_mediaaudit'),
            JPATH_ROOT,
            $this->app->getDispatcher(),
            (string) $this->app->get('tmp_path', sys_get_temp_dir())
        );
    }

    /**
     * Back to the list that the action came from.
     */
    protected function back(string $default = 'dashboard'): void
    {
        $view = $this->input->getCmd('return_view', $default);

        $this->setRedirect(Route::_('index.php?option=com_mediaaudit&view=' . (\in_array($view, self::VIEWS, true) ? $view : $default), false));
    }

    protected function allowed(string $action): bool
    {
        if ($this->app->getIdentity()->authorise($action, 'com_mediaaudit')) {
            return true;
        }

        $this->app->enqueueMessage(Text::_('JERROR_ALERTNOAUTHOR'), 'error');

        return false;
    }

    /**
     * @param  list<array{path: string, reason: string, detail: string}>  $refused
     */
    protected function explain(array $refused): void
    {
        $byReason = [];

        foreach ($refused as $entry) {
            $byReason[$entry['reason']][] = $entry;
        }

        foreach ($byReason as $reason => $entries) {
            $paths = array_map(static fn (array $entry): string => htmlspecialchars($entry['path'], ENT_QUOTES, 'UTF-8'), \array_slice($entries, 0, 10));

            if (\count($entries) > 10) {
                $paths[] = Text::sprintf('COM_MEDIAAUDIT_AND_MORE', \count($entries) - 10);
            }

            $this->app->enqueueMessage(
                Text::sprintf('COM_MEDIAAUDIT_REFUSED_' . strtoupper($reason), \count($entries), htmlspecialchars((string) $entries[0]['detail'], ENT_QUOTES, 'UTF-8'))
                . '<br>' . implode('<br>', $paths),
                'warning'
            );
        }
    }
}
