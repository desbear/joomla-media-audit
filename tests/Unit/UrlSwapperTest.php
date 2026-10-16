<?php

/**
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Mediaaudit\Tests\Unit;

use Gss\Component\Mediaaudit\Administrator\Converter\UrlSwapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlSwapperTest extends TestCase
{
    /**
     * @return  array<string, array{string, string, string, string|null}>
     */
    public static function urls(): array
    {
        return [
            'relative'              => ['images/a/photo.png', 'photo.png', 'photo.avif', 'images/a/photo.avif'],
            'from the root'         => ['/images/a/photo.png', 'photo.png', 'photo.avif', '/images/a/photo.avif'],
            'with the domain'       => ['https://www.example.com/images/photo.JPG', 'photo.JPG', 'photo.webp', 'https://www.example.com/images/photo.webp'],
            'without a scheme'      => ['//www.example.com/images/photo.jpg', 'photo.jpg', 'photo.webp', '//www.example.com/images/photo.webp'],
            'with a query'          => ['images/photo.png?v=3', 'photo.png', 'photo.avif', 'images/photo.avif?v=3'],
            'with a fragment'       => ['images/photo.png#top', 'photo.png', 'photo.avif', 'images/photo.avif#top'],
            'media field'           => [
                'images/a/photo.png#joomlaImage://local-images/a/photo.png?width=800&height=600',
                'photo.png',
                'photo.avif',
                'images/a/photo.avif#joomlaImage://local-images/a/photo.avif?width=800&height=600',
            ],
            'escaped'               => ['images/My%20Photo%20(1).png', 'My Photo (1).png', 'My Photo (1).avif', 'images/My%20Photo%20%281%29.avif'],
            'escaped Chinese'       => ['images/%E6%88%AA%E5%9C%96.png', '截圖.png', '截圖.avif', 'images/%E6%88%AA%E5%9C%96.avif'],
            'Chinese as it is'      => ['images/截圖.png', '截圖.png', '截圖.avif', 'images/截圖.avif'],
            'space as it is'        => ['images/My Photo.png', 'My Photo.png', 'My Photo.avif', 'images/My Photo.avif'],
            'another name'          => ['images/a/photo.png', 'photo.png', 'photo-png.avif', 'images/a/photo-png.avif'],
            'dots in the path'      => ['images/a/../b/photo.png', 'photo.png', 'photo.avif', 'images/a/../b/photo.avif'],
            'the name in a folder'  => ['images/photo.png/photo.png', 'photo.png', 'photo.avif', 'images/photo.png/photo.avif'],
            'paths are given'       => ['images/a/photo.png', 'images/a/photo.png', 'images/a/photo.avif', 'images/a/photo.avif'],
            'not the file'          => ['images/a/other.png', 'photo.png', 'photo.avif', null],
            'another case'          => ['images/a/Photo.png', 'photo.png', 'photo.avif', null],
            'no file at all'        => ['images/a/', 'photo.png', 'photo.avif', null],
        ];
    }

    #[DataProvider('urls')]
    public function testSwap(string $url, string $from, string $to, ?string $expected): void
    {
        $this->assertSame($expected, UrlSwapper::swap($url, $from, $to));
    }
}
