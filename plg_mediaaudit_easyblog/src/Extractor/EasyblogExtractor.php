<?php

/**
 * @package     Media Audit
 * @subpackage  plg_mediaaudit_easyblog
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mediaaudit\Easyblog\Extractor;

use Gss\Component\Mediaaudit\Administrator\Extractor\AbstractTableExtractor;
use Gss\Component\Mediaaudit\Administrator\Service\CaseSensitiveFilesystem;
use Gss\Component\Mediaaudit\Administrator\Service\PathNormalizer;
use Gss\Component\Mediaaudit\Administrator\Service\ReferenceBuilder;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * The posts of EasyBlog: the cover, and the images of the intro and of the text.
 *
 * Of an image in a folder of EasyBlog, the sizes that EasyBlog made count as referred to as well.
 * Only the sizes that are on disk.
 *
 * The posts are not rewritten: a post of the composer is built again from its blocks when it is
 * edited, and what was changed in the text would be lost.
 */
final class EasyblogExtractor extends AbstractTableExtractor
{
    private ?CaseSensitiveFilesystem $filesystem;

    /** @var callable|null  function (string $path): bool. For the tests. */
    private $exists;

    /** @var array<string, mixed>|null */
    private ?array $config = null;

    public function __construct(DatabaseInterface $db, ReferenceBuilder $builder, string $root = '', ?callable $exists = null)
    {
        parent::__construct($db, $builder);

        $this->filesystem = $root === '' ? null : new CaseSensitiveFilesystem($root);
        $this->exists     = $exists;
    }

    public function getSourceType(): string
    {
        return 'easyblog';
    }

    /**
     * The state of Joomla that the two columns of EasyBlog stand for.
     *
     * @param  int  $published  1 published, 0 unpublished, 2 scheduled, 3 draft, 4 pending.
     * @param  int  $state      0 as it is, 1 trashed, 2 archived.
     */
    public static function stateOf(int $published, int $state): int
    {
        if ($state === 1) {
            return -2;
        }

        if ($state === 2) {
            return 2;
        }

        return $published === 1 ? 1 : 0;
    }

    protected function getTable(): string
    {
        return '#__easyblog_post';
    }

    protected function getColumns(): array
    {
        return ['intro', 'content', 'image', 'state'];
    }

    protected function getStateColumn(): ?string
    {
        return 'published';
    }

    protected function extractRow(object $row, ?int $state): array
    {
        $id    = (int) $row->id;
        $state = self::stateOf((int) $row->published, (int) $row->state);

        $references = array_merge(
            $this->builder->fromHtml($row->intro, 'easyblog', $id, 'intro', $state),
            $this->builder->fromHtml($row->content, 'easyblog', $id, 'content', $state)
        );

        $cover = CoverPaths::of((string) $row->image, $this->getConfig());

        if ($cover !== null) {
            $references = array_merge($references, $this->builder->fromValue($cover, 'easyblog', $id, 'image', $state));
        }

        // The other sizes of what is referred to
        foreach ($references as $reference) {
            if ($reference->target->isExternal || !str_starts_with($reference->target->path, 'images/easyblog_')) {
                continue;
            }

            foreach (CoverPaths::variationsOf(self::withoutVariation($reference->target->path)) as $variation) {
                if ($this->isFile($variation)) {
                    $references = array_merge($references, $this->builder->fromValue($variation, 'easyblog', $id, preg_replace('/#.*$/', '', $reference->field) . '#variation', $state));
                }
            }
        }

        return $references;
    }

    /**
     * The path of the image that a size was made of.
     */
    private static function withoutVariation(string $path): string
    {
        $folder = \dirname($path) === '.' ? '' : \dirname($path) . '/';

        return $folder . preg_replace('/^b2ap3_[a-z]+_/', '', basename($path));
    }

    private function isFile(string $path): bool
    {
        if ($this->exists !== null) {
            return (bool) ($this->exists)($path);
        }

        return $this->filesystem !== null && $this->filesystem->isFile(PathNormalizer::composeUnicode($path));
    }

    /**
     * @return  array<string, mixed>
     */
    private function getConfig(): array
    {
        if ($this->config === null) {
            $this->config = [];

            try {
                $db    = $this->db;
                $query = $db->getQuery(true)
                    ->select($db->quoteName('params'))
                    ->from($db->quoteName('#__easyblog_configs'))
                    ->where($db->quoteName('name') . ' = ' . $db->quote('config'));

                $this->config = json_decode((string) $db->setQuery($query)->loadResult(), true) ?: [];
            } catch (\Throwable $e) {
                // Older versions keep their settings elsewhere: the folders that EasyBlog starts with
            }
        }

        return $this->config;
    }
}
