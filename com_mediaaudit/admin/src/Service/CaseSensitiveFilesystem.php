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
 * Tells whether a file exists under the name it is asked for, letter for letter.
 *
 * file_exists() says yes to Photo.JPG when the file is photo.jpg on the file systems of macOS and
 * Windows. The answer has to be the one of the Linux server that the site runs on.
 */
final class CaseSensitiveFilesystem
{
    /** @var array<string, array<string, true>|null>  Names in a folder; null for what is not a folder */
    private array $folders = [];

    public function __construct(private string $root)
    {
        $this->root = rtrim($root, '/');
    }

    /**
     * @param  string  $path  Relative to the root, normalized.
     */
    public function isFile(string $path): bool
    {
        $segments = explode('/', $path);
        $name     = array_pop($segments);
        $folder   = '';

        foreach ($segments as $segment) {
            if (!$this->has($folder, $segment)) {
                return false;
            }

            $folder = $folder === '' ? $segment : $folder . '/' . $segment;
        }

        return $this->has($folder, $name) && is_file($this->root . '/' . $path);
    }

    /**
     * Whether the file is where its path says: below the folder of this site, and not somewhere
     * that a link in its path leads to.
     *
     * @param  string  $path    Relative to the root.
     * @param  string  $folder  Relative to the root, like "images".
     */
    public function isInside(string $path, string $folder): bool
    {
        $base = realpath($this->root . '/' . trim($folder, '/'));
        $real = realpath(\dirname($this->root . '/' . $path));

        return $base !== false && $real !== false && ($real === $base || str_starts_with($real, $base . DIRECTORY_SEPARATOR));
    }

    private function has(string $folder, string $name): bool
    {
        if (!\array_key_exists($folder, $this->folders)) {
            $this->folders[$folder] = $this->read($folder);
        }

        return isset($this->folders[$folder][$name]);
    }

    /**
     * @return  array<string, true>|null
     */
    private function read(string $folder): ?array
    {
        $absolute = $folder === '' ? $this->root : $this->root . '/' . $folder;

        if (!is_dir($absolute) || ($names = @scandir($absolute)) === false) {
            return null;
        }

        $list = [];

        foreach ($names as $name) {
            $list[PathNormalizer::composeUnicode($name)] = true;
        }

        return $list;
    }
}
