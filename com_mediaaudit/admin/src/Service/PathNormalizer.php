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
 * Turns a reference, as it is written in the content, into a path relative to the root of the site.
 *
 * The case of the path is never changed: on a case sensitive file system Photo.JPG and photo.jpg
 * are two files, and a reference to the wrong one is a broken image.
 */
final class PathNormalizer
{
    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'];

    /** What a server runs rather than sends: a URL of these is never a file of the site */
    private const SCRIPT_EXTENSIONS = ['php', 'phtml', 'asp', 'aspx', 'ashx', 'jsp', 'cgi', 'do'];

    /** @var array<string, true>  Hosts of this site, lower case, without "www." and without port */
    private array $domains = [];

    /** Folder of the site below the root of the domain, without slashes at the ends */
    private string $basePath;

    /**
     * @param  string[]  $siteDomains  Domains of this site. Protocol, "www." and port do not matter.
     * @param  string    $basePath     Only for a site that is installed in a subfolder, like "/portal".
     */
    public function __construct(array $siteDomains = [], string $basePath = '')
    {
        foreach ($siteDomains as $domain) {
            $host = self::bareHost((string) $domain);

            if ($host !== '') {
                $this->domains[$host] = true;
            }
        }

        $this->basePath = trim(str_replace('\\', '/', $basePath), "/ \t\n\r");
    }

    /**
     * From the options of the component: one domain per line, or separated by commas.
     *
     * The hosts that Joomla knows the site by count as well: the one of "live_site" in the global
     * configuration, and the one that the request was sent to. Without them, a site that writes
     * its own images with its full address would take them for images of another site, and the
     * files for orphans, until someone fills in the option.
     */
    public static function fromOptions(string $siteDomains, string $basePath = ''): self
    {
        return new self(array_merge(preg_split('/[\s,]+/', $siteDomains, -1, PREG_SPLIT_NO_EMPTY) ?: [], self::knownHosts()), $basePath);
    }

    /**
     * @return  string[]  The hosts that Joomla knows this site by. Empty outside of Joomla.
     */
    public static function knownHosts(): array
    {
        $hosts = [];

        try {
            if (class_exists(\Joomla\CMS\Factory::class) && \Joomla\CMS\Factory::getApplication()) {
                $application = \Joomla\CMS\Factory::getApplication();
                $hosts[]     = (string) parse_url((string) $application->get('live_site', ''), PHP_URL_HOST);

                // The host that the administrator is opened with. Not of the site: whoever asks for a
                // page there chooses the host that the request names, and a request of a visitor can
                // run a scheduled scan. Not on the command line, which has no request
                if ($application->isClient('administrator') && PHP_SAPI !== 'cli') {
                    $hosts[] = (string) \Joomla\CMS\Uri\Uri::getInstance()->getHost();
                }
            }
        } catch (\Throwable $e) {
            // No application yet, as in the tests
        }

        return array_values(array_filter($hosts, static fn (string $host): bool => $host !== '' && $host !== 'localhost'));
    }

