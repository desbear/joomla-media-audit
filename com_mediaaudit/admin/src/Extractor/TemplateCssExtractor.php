<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Extractor;

use Gss\Component\Mediaaudit\Administrator\Service\ReferenceBuilder;

\defined('_JEXEC') or die;

/**
 * The style sheets of the templates: what they load with url().
 *
 * The sources are files, which have no id and no state. The path of the style sheet is the name of
 * the reference.
 */
final class TemplateCssExtractor implements ReferenceExtractorInterface
{
    /** Folders that are searched for style sheets, relative to the root of the site */
    private const FOLDERS = ['templates', 'media/templates', 'administrator/templates'];

    /** Larger style sheets are left out: they are builds of libraries, and reading them costs memory */
    private const MAX_BYTES = 4194304;

    private string $root;

    public function __construct(private ReferenceBuilder $builder, string $root)
    {
        $this->root = rtrim($root, '/');
    }

    public function getSourceType(): string
    {
        return 'template_css';
    }

    public function extractAll(callable $emit, int $batchSize = 200): void
    {
        foreach ($this->styleSheets() as $path) {
            $css = @file_get_contents($this->root . '/' . $path);

            if ($css === false || stripos($css, 'url(') === false) {
                continue;
            }

            $references = $this->builder->fromCss($css, \dirname($path), 'template_css', 0, self::fieldOf($path), null);

            foreach (ReferenceBuilder::unique($references) as $reference) {
                // Fonts and other style sheets are none of our business
                if ($reference->target->isExternal || \Gss\Component\Mediaaudit\Administrator\Service\PathNormalizer::looksLikeImage($reference->target->path)) {
                    $emit($reference);
                }
            }
        }
    }

    public function extractOne(int $sourceId): array
    {
        return [];
    }

    /**
     * The end of the path when the path is longer than the column.
     */
    private static function fieldOf(string $path): string
    {
        return mb_strlen($path, 'UTF-8') <= 100 ? $path : '…' . mb_substr($path, -99, null, 'UTF-8');
    }

    /**
     * @return  \Generator<string>  Paths relative to the root
     */
    private function styleSheets(): \Generator
    {
        $offset = \strlen($this->root) + 1;

        foreach (self::FOLDERS as $folder) {
            if (!is_dir($this->root . '/' . $folder)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root . '/' . $folder, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );

            try {
                foreach ($files as $file) {
                    if (strtolower($file->getExtension()) === 'css' && $file->isFile() && $file->getSize() <= self::MAX_BYTES) {
                        yield str_replace('\\', '/', substr($file->getPathname(), $offset));
                    }
                }
            } catch (\UnexpectedValueException $e) {
                // A folder that went away while it was read
            }
        }
    }
}
