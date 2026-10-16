<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Service;

\defined('_JEXEC') or die;

/**
 * Asks other sites whether an image is there, and downloads images. Only http and https.
 *
 * The URLs come from content, which authors write: the server must not be made to ask what only
 * it can reach. Every request goes to an address that was looked up and found to be a public one,
 * and to that address only: the one who connects does not look the name up a second time, which
 * could get another answer. Redirects are followed here, one at a time, each checked like the
 * first URL. Hosts that an administrator named may be of the local network; what they redirect to
 * may not, unless it is the same host.
 */
final class HttpFetcher
{
    public const REFUSED          = 'host of the local network';
    public const REFUSED_REDIRECT = 'redirected to the local network';

    private const USER_AGENT    = 'MediaAudit/0.3 (Joomla; image audit)';
    private const MAX_REDIRECTS = 5;

    /** Ranges that the filters of PHP take for public, and that are not: shared, benchmarking, multicast */
    private const NOT_PUBLIC = ['100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15', '224.0.0.0/4'];

    public static function isAvailable(): bool
    {
        return \function_exists('curl_multi_init');
    }

    /**
     * Checks the URLs with HEAD, and with a GET of the first byte where HEAD is refused.
     *
     * Requests to one host never run side by side, and wait for each other.
     *
     * @param  string[]  $urls
     * @param  callable  $onResult      function (string $url, int $status, string $error, string $mime): void.
     *                                  The status is 0 when there was no answer. The type is the one
     *                                  that the server names, in lower case, without parameters.
     * @param  int       $concurrency   Requests at the same time, over all the hosts.
     * @param  int       $timeout       Seconds for one request.
     * @param  int       $hostDelay     Milliseconds between two requests to the same host.
     * @param  string[]  $privateHosts  Hosts that may be of the local network: the ones that an
     *                                  administrator named.
     */
    public function check(array $urls, callable $onResult, int $concurrency = 5, int $timeout = 5, int $hostDelay = 200, array $privateHosts = []): void
    {
        $queues       = [];
        $privateHosts = array_map('strtolower', $privateHosts);

        foreach (array_unique($urls) as $url) {
            // "at" is where the request goes: the URL, or what it was redirected to
            $queues[self::hostOf($url)][] = ['url' => $url, 'at' => $url, 'method' => 'HEAD', 'hops' => 0];
        }

        $multi  = curl_multi_init();
        $active = [];
        $busy   = [];
        $nextAt = [];

        try {
            while ($queues || $active) {
                foreach (array_keys($queues) as $host) {
                    if (\count($active) >= max(1, $concurrency)) {
                        break;
                    }

                    if (isset($busy[$host]) || microtime(true) < ($nextAt[$host] ?? 0.0)) {
                        continue;
                    }

                    $job = array_shift($queues[$host]);

                    if (!$queues[$host]) {
                        unset($queues[$host]);
                    }

                    $private = \in_array(self::hostOf($job['url']), $privateHosts, true) && self::hostOf($job['at']) === self::hostOf($job['url']);
                    $handle  = $this->handle($job['at'], $timeout, $private);

                    if ($handle === null) {
                        $onResult($job['url'], 0, $job['hops'] === 0 ? self::REFUSED : self::REFUSED_REDIRECT, '');

                        continue;
                    }

                    if ($job['method'] === 'HEAD') {
                        curl_setopt($handle, CURLOPT_NOBODY, true);
                    } else {
                        // The first byte is enough; servers that ignore the range are cut off
                        curl_setopt($handle, CURLOPT_RANGE, '0-0');
                        curl_setopt($handle, CURLOPT_WRITEFUNCTION, static fn ($handle, string $data): int => 0);
                    }

                    curl_multi_add_handle($multi, $handle);

                    $active[spl_object_id($handle)] = $job + ['host' => $host];
                    $busy[$host]                    = true;
                }

                curl_multi_exec($multi, $running);

                while ($info = curl_multi_info_read($multi)) {
                    $handle = $info['handle'];
                    $job    = $active[spl_object_id($handle)];
                    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                    $mime   = strtolower(trim(explode(';', (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE))[0]));
                    $next   = (string) curl_getinfo($handle, CURLINFO_REDIRECT_URL);
                    $errno  = (int) $info['result'];

                    unset($active[spl_object_id($handle)], $busy[$job['host']]);
                    curl_multi_remove_handle($multi, $handle);
                    unset($handle);

                    $nextAt[$job['host']] = microtime(true) + max(0, $hostDelay) / 1000;

                    // Cutting the body off is an error for curl, and an answer for us
                    if ($errno === CURLE_WRITE_ERROR && $status > 0) {
                        $errno = 0;
                    }

                    // Followed here, not by curl: where it leads is checked like the first URL
                    if ($errno === 0 && $status >= 300 && $status < 400 && $next !== '') {
                        if ($job['hops'] >= self::MAX_REDIRECTS) {
                            $onResult($job['url'], 0, 'too many redirects', '');

                            continue;
                        }

                        $queues[self::hostOf($next)][] = ['url' => $job['url'], 'at' => $next, 'method' => $job['method'], 'hops' => $job['hops'] + 1];

                        continue;
                    }

                    if ($job['method'] === 'HEAD' && ($errno !== 0 || $status === 0 || $status >= 400)) {
                        $queues[$job['host']] ??= [];
                        array_unshift($queues[$job['host']], ['url' => $job['url'], 'at' => $job['at'], 'method' => 'GET', 'hops' => $job['hops']]);

                        continue;
                    }

                    $onResult($job['url'], $errno === 0 ? $status : 0, $errno === 0 ? '' : curl_strerror($errno), $errno === 0 ? $mime : '');
                }

                if ($active) {
                    curl_multi_select($multi, 0.2);
                } elseif ($queues) {
                    // Every host that has work left is waiting for its delay
                    usleep(10000);
                }
            }
        } finally {
            foreach ($active as $job) {
                $onResult($job['url'], 0, 'interrupted', '');
            }

            curl_multi_close($multi);
        }
    }

