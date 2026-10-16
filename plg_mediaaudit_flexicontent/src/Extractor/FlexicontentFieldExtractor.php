<?php

/**
 * @package     Media Audit
 * @subpackage  plg_mediaaudit_flexicontent
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mediaaudit\Flexicontent\Extractor;

use Gss\Component\Mediaaudit\Administrator\Extractor\AbstractTableExtractor;
use Gss\Component\Mediaaudit\Administrator\Service\CaseSensitiveFilesystem;
use Gss\Component\Mediaaudit\Administrator\Service\PathNormalizer;
use Gss\Component\Mediaaudit\Administrator\Service\ReferenceBuilder;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * The settings of the fields of FLEXIcontent. A source is a field.
 *
 * An image field can have a default image, which is shown for every item that has no image of its
 * own. No value of any item names it: without this extractor it looks like an orphan, and so do the
 * thumbnails that FLEXIcontent made of it.
 */
final class FlexicontentFieldExtractor extends AbstractTableExtractor
{
    private ?CaseSensitiveFilesystem $filesystem;

    /** @var callable|null  function (string $path): bool. For the tests. */
    private $exists;

    public function __construct(DatabaseInterface $db, ReferenceBuilder $builder, string $root = '', ?callable $exists = null)
    {
        parent::__construct($db, $builder);

        $this->filesystem = $root === '' ? null : new CaseSensitiveFilesystem($root);
        $this->exists     = $exists;
    }

    public function getSourceType(): string
    {
        return 'flexicontent_field';
    }

    protected function getTable(): string
    {
        return '#__flexicontent_fields';
    }

    protected function getColumns(): array
    {
        return ['field_type', 'attribs'];
    }

    protected function getStateColumn(): ?string
    {
        return 'published';
    }

    protected function toState($value): ?int
    {
        // A field is on or off
        return (int) $value === 1 ? 1 : 0;
    }

    protected function extractRow(object $row, ?int $state): array
    {
        $id       = (int) $row->id;
        $settings = json_decode((string) $row->attribs, true) ?: [];

        // Whatever the settings hold that looks like an image: icons, backgrounds, the default image
        $references = $this->builder->fromJson($settings, 'flexicontent_field', $id, 'attribs', $state);

        if ($row->field_type !== 'image' || trim((string) ($settings['default_image'] ?? '')) === '') {
            return $references;
        }

        foreach (ImagePaths::ofDefault($settings, $id, (string) $settings['default_image'])['thumbnails'] as $thumbnail) {
            if ($this->isFile($thumbnail)) {
                $references = array_merge($references, $this->builder->fromValue($thumbnail, 'flexicontent_field', $id, 'attribs.default_image#thumbnail', $state));
            }
        }

        return $references;
    }

    private function isFile(string $path): bool
    {
        if ($this->exists !== null) {
            return (bool) ($this->exists)($path);
        }

        return $this->filesystem !== null && $this->filesystem->isFile(PathNormalizer::composeUnicode($path));
    }
}
