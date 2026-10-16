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
 * What a reference points to: a path relative to the root of the site, or an external URL.
 */
final class NormalizedPath
{
    public function __construct(
        public readonly string $path,
        public readonly bool $isExternal = false
    ) {
    }

    /**
     * The key of the index tables. Paths can be longer than what an index takes.
     */
    public function hash(): string
    {
        return self::hashOf($this->path);
    }

    /**
     * The same for every spelling of the path that differs by case only.
     */
    public function caseInsensitiveHash(): string
    {
        return self::caseInsensitiveHashOf($this->path);
    }

    public static function hashOf(string $path): string
    {
        return sha1($path);
    }

    public static function caseInsensitiveHashOf(string $path): string
    {
        return sha1(mb_strtolower($path, 'UTF-8'));
    }

    public function isUnder(string $folder): bool
    {
        return !$this->isExternal && str_starts_with($this->path, rtrim($folder, '/') . '/');
    }
}