    /**
     * Downloads to a file.
     *
     * @param   int   $maxBytes      The download stops when the file grows larger.
     * @param   bool  $allowPrivate  Allow the host to be of the local network. Only for hosts that
     *                               an administrator has named; never for a URL that an author typed.
     *
     * @return  array{status: int, error: string, size: int, mime: string}  The status is 0 when there
     *                                                                       was no answer.
     */
    public function download(string $url, string $target, int $maxBytes = 20971520, int $timeout = 30, bool $allowPrivate = false): array
    {
        $result   = ['status' => 0, 'error' => '', 'size' => 0, 'mime' => ''];
        $at       = $url;
        $deadline = microtime(true) + max(1, $timeout);

        for ($hop = 0; ; $hop++) {
            // One time for the URL and all its redirects, not one for every request
            $left = (int) ceil($deadline - microtime(true));

            if ($left < 1) {
                $result['error'] = 'took longer than ' . $timeout . ' seconds';

                break;
            }

            // What a host that was named redirects to is a URL like any other, unless it is the host itself
            $handle = $this->handle($at, $left, $allowPrivate && self::hostOf($at) === self::hostOf($url));

            if ($handle === null) {
                $result['error'] = $hop === 0 ? self::REFUSED : self::REFUSED_REDIRECT;

                break;
            }

            // Opened for every request: what a redirect answered with is no part of the image
            $file = @fopen($target, 'wb');

            if ($file === false) {
                unset($handle);

                $result['error'] = 'cannot write the file';

                break;
            }

            // Counted as it is written, after it was unpacked: a few kilobytes of gzip can be gigabytes
            $written = 0;

            curl_setopt($handle, CURLOPT_MAXFILESIZE, $maxBytes);
            curl_setopt(
                $handle,
                CURLOPT_WRITEFUNCTION,
                static function ($handle, string $data) use ($file, $maxBytes, &$written): int {
                    $written += \strlen($data);

                    // Less than was given ends the transfer
                    return $written > $maxBytes ? 0 : (int) fwrite($file, $data);
                }
            );

            $ok     = curl_exec($handle);
            $errno  = curl_errno($handle);
            $status = $errno === 0 ? (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE) : 0;
            $next   = (string) curl_getinfo($handle, CURLINFO_REDIRECT_URL);

            $result['status'] = $status;
            $result['mime']   = strtolower(trim(explode(';', (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE))[0]));

            unset($handle);
            fclose($file);

            if ($ok === false || $errno !== 0) {
                $result['error'] = $written > $maxBytes || $errno === CURLE_FILESIZE_EXCEEDED
                    ? 'larger than ' . $maxBytes . ' bytes'
                    : curl_strerror($errno);

                break;
            }

            if ($status < 300 || $status >= 400 || $next === '') {
                break;
            }

            if ($hop >= self::MAX_REDIRECTS) {
                $result['error']  = 'too many redirects';
                $result['status'] = 0;

                break;
            }

            $at = $next;
        }

        if ($result['error'] !== '' && is_file($target)) {
            // Nothing of what was refused or cut off is kept
            file_put_contents($target, '');
        }

        clearstatcache(true, $target);
        $result['size'] = (int) @filesize($target);

        return $result;
    }

    /**
     * False for hosts that resolve to this machine or to the local network, and for hosts that do
     * not resolve at all.
     */
    public static function isPublicHost(string $host): bool
    {
        $addresses = self::addressesOf($host);

        if (!$addresses) {
            return false;
        }

        foreach ($addresses as $address) {
            if (!self::isPublicAddress($address)) {
                return false;
            }
        }

        return true;
    }

