<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Service;

use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Writes to the User Actions Log of Joomla.
 *
 * Straight to the table, in the form that com_actionlogs reads: the model of com_actionlogs needs an
 * application with a user, which the command line and the scheduled task do not have.
 */
final class ActionLogger
{
    public const EXTENSION = 'com_mediaaudit';

    public function __construct(private DatabaseInterface $db)
    {
    }

    /**
     * @param  string                $key       Language key of com_mediaaudit, with {username}, {path}
     *                                          and whatever else the values hold.
     * @param  array<string, mixed>  $values
     */
    public function log(string $key, array $values, int $userId, string $username = ''): void
    {
        try {
            $row = (object) [
                'message_language_key' => $key,
                'message'              => json_encode($values + [
                    'userid'      => $userId,
                    'username'    => $username,
                    'accountlink' => 'index.php?option=com_users&task=user.edit&id=' . $userId,
                ]),
                'log_date'             => gmdate('Y-m-d H:i:s'),
                'extension'            => self::EXTENSION,
                'user_id'              => $userId,
                'item_id'              => (int) ($values['id'] ?? 0),
                'ip_address'           => 'COM_ACTIONLOGS_DISABLED',
            ];

            $this->db->insertObject('#__action_logs', $row);
        } catch (\Throwable $e) {
            // The log is a record of what was done, not a condition for doing it
        }
    }
}
