<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Scanner;

use Gss\Component\Mediaaudit\Administrator\Service\HttpFetcher;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * #__mediaaudit_urls: what is known of an external URL, whatever refers to it.
 */
final class UrlTable
{
    /**
     * Writes the columns of the row of the URL, and adds the row when it is not there.
     *
     * @param  array<string, string|int|null>  $columns
     */
    public static function upsert(DatabaseInterface $db, string $url, string $hash, array $columns): void
    {
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__mediaaudit_urls'))
            ->where($db->quoteName('url_hash') . ' = ' . $db->quote($hash));

        $row = (object) $columns;

        if ($id = (int) $db->setQuery($query)->loadResult()) {
            $row->id = $id;
            $db->updateObject('#__mediaaudit_urls', $row, 'id', true);

            return;
        }

        $row->url      = $url;
        $row->url_hash = $hash;
        $row->host     = mb_substr(HttpFetcher::hostOf($url), 0, 255, 'UTF-8');

        $db->insertObject('#__mediaaudit_urls', $row);
    }
}
