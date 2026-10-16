<?php

/**
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Mediaaudit\Tests\Unit;

use Gss\Plugin\Mediaaudit\Easyblog\Extractor\CoverPaths;
use Gss\Plugin\Mediaaudit\Easyblog\Extractor\EasyblogExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EasyblogCoverPathsTest extends TestCase
{
    /**
     * @return  array<string, array{string, string|null}>
     */
    public static function covers(): array
    {
        return [
            'of a post'        => ['post:1847/uipath-rpa.jpg', 'images/easyblog_articles/1847/uipath-rpa.jpg'],
            'of a user'        => ['user:42/2024/cover.png', 'images/easyblog_images/42/2024/cover.png'],
            'shared'           => ['shared/computer/unsplash-coding104.jpg', 'images/easyblog_shared/computer/unsplash-coding104.jpg'],
            'Chinese name'     => ['post:9/封面.jpg', 'images/easyblog_articles/9/封面.jpg'],
            'a path'           => ['images/stories/cover.jpg', 'images/stories/cover.jpg'],
            'a rooted path'    => ['/images/stories/cover.jpg', 'images/stories/cover.jpg'],
            'an address'       => ['https://i.imgur.com/abc.png', 'https://i.imgur.com/abc.png'],
            'an object'        => ['{"url":"https://blog.example.com/images/a.jpg","title":"a"}', 'https://blog.example.com/images/a.jpg'],
            'an empty object'  => ['{"title":"a"}', null],
            'nothing'          => ['', null],
            'a place only'     => ['post:', null],
            'leaves the folder' => ['post:../../configuration.php', null],
            'leaves the site'  => ['../configuration.php', null],
        ];
    }

    #[DataProvider('covers')]
    public function testOf(string $cover, ?string $expected): void
    {
        $this->assertSame($expected, CoverPaths::of($cover));
    }

    public function testFoldersOfTheSettings(): void
    {
        $config = ['main_articles_path' => '/media/blog/posts/', 'main_image_path' => '', 'main_shared_path' => 'images\\shared'];

        $this->assertSame('media/blog/posts/7/a.jpg', CoverPaths::of('post:7/a.jpg', $config));
        $this->assertSame('images/easyblog_images/7/a.jpg', CoverPaths::of('user:7/a.jpg', $config));
        $this->assertSame('images/shared/x/a.jpg', CoverPaths::of('shared/x/a.jpg', $config));
    }

    public function testVariations(): void
    {
        $this->assertSame(
            [
                'images/easyblog_articles/7/b2ap3_icon_a.jpg',
                'images/easyblog_articles/7/b2ap3_thumbnail_a.jpg',
                'images/easyblog_articles/7/b2ap3_small_a.jpg',
                'images/easyblog_articles/7/b2ap3_medium_a.jpg',
                'images/easyblog_articles/7/b2ap3_large_a.jpg',
                'images/easyblog_articles/7/b2ap3_amp_a.jpg',
            ],
            CoverPaths::variationsOf('images/easyblog_articles/7/a.jpg')
        );
        $this->assertSame([], CoverPaths::variationsOf('https://i.imgur.com/abc.png'));
    }

    public function testStates(): void
    {
        $this->assertSame(1, EasyblogExtractor::stateOf(1, 0));
        $this->assertSame(0, EasyblogExtractor::stateOf(0, 0));
        $this->assertSame(0, EasyblogExtractor::stateOf(3, 0), 'a draft');
        $this->assertSame(0, EasyblogExtractor::stateOf(2, 0), 'scheduled');
        $this->assertSame(-2, EasyblogExtractor::stateOf(1, 1), 'trashed, whatever it was');
        $this->assertSame(2, EasyblogExtractor::stateOf(1, 2), 'archived');
    }
}