    public static function isPublicAddress(string $address): bool
    {
        $bytes = @inet_pton($address);

        if ($bytes === false) {
            return false;
        }

        // By what the address is, not by how it is written: "0:0:0:0:0:ffff:7f00:1" is 127.0.0.1
        if (\strlen($bytes) === 16) {
            $head = bin2hex(substr($bytes, 0, 12));

            // An IPv4 address in IPv6: mapped, compatible, or translated (64:ff9b::/96)
            if ($head === '00000000000000000000ffff' || $head === '000000000000000000000000' || $head === '0064ff9b0000000000000000') {
                return $head !== '000000000000000000000000' && self::isPublicAddress((string) inet_ntop(substr($bytes, 12)));
            }

            $first = \ord($bytes[0]);

            // Only what is handed out to the world, 2000::/3, without the ranges in it that are not:
            // 6to4 (2002::/16), which holds an IPv4 address, Teredo and documentation
            if (($first & 0xe0) !== 0x20 || str_starts_with($head, '2002') || str_starts_with($head, '20010000') || str_starts_with($head, '20010db8')) {
                return false;
            }

            return true;
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        $number = ip2long($address);

        foreach (self::NOT_PUBLIC as $range) {
            [$network, $bits] = explode('/', $range);

            if (($number & (-1 << (32 - (int) $bits))) === (ip2long($network) & (-1 << (32 - (int) $bits)))) {
                return false;
            }
        }

        return true;
    }

    public static function hostOf(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }

    /**
     * The addresses of a host: itself when it is an address, or what its name resolves to. Only
     * IPv4 for names: that is what the requests are sent to.
     *
     * @return  string[]
     */
    private static function addressesOf(string $host): array
    {
        $host = trim($host, '[]');

        if ($host === '') {
            return [];
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        // Numbers that are no address as it is written: one number, fewer parts, parts in hexadecimal
        // or with a zero in front. Some resolvers read them as addresses, and not all the same way
        if (preg_match('/^(?:0x[0-9a-f]*|\d+)(?:\.(?:0x[0-9a-f]*|\d+)){0,3}\.?$/i', $host)) {
            return [];
        }

        return @gethostbynamel($host) ?: [];
    }

    /**
     * A request that goes to an address that was checked, and nowhere else.
     *
     * @param   bool  $allowPrivate  Whether the host may be of the local network.
     *
     * @return  \CurlHandle|null  Null for a URL that is not asked: no http or https, a host that does
     *                            not resolve, or one of the local network.
     */
    private function handle(string $url, int $timeout, bool $allowPrivate = false)
    {
        $parts  = parse_url(self::encode($url));
        $scheme = \is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host   = \is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';

        if (!\in_array($scheme, ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        // A name or an address, and nothing that two readers of a URL could read in two ways
        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?|\[[0-9a-f:.]+\])$/', $host)) {
            return null;
        }

        // Put together again from its parts: what is asked is the host that was checked
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        // The ports of the web. Another port of a public address can be a service that only a
        // firewall keeps from the world, and this server may stand behind that firewall. A host that
        // an administrator named may use any
        if (!$allowPrivate && !\in_array($port, [80, 443, 8080, 8443], true)) {
            return null;
        }
        $url  = $scheme . '://' . $host . ':' . $port
            . (($parts['path'] ?? '') === '' ? '/' : $parts['path'])
            . (isset($parts['query']) ? '?' . $parts['query'] : '');

        $addresses = self::addressesOf($host);

        if (!$addresses) {
            return null;
        }

        if (!$allowPrivate) {
            foreach ($addresses as $address) {
                if (!self::isPublicAddress($address)) {
                    return null;
                }
            }
        }

        $handle = curl_init($url);

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER  => true,
            // Followed by check() and download(), which look at where it leads first
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_CONNECTTIMEOUT  => max(1, min(10, $timeout)),
            CURLOPT_TIMEOUT         => max(1, $timeout),
            CURLOPT_USERAGENT       => self::USER_AGENT,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_ENCODING        => '',
            // A proxy of the environment would look the name up again, and ask what it finds
            CURLOPT_PROXY           => '',
        ]);

        // The name is not looked up again: the request goes to the address that was checked
        if (!filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
            curl_setopt($handle, CURLOPT_RESOLVE, [$host . ':' . $port . ':' . $addresses[0]]);
        }

        return $handle;
    }

    /**
     * Content is written for browsers, which encode spaces and Chinese by themselves. curl does not.
     */
    private static function encode(string $url): string
    {
        return preg_replace_callback(
            '/[^\x21-\x7e]/',
            static fn (array $match): string => rawurlencode($match[0]),
            $url
        );
    }
}
