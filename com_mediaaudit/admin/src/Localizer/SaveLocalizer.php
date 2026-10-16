<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Localizer;

use Gss\Component\Mediaaudit\Administrator\Service\HttpFetcher;
use Gss\Component\Mediaaudit\Administrator\Service\ReferenceBuilder;

\defined('_JEXEC') or die;

/**
 * Replaces the external images of a text that is about to be saved by local copies.
 *
 * Only what the parser reads as an image is fetched: a link to a page or the source of a frame is
 * never downloaded. Saving must not wait for a slow site, so there is a limit to the time; the
 * images that are left stay external, and the next run of mediaaudit:localize takes care of them.
 */
final class SaveLocalizer
{
    public function __construct(private ReferenceBuilder $builder, private ContentRewriter $rewriter)
    {
    }

    /**
     * @param   array<string, string|null>  $html    Column => HTML
     * @param   array<string, string|null>  $json    Column => JSON
     * @param   string[]                    $hosts   Hosts to copy from. Empty for every host.
     * @param   callable                    $copy    function (string $url, string $hash, float $secondsLeft): ?string.
     *                                               Returns the path of the copy, which it makes when
     *                                               there is none, or null when it cannot.
     * @param   float                       $budget  Seconds that all the downloads may take together.
     *
     * @return  array{columns: array<string, string>, replaced: int, copied: int, failed: int, postponed: int}
     *          The columns that changed, with their new text.
     */
    public function localize(array $html, array $json, array $hosts, callable $copy, float $budget = 15.0): array
    {
        $result = ['columns' => [], 'replaced' => 0, 'copied' => 0, 'failed' => 0, 'postponed' => 0];
        $hosts  = array_filter(array_map('strtolower', array_map('trim', $hosts)));
        $urls   = [];

        foreach ($html as $column => $text) {
            foreach ($this->builder->fromHtml($text, 'save', 0, $column, null) as $reference) {
                $urls[$reference->target->hash()] = $reference->target;
            }
        }

        foreach ($json as $column => $text) {
            foreach ($this->builder->fromJson($text, 'save', 0, $column, null) as $reference) {
                $urls[$reference->target->hash()] = $reference->target;
            }
        }

        $copies   = [];
        $deadline = microtime(true) + max(0.0, $budget);

        foreach ($urls as $hash => $target) {
            if (!$target->isExternal || ($hosts && !\in_array(HttpFetcher::hostOf($target->path), $hosts, true))) {
                continue;
            }

            $left = $deadline - microtime(true);
            $path = $copy($target->path, $hash, max(0.0, $left));

            if ($path !== null) {
                $copies[$hash] = $path;
                $result['copied']++;
            } elseif ($left <= 0) {
                $result['postponed']++;
            } else {
                $result['failed']++;
            }
        }

        if (!$copies) {
            return $result;
        }

        $lookup = function (string $url) use ($copies): ?string {
            $reference = $this->builder->fromValue($url, 'save', 0, '', null)[0] ?? null;

            return $reference === null ? null : ($copies[$reference->target->hash()] ?? null);
        };

        foreach ($html as $column => $text) {
            [$rewritten, $replaced] = $this->rewriter->rewrite((string) $text, $lookup);

            if ($replaced > 0 && $rewritten !== $text) {
                $result['columns'][$column] = $rewritten;
                $result['replaced']        += $replaced;
            }
        }

        foreach ($json as $column => $text) {
            [$rewritten, $replaced] = $this->rewriter->rewriteJson((string) $text, $lookup);

            if ($replaced > 0 && $rewritten !== $text) {
                $result['columns'][$column] = $rewritten;
                $result['replaced']        += $replaced;
            }
        }

        return $result;
    }
}
