<?php

/**
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Mediaaudit\Tests\Unit;

use Gss\Component\Mediaaudit\Administrator\Localizer\FileNamer;
use Gss\Component\Mediaaudit\Administrator\Localizer\ImageLocalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FileNamerTest extends TestCase
{
    /**
     * @return  array<string, array{string, string}>
     */
    public static function names(): array
    {
        return [
            'file name in the query'  => ['https://faq.example.com/s.aspx?cmd=d&subjectId=ee8a&fileName=%E6%88%AA%E5%9C%96%202022-03-23%2011_44_10.png', '2022-03-23-11_44_10'],
            'Chinese name only'       => ['https://faq.example.com/s.aspx?fileName=%E5%8A%A0%E7%B0%BD.png', 'image'],
            'name of the path'        => ['https://www.example.com/images/2024/My%20Photo%20(1).JPG', 'My-Photo-1'],
            'query that names nothing' => ['https://www.example.com/images/a.png?v=3', 'a'],
            'no name at all'          => ['https://www.example.com/', 'image'],
            'dots and traversal'      => ['https://www.example.com/x?file=../../etc/passwd.png', 'passwd'],
            'long name'               => ['https://www.example.com/' . str_repeat('a', 200) . '.png', str_repeat('a', 60)],
        ];
    }

    #[DataProvider('names')]
    public function testNameOf(string $url, string $expected): void
    {
        $this->assertSame($expected, FileNamer::nameOf($url));
    }

    public function testPathOf(): void
    {
        $url  = 'https://FAQ.Example.com:8443/s.aspx?fileName=report.png';
        $path = FileNamer::pathOf($url, 'PNG');

        $this->assertSame('faq.example.com/' . substr(sha1($url), 0, 10) . '-report.png', $path);
        $this->assertMatchesRegularExpression('#^[a-z0-9.-]+/[A-Za-z0-9_.-]+$#', $path);
    }

    public function testTwoUrlsWithTheSameNameGetTwoPaths(): void
    {
        $this->assertNotSame(
            FileNamer::pathOf('https://a.test/s.aspx?id=1&fileName=a.png', 'png'),
            FileNamer::pathOf('https://a.test/s.aspx?id=2&fileName=a.png', 'png')
        );
    }

    /**
     * @return  array<string, array{string, string|null}>
     */
    public static function folders(): array
    {
        return [
            'default'          => ['images/localized', 'images/localized'],
            'slashes around'   => ['/images/legacy/faq/', 'images/legacy/faq'],
            'outside images'   => ['media/localized', null],
            'images itself'    => ['images', null],
            'traversal'        => ['images/../configuration', null],
            'hidden'           => ['images/.hidden', null],
            'quarantine'       => ['images/_mediaaudit_quarantine', null],
            'below quarantine' => ['images/_mediaaudit_quarantine/x', null],
            'absolute'         => ['/etc/images/x', null],
        ];
    }

    #[DataProvider('folders')]
    public function testCheckFolder(string $folder, ?string $expected): void
    {
        if ($expected === null) {
            $this->expectException(\InvalidArgumentException::class);
        }

        $this->assertSame($expected, ImageLocalizer::checkFolder($folder));
    }
}
