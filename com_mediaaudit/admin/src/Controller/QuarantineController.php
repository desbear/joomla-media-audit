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
use Joomla\CMS\Router\Route;

\defined('_JEXEC') or die;

/**
 * Actions on what is in the quarantine.
 */
class QuarantineController extends ActionController
{
    /** Where the selection waits between the list and the page that asks again */
    public const STATE = 'com_mediaaudit.delete.ids';

    /**
     * Puts the selected images back where they were.
     */
    public function restore(): void
    {
        $this->checkToken();
        $this->back('quarantine');

        if (!$this->allowed('mediaaudit.quarantine')) {
            return;
        }

        $ids = (array) $this->input->get('cid', [], 'int');

        if (!$ids) {
            $this->app->enqueueMessage(Text::_('JGLOBAL_NO_ITEM_SELECTED'), 'warning');

            return;
        }

        $user   = $this->app->getIdentity();
        $result = $this->runner()->getQuarantine()->restore($ids, (int) $user->id, (string) $user->username);

        if ($result['restored']) {
            $this->app->enqueueMessage(Text::plural('COM_MEDIAAUDIT_N_RESTORED', \count($result['restored'])), 'message');
        }

        $this->explain($result['refused']);
    }

    /**
     * The first of the two steps of deleting: shows what would be deleted, and asks.
     */
    public function confirmDelete(): void
    {
        $this->checkToken();
        $this->back('quarantine');

        if (!$this->allowed('mediaaudit.delete')) {
            return;
        }

        $ids = array_values(array_filter(array_map('intval', (array) $this->input->get('cid', [], 'int'))));

        if (!$ids) {
            $this->app->enqueueMessage(Text::_('JGLOBAL_NO_ITEM_SELECTED'), 'warning');

            return;
        }

        $this->app->setUserState(self::STATE, $ids);
        $this->setRedirect(Route::_('index.php?option=com_mediaaudit&view=deleteconfirm', false));
    }

    /**
     * The second step. Only for records that this user selected for the page that asks, and only
     * once. The page says which of them can be deleted; what is deleted is decided here, by checking
     * every record again.
     */
    public function delete(): void
    {
        $this->checkToken();
        $this->back('quarantine');

        if (!$this->allowed('mediaaudit.delete')) {
            return;
        }

        $shown = array_map('intval', (array) $this->app->getUserState(self::STATE, []));
        $ids   = array_values(array_filter(array_map('intval', (array) $this->input->get('cid', [], 'int'))));

        // Asked for once, used once
        $this->app->setUserState(self::STATE, null);

        if (!$this->input->getBool('confirmed') || !$ids || array_diff($ids, $shown)) {
            $this->app->enqueueMessage(Text::_('COM_MEDIAAUDIT_DELETE_NOT_CONFIRMED'), 'error');

            return;
        }

        $user   = $this->app->getIdentity();
        $result = $this->runner()->getQuarantine()->delete($ids, (int) $user->id, (string) $user->username);

        if ($result['deleted']) {
            $this->app->enqueueMessage(
                Text::plural('COM_MEDIAAUDIT_N_DELETED', \count($result['deleted']))
                . ' ' . Text::sprintf('COM_MEDIAAUDIT_DELETED_BYTES', number_format(array_sum(array_column($result['deleted'], 'bytes')) / 1048576, 1)),
                'message'
            );
        }

        // In the words of the page that asked
        $byReason = [];

        foreach ($result['refused'] as $entry) {
            $byReason[$entry['reason']][] = $entry;
        }

        $days = $this->runner()->getQuarantine()->getDeleteAfterDays();

        foreach ($byReason as $reason => $entries) {
            $paths = array_map(static fn (array $entry): string => htmlspecialchars($entry['path'], ENT_QUOTES, 'UTF-8'), \array_slice($entries, 0, 10));

            if (\count($entries) > 10) {
                $paths[] = Text::sprintf('COM_MEDIAAUDIT_AND_MORE', \count($entries) - 10);
            }

            $why = $reason === 'too_recent'
                ? Text::sprintf('COM_MEDIAAUDIT_NOT_DELETED_TOO_RECENT', $days)
                : Text::sprintf('COM_MEDIAAUDIT_WHY_' . strtoupper($reason), htmlspecialchars($entries[0]['detail'], ENT_QUOTES, 'UTF-8'));

            $this->app->enqueueMessage(
                Text::plural('COM_MEDIAAUDIT_N_NOT_DELETED', \count($entries)) . ' ' . $why . '<br>' . implode('<br>', $paths),
                'warning'
            );
        }
    }

    /**
     * Back to the list without deleting.
     */
    public function cancelDelete(): void
    {
        $this->checkToken();

        $this->app->setUserState(self::STATE, null);
        $this->setRedirect(Route::_('index.php?option=com_mediaaudit&view=quarantine', false));
    }
}
