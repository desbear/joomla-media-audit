<?php

/**
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Mediaaudit\Tests\Unit;

use Gss\Component\Mediaaudit\Administrator\Service\HtmlReferenceParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HtmlReferenceParserTest extends TestCase
{
    /**
     * @return  string[]  The references, sorted.
     */
    private function raws(string $html): array
    {
        $raws = array_column((new HtmlReferenceParser())->parse($html), 'raw');
        sort($raws);

        return $raws;
    }

    /**
     * @return  array<string, string>  Where each reference was found.
     */
    private function vias(string $html): array
    {
        return array_column((new HtmlReferenceParser())->parse($html), 'via', 'raw');
    }

    public function testImgSrc(): void
    {
        $this->assertSame(['images/a.jpg'], $this->raws('<p><img src="images/a.jpg" alt="a"></p>'));
        $this->assertSame(['images/a.jpg' => 'img[src]'], $this->vias('<img src="images/a.jpg">'));
    }

    public function testSrcset(): void
    {
        $html = '<img src="images/d-800.jpg" srcset="images/d-800.jpg 800w, images/d-400.jpg 400w" sizes="100vw">';

        $this->assertSame(['images/d-400.jpg', 'images/d-800.jpg'], $this->raws($html));
    }

    public function testPictureSources(): void
    {
        $html = '<picture><source srcset="images/a.avif 1x, images/a@2x.avif 2x" type="image/avif">'
            . '<source srcset="images/a.webp"><img src="images/a.jpg"></picture>';

        $this->assertSame(['images/a.avif', 'images/a.jpg', 'images/a.webp', 'images/a@2x.avif'], $this->raws($html));
    }

    public function testLazyLoading(): void
    {
        $html = '<img class="lazyload" src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" data-src="images/e.jpg" data-srcset="images/e-2x.jpg 2x">';
        $raws = $this->raws($html);

        $this->assertContains('images/e.jpg', $raws);
        $this->assertContains('images/e-2x.jpg', $raws);
    }

    public function testOtherDataAttributesWithAnImage(): void
    {
        $html = '<div data-thumb="images/t.png" data-full="/images/full.webp" data-title="not an image" data-id="12"></div>';

        $this->assertSame(['/images/full.webp', 'images/t.png'], $this->raws($html));
    }

    public function testInlineStyle(): void
    {
        $this->assertSame(['/images/f.jpg'], $this->raws("<div style=\"background-image:url('/images/f.jpg')\">x</div>"));
        $this->assertSame(['images/f.jpg'], $this->raws('<div style="background: #fff url(images/f.jpg) no-repeat">x</div>'));
        $this->assertSame(['images/f.jpg'], $this->raws('<div style="background:url(&quot;images/f.jpg&quot;)">x</div>'));
    }

    public function testStyleElement(): void
    {
        $html = '<style>.a { background: url("images/s1.png"); } .b { background: url(images/s2.png) }</style><p>x</p>';

        $this->assertSame(['images/s1.png', 'images/s2.png'], $this->raws($html));
    }

    public function testLinksToImagesOnly(): void
    {
        $html = '<a href="images/big.JPG">big</a><a href="images/doc.pdf">pdf</a><a href="index.php?option=com_content">x</a>'
            . '<a href="images/x.svg#icon">svg</a>';

        $this->assertSame(['images/big.JPG', 'images/x.svg#icon'], $this->raws($html));
    }

    public function testSrcOfOtherElementsIsNotAnImage(): void
    {
        $html = '<script src="media/x.js"></script><iframe src="https://example.com/"></iframe><video src="images/v.mp4" poster="images/poster.jpg"></video>';

        $this->assertSame(['images/poster.jpg'], $this->raws($html));
    }

    public function testEntitiesAreDecoded(): void
    {
        $this->assertSame(['images/a.jpg?x=1&y=2'], $this->raws('<img src="images/a.jpg?x=1&amp;y=2">'));
    }

    public function testChineseAndSpaces(): void
    {
        $html = '<img src="images/產品圖.png"><img src="images/%E7%94%A2%E5%93%81%E5%9C%96.png"><img src="images/my photo.jpg">';

        $this->assertSame(
            ['images/%E7%94%A2%E5%93%81%E5%9C%96.png', 'images/my photo.jpg', 'images/產品圖.png'],
            $this->raws($html)
        );
    }

    public function testDataUrisAreReturnedAsTheyAre(): void
    {
        // The normalizer is the one that drops them
        $this->assertSame(['data:image/png;base64,AAAA'], $this->raws('<img src="data:image/png;base64,AAAA">'));
    }

    public function testBrokenHtmlDoesNotStopTheParser(): void
    {
        $html = '<div><p><img src="images/a.jpg"<span></div></p><img src=images/b.jpg><table><tr><td><img src="images/c.jpg"';

        $raws = $this->raws($html);

        $this->assertContains('images/a.jpg', $raws);
        $this->assertContains('images/b.jpg', $raws);
        $this->assertContains('images/c.jpg', $raws);
    }

    public function testEmptyAndPlainText(): void
    {
        $this->assertSame([], $this->raws(''));
        $this->assertSame([], $this->raws("  \n "));
        $this->assertSame([], $this->raws('No markup at all.'));
    }

    public function testPathsInTheTagsOfContentPlugins(): void
    {
        $vias = $this->vias('<p>{slider images/slides/one.jpg|images/slides/two.png}</p>');

        $this->assertSame(
            ['images/slides/one.jpg' => HtmlReferenceParser::VIA_TEXT, 'images/slides/two.png' => HtmlReferenceParser::VIA_TEXT],
            $vias
        );
    }

    public function testTextScanLeavesOtherSitesAndLongerPathsAlone(): void
    {
        $html = '<p>See https://cdn.example.org/images/remote.jpg and media/com_x/images/inner.png and /images/rooted.gif.</p>';

        $this->assertSame([], $this->raws($html));
    }

    public function testAnAttributeWinsOverTheText(): void
    {
        $vias = $this->vias('<img src="images/a.jpg"><p>images/a.jpg</p>');

        $this->assertSame(['images/a.jpg' => 'img[src]'], $vias);
    }

    public function testRepeatsAreDropped(): void
    {
        $this->assertSame(['images/a.jpg'], $this->raws('<img src="images/a.jpg"><img src="images/a.jpg">'));
    }

    public function testLargeContent(): void
    {
        $html = '<img src="data:image/png;base64,' . str_repeat('A', 12 * 1024 * 1024) . '"><img src="images/after.jpg">';

        $this->assertContains('images/after.jpg', $this->raws($html));
    }

    /**
     * @return  array<string, array{string, string[]}>
     */
    public static function srcsets(): array
    {
        return [
            'widths'              => ['a.jpg 800w, b.jpg 400w', ['a.jpg', 'b.jpg']],
            'densities'           => ['a.jpg 1x, b.jpg 2x', ['a.jpg', 'b.jpg']],
            'no descriptors'      => ['a.jpg, b.jpg', ['a.jpg', 'b.jpg']],
            'no space after comma' => ['a.jpg 1x,b.jpg 2x', ['a.jpg', 'b.jpg']],
            'no spaces at all'    => ['a.jpg,b.jpg', ['a.jpg', 'b.jpg']],
            'single'              => ['a.jpg', ['a.jpg']],
            'line breaks'         => ["\n  a.jpg 800w,\n  b.jpg 400w\n", ['a.jpg', 'b.jpg']],
            'trailing comma'      => ['a.jpg 1x,', ['a.jpg']],
            'comma in the URL'    => ['https://x.test/resize/w_200,h_100/a.jpg 1x, b.jpg 2x', ['https://x.test/resize/w_200,h_100/a.jpg', 'b.jpg']],
            'encoded space'       => ['images/my%20photo.jpg 1x', ['images/my%20photo.jpg']],
            'empty'               => ['', []],
        ];
    }

    /**
     * @param  string[]  $expected
     */
    #[DataProvider('srcsets')]
    public function testSrcsetUrls(string $srcset, array $expected): void
    {
        $this->assertSame($expected, HtmlReferenceParser::srcsetUrls($srcset));
    }

    public function testCss(): void
    {
        $css = <<<'CSS'
            @import url("other.css");
            .a { background: url(../images/a.png); }
            .b { background-image: url( '../images/b.jpg' ), url("data:image/png;base64,AAAA"); }
            .c { background: URL(/images/c.svg#icon) }
            .d { background: url() }
            CSS;

        $raws = array_column((new HtmlReferenceParser())->parseCss($css), 'raw');

        $this->assertSame(['other.css', '../images/a.png', '../images/b.jpg', 'data:image/png;base64,AAAA', '/images/c.svg#icon'], $raws);
    }
}
