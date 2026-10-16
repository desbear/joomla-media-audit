<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Localizer;

use Gss\Component\Mediaaudit\Administrator\Scanner\ExternalChecker;
use Gss\Component\Mediaaudit\Administrator\Scanner\UrlTable;
use Gss\Component\Mediaaudit\Administrator\Scanner\FileScanner;
use Gss\Component\Mediaaudit\Administrator\Service\FilePlacer;
use Gss\Component\Mediaaudit\Administrator\Service\HttpFetcher;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Copies the images that the content loads from other sites to the images folder of this site.
 *
 * Only adds files. A file that is there is never overwritten and never removed.
 */
final class ImageLocalizer
{
    public const DEFAULT_FOLDER = 'images/localized';

    /** Types that are stored, by what the content of the file is. No SVG: it can carry scripts. */
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];

    private string $root;

    private string $tmp;

    public function __construct(private DatabaseInterface $db, private HttpFetcher $http, string $root, string $tmp)
    {
        $this->root = rtrim($root, '/');
        $this->tmp  = rtrim($tmp, '/');
    }

    /**
     * @param   string[]       $hosts     Only images of these hosts. Empty for all.
     * @param   string         $folder    Below images/, relative to the root of the site.
     * @param   int            $limit     At most this many downloads. 0 for all.
     * @param   bool           $dryRun    Only count.
     * @param   int            $delay     Milliseconds between two downloads.
     * @param   callable|null  $progress  function (int $done, int $total, string $url, string $result): void
     * @param   int            $retryDays  Leave out what failed in the last days. 0 to try all again.
     * @param   float          $maxSeconds  Stop when the run has taken this long; the rest waits for
     *                                      the next run. 0 for no limit.
     *
     * @return  array{urls: int, downloaded: int, reused: int, failed: int, bytes: int, seconds: float, left: int}
     */
    public function download(
        array $hosts = [],
        string $folder = self::DEFAULT_FOLDER,
        int $limit = 0,
        bool $dryRun = false,
        int $delay = 100,
        ?callable $progress = null,
        int $retryDays = 7,
        float $maxSeconds = 0.0
    ): array {
        $folder = self::checkFolder($folder);
        $start  = microtime(true);
        $urls   = $this->pending($hosts, $limit, $retryDays);
        $stats  = ['urls' => \count($urls), 'downloaded' => 0, 'reused' => 0, 'failed' => 0, 'bytes' => 0, 'seconds' => 0.0, 'left' => \count($urls)];
        $done   = 0;

        if ($dryRun) {
            return $stats;
        }

        foreach ($urls as $url => $hash) {
            if ($maxSeconds > 0 && microtime(true) - $start >= $maxSeconds) {
                break;
            }

            // Hosts that were named can be on the local network; "every host" cannot
            $result = $this->fetch($url, $hash, $folder, 30, (bool) $hosts);

            $stats[$result['result']]++;
            $stats['bytes'] += $result['bytes'];

            $done++;

            if ($progress !== null) {
                $progress($done, $stats['urls'], $url, $result['result'] === 'failed' ? $result['error'] : $result['path']);
            }

            if ($delay > 0) {
                usleep($delay * 1000);
            }
        }

        $stats['seconds'] = round(microtime(true) - $start, 2);
        $stats['left']    = $stats['urls'] - $done;

        return $stats;
    }

    /**
     * The local copies that are on disk.
     *
     * @return  array<string, string>  Hash of the URL => path of the copy
     */
    public function getCopies(): array
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName(['url_hash', 'local_path', 'size']))
            ->from($this->db->quoteName('#__mediaaudit_urls'))
            ->where($this->db->quoteName('local_path') . ' IS NOT NULL');

        $copies = [];

        foreach ($this->db->setQuery($query)->loadObjectList() as $row) {
            if ($this->isOurs($row->local_path, $row->size)) {
                $copies[$row->url_hash] = $row->local_path;
            }
        }

        return $copies;
    }

    /**
     * The copy of one URL, if there is one on disk.
     */
    public function copyOf(string $hash): ?string
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName(['local_path', 'size']))
            ->from($this->db->quoteName('#__mediaaudit_urls'))
            ->where($this->db->quoteName('url_hash') . ' = ' . $this->db->quote($hash))
            ->where($this->db->quoteName('local_path') . ' IS NOT NULL');

        $row = $this->db->setQuery($query)->loadObject();

        return $row && $this->isOurs($row->local_path, $row->size) ? $row->local_path : null;
    }

    /**
     * Whether the file at the path is the copy that was stored there. When the copy was moved away
     * and another file took its name, content must not be made to load it: whoever can upload a
     * file could choose what other people's articles show.
     *
     * @param  int|string|null  $size  As it was stored. Null for the copies of before it was.
     */
    private function isOurs(string $path, $size): bool
    {
        $file = $this->root . '/' . $path;

        return is_file($file) && !is_link($file) && ($size === null || (int) filesize($file) === (int) $size);
    }

    /**
     * Downloads one image.
     *
     * @param   string  $url           As the normalizer writes it.
     * @param   string  $hash          Hash of the URL.
     * @param   bool    $allowPrivate  See HttpFetcher::download().
     *
     * @return  array{result: string, path: string, error: string, bytes: int}  The result is
     *                                                                           "downloaded", "reused" or "failed".
     */
    public function fetchOne(string $url, string $hash, string $folder, int $timeout = 30, bool $allowPrivate = false): array
    {
        return $this->fetch($url, $hash, self::checkFolder($folder), $timeout, $allowPrivate);
    }

    /**
     * The folder has to be one below images/, and outside the quarantine.
     */
    public static function checkFolder(string $folder): string
    {
        $folder = trim(str_replace('\\', '/', $folder), '/ ');

        if (
            !preg_match('#^images/[A-Za-z0-9_][A-Za-z0-9_./-]*$#', $folder)
            || preg_match('#(^|/)\.\.?(/|$)#', $folder)
            || str_starts_with(strtolower($folder) . '/', strtolower(FileScanner::QUARANTINE_FOLDER) . '/')
        ) {
            throw new \InvalidArgumentException('The folder has to be below images/: ' . $folder);
        }

        return $folder;
    }

    /**
     * @return  array<string, string>  URL => hash, of what has no copy on disk yet
     */
    private function pending(array $hosts, int $limit, int $retryDays): array
    {
        $db     = $this->db;
        $copies = $this->getCopies() + $this->recentFailures($retryDays);
        $hosts  = array_filter(array_map('strtolower', array_map('trim', $hosts)));
        $query  = $db->getQuery(true)
            ->select('DISTINCT ' . $db->quoteName('path') . ', ' . $db->quoteName('path_hash'))
            ->from($db->quoteName('#__mediaaudit_refs'))
            ->where($db->quoteName('is_external') . ' = 1')
            ->order($db->quoteName('path_hash') . ' ASC');

        $urls = [];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            if (isset($copies[$row->path_hash]) || ($hosts && !\in_array(HttpFetcher::hostOf($row->path), $hosts, true))) {
                continue;
            }

            $urls[$row->path] = $row->path_hash;

            if ($limit > 0 && \count($urls) >= $limit) {
                break;
            }
        }

        return $urls;
    }

    /**
     * What could not be copied lately: an image that is gone does not come back by asking every day.
     *
     * @return  array<string, true>  By the hash of the URL
     */
    private function recentFailures(int $days): array
    {
        if ($days <= 0) {
            return [];
        }

        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('url_hash'))
            ->from($this->db->quoteName('#__mediaaudit_urls'))
            ->where($this->db->quoteName('local_path') . ' IS NULL')
            ->where($this->db->quoteName('error') . ' <> ' . $this->db->quote(''))
            ->where($this->db->quoteName('checked_at') . ' > ' . $this->db->quote(gmdate('Y-m-d H:i:s', time() - $days * 86400)));

        return array_fill_keys($this->db->setQuery($query)->loadColumn(), true);
    }

    /**
     * @return  array{result: string, path: string, error: string, bytes: int}  The result is
     *                                                                           "downloaded", "reused" or "failed".
     */
    private function fetch(string $url, string $hash, string $folder, int $timeout, bool $allowPrivate): array
    {
        $now  = gmdate('Y-m-d H:i:s');
        // A name that nobody can tell beforehand: the folder for temporary files is one that browsers
        // can ask for on many sites, and what another site sends is in this file before it is checked
        $part = $this->tmp . '/mediaaudit-' . $hash . '-' . bin2hex(random_bytes(8)) . '.part';

        try {
            $response = $this->http->download($url, $part, 20971520, $timeout, $allowPrivate);
            $error    = $response['error'];

            if ($error === '' && !ExternalChecker::isOk($response['status'])) {
                $error = 'HTTP ' . $response['status'];
            }

            // By the content of the file, not by what the server calls it
            $mime = $error === '' ? (string) ((@getimagesize($part) ?: [])['mime'] ?? '') : '';

            if ($error === '' && !isset(self::EXTENSIONS[$mime])) {
                $error = 'not an image that can be stored: ' . ($mime ?: $response['mime'] ?: 'unknown type');
            }

            if ($error !== '') {
                UrlTable::upsert($this->db, $url, $hash, [
                    'http_status' => $response['status'],
                    'error'       => mb_substr(mb_scrub($error, 'UTF-8'), 0, 255, 'UTF-8'),
                    'checked_at'  => $now,
                ]);

                return ['result' => 'failed', 'path' => '', 'error' => $error, 'bytes' => 0];
            }

            $sha1   = sha1_file($part);
            $size   = (int) filesize($part);
            $reused = $this->findCopy($sha1);
            $path   = $reused ?? $this->store($part, $folder . '/' . FileNamer::pathOf($url, self::EXTENSIONS[$mime]), $sha1);

            UrlTable::upsert($this->db, $url, $hash, [
                'http_status' => $response['status'],
                'error'       => '',
                'checked_at'  => $now,
                'local_path'  => $path,
                'sha1'        => $sha1,
                'size'        => $size,
                'mime'        => $mime,
                'fetched_at'  => $now,
            ]);

            return ['result' => $reused === null ? 'downloaded' : 'reused', 'path' => $path, 'error' => '', 'bytes' => $reused === null ? $size : 0];
        } finally {
            // Our own file in the temporary folder
            if (is_file($part)) {
                @unlink($part);
            }
        }
    }

    /**
     * A copy with the same content, made for another URL.
     */
    private function findCopy(string $sha1): ?string
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('local_path'))
            ->from($this->db->quoteName('#__mediaaudit_urls'))
            ->where($this->db->quoteName('sha1') . ' = ' . $this->db->quote($sha1))
            ->where($this->db->quoteName('local_path') . ' IS NOT NULL');

        foreach ($this->db->setQuery($query)->loadColumn() as $path) {
            if (is_file($this->root . '/' . $path) && sha1_file($this->root . '/' . $path) === $sha1) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Puts the download in its place, under another name when the name is taken by another image.
     */
    private function store(string $part, string $path, string $sha1): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $base      = substr($path, 0, -\strlen($extension) - 1);

        $folder = \dirname($this->root . '/' . $path);

        if (!is_dir($folder) && !@mkdir($folder, 0755, true) && !is_dir($folder)) {
            throw new \RuntimeException('Cannot create ' . \dirname($path));
        }

        for ($i = 1; $i < 1000; $i++) {
            $target = $this->root . '/' . $path;

            // The same image under this name, stored before: that one is the copy
            if (is_file($target) && !is_link($target) && sha1_file($target) === $sha1) {
                return $path;
            }

            if (!file_exists($target) && !is_link($target) && FilePlacer::place($part, $target)) {
                return $path;
            }

            $path = $base . '-' . ($i + 1) . '.' . $extension;
        }

        throw new \RuntimeException('Cannot write ' . $path);
    }
}
