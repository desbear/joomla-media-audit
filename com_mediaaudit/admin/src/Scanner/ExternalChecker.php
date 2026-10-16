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
 * Asks the other sites whether the images that the content loads from them are still there.
 *
 * The answers are kept in #__mediaaudit_urls, which a scan does not empty, and copied to the
 * references from there.
 */
final class ExternalChecker
{
    /**
     * @param  string[]  $privateHosts  Hosts that may be of the local network: the ones that an
     *                                  administrator named, to copy their images.
     */
    public function __construct(private DatabaseInterface $db, private HttpFetcher $http, private array $privateHosts = [])
    {
    }

    /**
     * @param   string[]       $hosts        Only URLs of these hosts. Empty for all.
     * @param   int            $limit        At most this many URLs. 0 for all.
     * @param   int            $recheckDays  Leave out what was checked in the last days. 0 to check all.
     * @param   callable|null  $progress     function (int $done, int $total, string $url, int $status): void
     *
     * @return  array{urls: int, checked: int, ok: int, broken: int, unreachable: int, seconds: float}
     */
    public function check(
        array $hosts = [],
        int $limit = 0,
        int $recheckDays = 0,
        int $concurrency = 5,
        int $timeout = 5,
        int $hostDelay = 200,
        ?callable $progress = null
    ): array {
        $start = microtime(true);
        $urls  = $this->pending($hosts, $limit, $recheckDays);
        $stats = ['urls' => \count($urls), 'checked' => 0, 'ok' => 0, 'broken' => 0, 'unreachable' => 0, 'seconds' => 0.0];

        $this->http->check(
            array_keys($urls),
            function (string $url, int $status, string $error, string $mime) use (&$stats, $urls, $progress): void {
                // A login page or an error page that is sent with status 200
                if ($error === '' && self::isOk($status) && !self::canBeImage($mime)) {
                    $error = 'not an image: ' . $mime;
                }

                $this->remember($url, $urls[$url], $status, $error);

                $stats['checked']++;
                $stats[$status === 0 ? 'unreachable' : (self::isOk($status) && $error === '' ? 'ok' : 'broken')]++;

                if ($progress !== null) {
                    $progress($stats['checked'], $stats['urls'], $url, $status);
                }
            },
            $concurrency,
            $timeout,
            $hostDelay,
            $this->privateHosts
        );

        $this->copyToReferences();

        $stats['seconds'] = round(microtime(true) - $start, 2);

        return $stats;
    }

    /**
     * Copies what is known of the URLs to the references. A scan writes the references anew, without
     * the answers.
     */
    public function copyToReferences(): void
    {
        $db = $this->db;

        $db->setQuery(
            'UPDATE ' . $db->quoteName('#__mediaaudit_refs', 'r')
            . ' INNER JOIN ' . $db->quoteName('#__mediaaudit_urls', 'u')
            . ' ON ' . $db->quoteName('u.url_hash') . ' = ' . $db->quoteName('r.path_hash')
            . ' SET ' . $db->quoteName('r.http_status') . ' = ' . $db->quoteName('u.http_status') . ', '
            . $db->quoteName('r.checked_at') . ' = ' . $db->quoteName('u.checked_at')
            . ' WHERE ' . $db->quoteName('r.is_external') . ' = 1'
            . ' AND ' . $db->quoteName('u.checked_at') . ' IS NOT NULL'
        )->execute();
    }

    public static function isOk(int $status): bool
    {
        return $status >= 200 && $status < 300;
    }

    /**
     * Whether what is sent with this type can be an image. Servers that do not know the type of a
     * file send none, or "application/octet-stream": that is no reason to call the image broken.
     */
    public static function canBeImage(string $mime): bool
    {
        return !str_starts_with($mime, 'text/')
            && !\in_array($mime, ['application/json', 'application/xml', 'application/xhtml+xml', 'application/javascript'], true);
    }

    /**
     * @return  array<string, string>  URL => hash
     */
    private function pending(array $hosts, int $limit, int $recheckDays): array
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select('DISTINCT ' . $db->quoteName('r.path') . ', ' . $db->quoteName('r.path_hash'))
            ->from($db->quoteName('#__mediaaudit_refs', 'r'))
            ->where($db->quoteName('r.is_external') . ' = 1')
            ->order($db->quoteName('r.path_hash') . ' ASC');

        if ($recheckDays > 0) {
            $since = gmdate('Y-m-d H:i:s', time() - $recheckDays * 86400);

            $query->join('LEFT', $db->quoteName('#__mediaaudit_urls', 'u'), $db->quoteName('u.url_hash') . ' = ' . $db->quoteName('r.path_hash'))
                ->where('(' . $db->quoteName('u.checked_at') . ' IS NULL OR ' . $db->quoteName('u.checked_at') . ' < ' . $db->quote($since) . ')');
        }

        $hosts = array_filter(array_map('strtolower', array_map('trim', $hosts)));
        $urls  = [];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            if ($hosts && !\in_array(HttpFetcher::hostOf($row->path), $hosts, true)) {
                continue;
            }

            $urls[$row->path] = $row->path_hash;

            if ($limit > 0 && \count($urls) >= $limit) {
                break;
            }
        }

        return $urls;
    }

    private function remember(string $url, string $hash, int $status, string $error): void
    {
        UrlTable::upsert($this->db, $url, $hash, [
            'http_status' => $status,
            // What another server calls its content is text that it chose
            'error'       => mb_substr(mb_scrub($error, 'UTF-8'), 0, 255, 'UTF-8'),
            'checked_at'  => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
