<?php

/**
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Mediaaudit\Tests\Unit;

use Gss\Plugin\Mediaaudit\Flexicontent\Extractor\FlexicontentExtractor;
use Gss\Plugin\Mediaaudit\Flexicontent\Extractor\ImagePaths;
use PHPUnit\Framework\TestCase;

final class FlexicontentImagePathsTest extends TestCase
{
    private const COMPONENT = ['file_path' => 'components/com_flexicontent/uploads', 'media_path' => 'components/com_flexicontent/medias'];

    public function testFilesInTheFolderOfTheComponent(): void
    {
        $field = ['image_source' => '0', 'dir' => 'images/stories/mainimg', 'target_dir' => '1', 'list_all_media_files' => '0'];

        $this->assertSame(
            [
                'original'   => 'components/com_flexicontent/uploads/cover.jpg',
                'thumbnails' => ['images/stories/mainimg/l_cover.jpg', 'images/stories/mainimg/m_cover.jpg', 'images/stories/mainimg/s_cover.jpg', 'images/stories/mainimg/b_cover.jpg'],
            ],
            ImagePaths::of($field, 16, self::COMPONENT, 4938, 'cover.jpg')
        );
    }

    public function testFieldsThatShareTheirFiles(): void
    {
        $field = ['image_source' => '0', 'dir' => 'images/stories/ogimg', 'target_dir' => '1', 'list_all_media_files' => '1'];
        $paths = ImagePaths::of($field, 45, self::COMPONENT, 4938, 'cover.jpg');

        $this->assertSame('images/stories/ogimg/l_fld45_cover.jpg', $paths['thumbnails'][0]);

        // Unless the thumbnails are told apart in another way
        $paths = ImagePaths::of($field + ['unique_thumb_method' => '1'], 45, self::COMPONENT, 4938, 'cover.jpg');

        $this->assertSame('images/stories/ogimg/l_cover.jpg', $paths['thumbnails'][0]);
    }

    public function testTheOtherFolderOfTheComponent(): void
    {
        $paths = ImagePaths::of(['image_source' => 0, 'target_dir' => '0'], 1, self::COMPONENT, 1, 'a.png');

        $this->assertSame('components/com_flexicontent/medias/a.png', $paths['original']);
    }

    public function testAFolderForEveryItemAndField(): void
    {
        $field = ['image_source' => '1', 'dir' => 'images/stories/flexicontent'];

        $this->assertSame(
            [
                'original'   => 'images/stories/flexicontent/item_321_field_104/original/hero.webp',
                'thumbnails' => [
                    'images/stories/flexicontent/item_321_field_104/l_hero.webp',
                    'images/stories/flexicontent/item_321_field_104/m_hero.webp',
                    'images/stories/flexicontent/item_321_field_104/s_hero.webp',
                    'images/stories/flexicontent/item_321_field_104/b_hero.webp',
                ],
            ],
            ImagePaths::of($field, 104, self::COMPONENT, 321, 'hero.webp')
        );
    }

    public function testFilesOfTheMediaManager(): void
    {
        $paths = ImagePaths::of(['image_source' => '-2', 'dir' => 'images/banners/thumb'], 80, self::COMPONENT, 5, 'images/banners/2026/wide.jpg');

        $this->assertSame('images/banners/2026/wide.jpg', $paths['original']);
        $this->assertSame('images/banners/thumb/mediaman/images/banners/2026/l_wide.jpg', $paths['thumbnails'][0]);
    }

    public function testTheImagesOfTheArticle(): void
    {
        $paths = ImagePaths::of(['image_source' => '-1'], 9, self::COMPONENT, 5, 'images/intro.jpg');

        $this->assertNull($paths['original']);
        $this->assertSame('images/stories/flexicontent/intro_full/images/l_intro.jpg', $paths['thumbnails'][0]);
    }

    public function testSettingsThatAreNotThere(): void
    {
        $paths = ImagePaths::of([], 26, [], 7, 'gallery.png');

        $this->assertSame('components/com_flexicontent/uploads/gallery.png', $paths['original']);
        $this->assertSame('images/stories/flexicontent/l_gallery.png', $paths['thumbnails'][0]);
    }

    public function testNamesThatLeaveTheFolder(): void
    {
        foreach (['', '  ', '../../configuration.php', 'a/../../b.jpg'] as $name) {
            $this->assertSame(['original' => null, 'thumbnails' => []], ImagePaths::of([], 1, [], 1, $name), $name);
        }
    }

    public function testTheDefaultImageOfAField(): void
    {
        // As on a site that was looked at: the field does not share its files, and the thumbnails carry its id all the same
        $field = ['image_source' => '0', 'dir' => 'images/stories/flexicontent', 'list_all_media_files' => '0'];

        $this->assertSame(
            [
                'original'   => 'images/stories/images/vitalacademybg2.png',
                'thumbnails' => [
                    'images/stories/flexicontent/l_fld293_vitalacademybg2.png',
                    'images/stories/flexicontent/m_fld293_vitalacademybg2.png',
                    'images/stories/flexicontent/s_fld293_vitalacademybg2.png',
                    'images/stories/flexicontent/b_fld293_vitalacademybg2.png',
                ],
            ],
            ImagePaths::ofDefault($field, 293, '/images/stories/images/vitalacademybg2.png')
        );

        // In the folder of the field, also when the files of the items have folders of their own
        $paths = ImagePaths::ofDefault(['image_source' => '1', 'dir' => 'images/stories/x'], 7, 'images/default.jpg');

        $this->assertSame('images/stories/x/l_fld7_default.jpg', $paths['thumbnails'][0]);
        $this->assertSame(['original' => null, 'thumbnails' => []], ImagePaths::ofDefault([], 7, ''));
        $this->assertSame(['original' => null, 'thumbnails' => []], ImagePaths::ofDefault([], 7, '../configuration.php'));
    }

    public function testNoFileIsCalledAfterTheOriginal(): void
    {
        // "o" is a name for the original in the settings of the display, not a file on disk
        $this->assertSame(['l', 'm', 's', 'b'], ImagePaths::SIZES);
    }

    public function testStates(): void
    {
        $this->assertSame(
            [1, 0, 2, -2, 1, 0, 0],
            array_map([FlexicontentExtractor::class, 'toState'], [1, 0, 2, -2, -5, -3, -4])
        );
    }
}
