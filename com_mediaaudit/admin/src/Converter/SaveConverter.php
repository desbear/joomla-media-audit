<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Converter;

use Gss\Component\Mediaaudit\Administrator\Localizer\ContentRewriter;
use Gss\Component\Mediaaudit\Administrator\Service\ReferenceBuilder;

\defined('_JEXEC') or die;

/**
 * Makes a text that is about to be saved load converted files in the place of its images.
 *
 * Writing AVIF takes seconds for a large image, and saving must not take minutes: there is a limit
 * to the time. The images that are left stay as they are, and the next run of mediaaudit:convert
 * takes care of them.
 */
final class SaveConverter
{
    public function __construct(private ReferenceBuilder $builder, private ContentRewriter $rewriter)
    {
    }

    /**
     * @param   array<string, string|null>  $html      Column => HTML
     * @param   array<string, string|null>  $json      Column => JSON
     * @param   callable                    $convert   function (string $path, float $secondsLeft): ?string.
     *                                                 Returns the path of the converted file, which
     *                                                 it makes when there is none, or null when there
     *                                                 is none to have.
     * @param   float                       $budget    Seconds that all the images may take together.
     * @param   callable|null               $progress  function (int $done, int $total, string $path): void,
     *                                                 before every image.
     *
     * @return  array{columns: array<string, string>, replaced: int, images: int, converted: int, postponed: int}
     *          The columns that changed, with their new text.
     */
    public function convert(array $html, array $json, callable $convert, float $budget = 20.0, ?callable $progress = null): array
    {
        $result = ['columns' => [], 'replaced' => 0, 'images' => 0, 'converted' => 0, 'postponed' => 0];
        $paths  = [];

        foreach ($html as $column => $text) {
            foreach ($this->builder->fromHtml($text, 'save', 0, $column, null) as $reference) {
                $paths[$reference->target->hash()] = $reference->target;
            }
        }

        foreach ($json as $column => $text) {
            foreach ($this->builder->fromJson($text, 'save', 0, $column, null) as $reference) {
                $paths[$reference->target->hash()] = $reference->target;
            }
        }

        $paths = array_filter(
            $paths,
            static fn ($target): bool => !$target->isExternal && TargetNamer::isConvertible($target->path)
        );

        $result['images'] = \count($paths);

        $targets  = [];
        $deadline = microtime(true) + max(0.0, $budget);
        $done     = 0;

        foreach ($paths as $hash => $target) {
            if ($progress !== null) {
                $progress($done, \count($paths), $target->path);
            }

            $done++;

            $left      = $deadline - microtime(true);
            $converted = $convert($target->path, max(0.0, $left));

            if ($converted !== null) {
                $targets[$hash] = ['source' => $target->path, 'target' => $converted];
                $result['converted']++;
            } elseif ($left <= 0) {
                $result['postponed']++;
            }
        }

        if (!$targets) {
            return $result;
        }

        $lookup = function (string $url) use ($targets): ?string {
            $reference = $this->builder->fromValue($url, 'save', 0, '', null)[0] ?? null;

            if ($reference === null || !isset($targets[$reference->target->hash()])) {
                return null;
            }

            $target = $targets[$reference->target->hash()];

            return UrlSwapper::swap($url, $target['source'], $target['target']);
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