    /**
     * @param  string  $raw      The reference as it is written.
     * @param  string  $baseDir  Folder that relative references start from, relative to the root of
     *                           the site. Empty for content; the folder of the file for a style sheet.
     *
     * @return  NormalizedPath|null  Null for what is not a reference to a file: data: URIs, anchors,
     *                               mailto: and the like, and the URLs of scripts of this site.
     */
    public function normalize(string $raw, string $baseDir = ''): ?NormalizedPath
    {
        $value = trim($raw);

        // images/a.jpg#joomlaImage://local-images/a.jpg?width=800&height=600
        $suffix = stripos($value, '#joomlaImage:');

        if ($suffix !== false) {
            $value = rtrim(substr($value, 0, $suffix));
        }

        if ($value === '' || $value[0] === '#' || $value[0] === '?') {
            return null;
        }

        // data:, blob:, javascript:, mailto:, tel: and every other scheme that is not http
        if (preg_match('#^([a-z][a-z0-9+.-]*):#i', $value, $match) && !\in_array(strtolower($match[1]), ['http', 'https'], true)) {
            return null;
        }

        // Browsers read a backslash in a URL as a slash
        $value        = str_replace('\\', '/', $value);
        $rootRelative = false;

        if (preg_match('#^(?:(https?):)?//([^/?\#]*)(.*)$#is', $value, $match)) {
            if (!isset($this->domains[self::bareHost($match[2])])) {
                return $this->external($match[1], $match[2], $match[3]);
            }

            $value        = $match[3];
            $rootRelative = true;
        }

        // Before decoding: %23 and %3F are part of a file name
        $hasQuery = str_contains($value, '?');
        $value    = preg_replace('/[?#].*$/s', '', $value);

        if (str_starts_with($value, '/')) {
            $rootRelative = true;
        }

        // Not urldecode(): a plus sign in a path is a plus sign
        $value = rawurldecode($value);

        if (!$rootRelative && $baseDir !== '') {
            $value = trim($baseDir, '/') . '/' . $value;
        }

        $path = self::resolveDots($value);

        if ($rootRelative && $this->basePath !== '') {
            if ($path === $this->basePath) {
                return null;
            }

            if (str_starts_with($path, $this->basePath . '/')) {
                $path = substr($path, \strlen($this->basePath) + 1);
            }
        }

        if ($path === '' || self::isDynamic($path, $hasQuery)) {
            return null;
        }

        // "%FF" and "%00" decode to what is no name of a file: bytes that are no text, and control
        // characters. No file is meant, and what cannot be stored must not stop the index
        if (!mb_check_encoding($path, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $path)) {
            return null;
        }

        return new NormalizedPath(self::composeUnicode($path));
    }

    /**
     * Whether the reference points to an image, judged by its extension.
     */
    public static function looksLikeImage(string $raw): bool
    {
        $value  = trim($raw);
        $suffix = stripos($value, '#joomlaImage:');

        if ($suffix !== false) {
            $value = substr($value, 0, $suffix);
        }

        $value = preg_replace('/[?#].*$/s', '', $value);

        return (bool) preg_match('/\.(?:' . implode('|', self::IMAGE_EXTENSIONS) . ')$/i', rtrim($value));
    }

    /**
     * File names are compared byte for byte, so the two ways to write an accented or a composed
     * character have to become one.
     */
    public static function composeUnicode(string $path): string
    {
        if (!class_exists(\Normalizer::class) || !preg_match('/[^\x00-\x7f]/', $path)) {
            return $path;
        }

        $composed = \Normalizer::normalize($path, \Normalizer::FORM_C);

        return $composed === false ? $path : $composed;
    }

    private function external(string $scheme, string $host, string $rest): ?NormalizedPath
    {
        // The query can be what tells two external images apart; the fragment never is
        $rest = preg_replace('/#.*$/s', '', $rest);

        if (!mb_check_encoding($host . $rest, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $host . $rest)) {
            return null;
        }

        // A name, with a port or without, and nothing that reads as a sentence: the hosts are shown
        // in lists and written into the summary that is sent by mail
        if (!preg_match('/^(?:[\p{L}\p{N}](?:[\p{L}\p{N}._-]*[\p{L}\p{N}])?\.?|\[[0-9a-f:.]+\])(?::\d{1,5})?$/iu', $host)) {
            return null;
        }

        return new NormalizedPath(
            strtolower($scheme === '' ? 'https' : $scheme) . '://' . strtolower($host) . ($rest === '' ? '/' : $rest),
            true
        );
    }

    /**
     * index.php?option=com_x&task=image&id=3 is an answer of the site, not a file on its disk.
     */
    private static function isDynamic(string $path, bool $hasQuery): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $extension === '' ? $hasQuery : \in_array($extension, self::SCRIPT_EXTENSIONS, true);
    }

    /**
     * Resolves "./" and "../" and drops empty segments. Like a browser, never climbs above the root.
     */
    private static function resolveDots(string $path): string
    {
        $resolved = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($resolved);

                continue;
            }

            $resolved[] = $segment;
        }

        return implode('/', $resolved);
    }

    /**
     * "https://WWW.Example.com:8443/path" becomes "example.com".
     */
    private static function bareHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('#^(?:[a-z][a-z0-9+.-]*:)?//#', '', $host);
        $host = preg_replace('#[/?\#].*$#s', '', $host);
        $host = preg_replace('/^[^@]*@/', '', $host);
        $host = preg_replace('/:\d+$/', '', $host);

        // "www.example.com." is the full way to write "www.example.com"
        $host = rtrim((string) $host, '.');

        return preg_replace('/^www\./', '', $host);
    }
}
