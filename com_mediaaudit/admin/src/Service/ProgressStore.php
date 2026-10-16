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
 * Where a request that takes long tells how far it is, for the page that waits for it to ask.
 *
 * The page makes up a token and sends it with its form; what the request writes is kept under the
 * token, in the folder for temporary files. Who does not know the token reads nothing.
 */
final class ProgressStore
{
    /** Seconds after which what was written is of a request that is over */
    private const LIFETIME = 600;

    public function __construct(private string $folder)
    {
        $this->folder = rtrim($folder, '/');
    }

    public static function isToken(string $token): bool
    {
        return (bool) preg_match('/^[a-f0-9]{32}\z/', $token);
    }

    /**
     * @param  array<string, string|int|float|bool|null>  $state
     */
    public function write(string $token, array $state): void
    {
        if (!self::isToken($token) || !is_dir($this->folder)) {
            return;
        }

        $this->sweep();

        $file = $this->file($token);

        // Written at once: a reader never gets half of it
        if (@file_put_contents($file . '.part', json_encode($state + ['at' => time()], JSON_UNESCAPED_UNICODE)) !== false) {
            @rename($file . '.part', $file);
        }
    }

    /**
     * @return  array<string, mixed>|null  Null when nothing was written, or too long ago.
     */
    public function read(string $token): ?array
    {
        if (!self::isToken($token) || !is_file($this->file($token))) {
            return null;
        }

        $state = json_decode((string) @file_get_contents($this->file($token)), true);

        return \is_array($state) && time() - (int) ($state['at'] ?? 0) <= self::LIFETIME ? $state : null;
    }

    public function clear(string $token): void
    {
        if (self::isToken($token) && is_file($this->file($token))) {
            @unlink($this->file($token));
        }
    }

    /**
     * Takes away what requests of long ago wrote and nobody asked for. Now and then: not every
     * request has to look through the folder.
     */
    private function sweep(): void
    {
        if (random_int(1, 20) !== 1) {
            return;
        }

        foreach (glob($this->folder . '/mediaaudit-progress-*.json*') ?: [] as $file) {
            if (time() - (int) @filemtime($file) > self::LIFETIME) {
                @unlink($file);
            }
        }
    }

    private function file(string $token): string
    {
        return $this->folder . '/mediaaudit-progress-' . $token . '.json';
    }
}
