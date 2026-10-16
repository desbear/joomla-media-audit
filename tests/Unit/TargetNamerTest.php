<?php

/**
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Mediaaudit\Tests\Unit;

use Gss\Component\Mediaaudit\Administrator\Converter\TargetNamer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TargetNamerTest extends TestCase
{
    /**
     * @return  array<string, array{string, bool}>
     */
    public static function paths(): array
    {
        return [
            'png'                 => ['images/a/photo.png', true],
            'jpg in capitals'     => ['images/photo.JPG', true],
            'jpeg'                => ['images/photo.jpeg', true],
            'gif can move'        => ['images/photo.gif', false],
            'webp already'        => ['images/photo.webp', false],
            'avif already'        => ['images/photo.avif', false],
            'svg'                 => ['images/logo.svg', false],
            'in quarantine'       => ['images/_mediaaudit_quarantine/20260929/images/photo.png', false],
            'outside images'      => ['media/photo.png', false],
            'of a template'       => ['templates/site/images/photo.png', false],
            'up and out'          => ['images/../configuration.png', false],
            'from the root'       => ['/images/photo.png', false],
            'no image'            => ['images/readme.txt', false],
        ];
    }

    #[DataProvider('paths')]
    public function testIsConvertible(string $path, bool $expected): void
    {
        $this->assertSame($expected, TargetNamer::isConvertible($path));
    }

    public function testCandidates(): void
    {
        $this->assertSame(
            ['images/a/photo.avif', 'images/a/photo-png.avif', 'images/a/photo-0123abcd.avif'],
            TargetNamer::candidates('images/a/photo.PNG', 'avif', '0123abcd4567')
        );

        $this->assertSame(
            ['images/My Photo (1).webp', 'images/My Photo (1)-jpg.webp'],
            TargetNamer::candidates('images/My Photo (1).jpg', 'webp')
        );

        // Only the last dot ends the name
        $this->assertSame('images/a.b.webp', TargetNamer::candidates('images/a.b.jpeg', 'webp')[0]);
    }
}
