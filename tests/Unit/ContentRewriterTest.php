<?php

/**
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Mediaaudit\Tests\Unit;

use Gss\Component\Mediaaudit\Administrator\Localizer\ContentRewriter;
use PHPUnit\Framework\TestCase;

final class ContentRewriterTest extends TestCase
{
    private const OLD = 'https://faq.example.com/services/file.aspx?cmd=download&id=7&fileName=%E5%9C%96.png';
    private const NEW = 'images/localized/faq.example.com/0123456789-image.png';

    /**
     * @return  array{0: string, 1: int}
     */
    private function rewrite(string $html, array $map = [self::OLD => self::NEW]): array
    {
        return (new ContentRewriter())->rewrite($html, static fn (string $url): ?string => $map[$url] ?? null);
    }

    public function testImgSrcWithEntities(): void
    {
        $old = htmlspecialchars(self::OLD);

        $this->assertStringContainsString('&amp;', $old);
        $this->assertSame(
            ['<p>Before <img src="' . self::NEW . '" alt="" /> after</p>', 1],
            $this->rewrite('<p>Before <img src="' . $old . '" alt="" /> after</p>')
        );
    }

    public function testAmpersandsThatAreNotEncoded(): void
    {
        $this->assertSame(['<img src="' . self::NEW . '">', 1], $this->rewrite('<img src="' . self::OLD . '">'));
    }

    public function testEverythingElseStaysByteForByte(): void
    {
        $html = "<P  CLASS='x'>&nbsp;Tom &amp; Jerry<br/>\n<IMG  ALT='a \"b\"' SRC='" . htmlspecialchars(self::OLD) . "'  width=100 >"
            . '<img src="images/local.jpg"><a href="https://other.example.com/x.png?a=1&amp;b=2">x</a><!-- ' . self::OLD . ' --></p>';

        [$rewritten, $count] = $this->rewrite($html);

        $this->assertSame(1, $count);
        $this->assertSame(str_replace(htmlspecialchars(self::OLD), self::NEW, $html), $rewritten);
    }

    public function testSeveralAttributesOfOneTag(): void
    {
        $map  = ['https://a.test/1.png' => 'images/l/1.png', 'https://a.test/2.png' => 'images/l/2.png'];
        $html = '<img src="https://a.test/1.png" data-src="https://a.test/2.png" data-other="https://a.test/3.png">';

        $this->assertSame(
            ['<img src="images/l/1.png" data-src="images/l/2.png" data-other="https://a.test/3.png">', 2],
            $this->rewrite($html, $map)
        );
    }

    public function testSrcset(): void
    {
        $map  = ['https://a.test/1.png' => 'images/l/1.png', 'https://a.test/2.png' => 'images/l/2 x.png'];
        $html = '<img srcset="https://a.test/1.png 1x, https://a.test/2.png 2x, https://a.test/3.png 3x">';

        $this->assertSame(
            ['<img srcset="images/l/1.png 1x, images/l/2%20x.png 2x, https://a.test/3.png 3x">', 2],
            $this->rewrite($html, $map)
        );
    }

    public function testInlineStyleAndStyleElement(): void
    {
        $map  = ['https://a.test/bg.png' => 'images/l/bg.png'];
        $html = '<div style="color:red;background:url(&quot;https://a.test/bg.png&quot;) no-repeat">x</div>'
            . '<style>.a{background:url(https://a.test/bg.png)}</style>';

        $this->assertSame(
            ['<div style="color:red;background:url(&quot;images/l/bg.png&quot;) no-repeat">x</div><style>.a{background:url(images/l/bg.png)}</style>', 2],
            $this->rewrite($html, $map)
        );
    }

    /**
     * What looks like an attribute inside the value of another one is text. Rewriting it would
     * decode its entities and write them back as quotes, which end the value they are in.
     *
     * @return  array<string, array{string}>
     */
    public static function hidden(): array
    {
        return [
            'src in an alt'             => ['<img src="https://a.test/1.png" alt="x src=\'https://a.test/1.png?&quot; onload=alert(1) x\' y">'],
            'srcset in a title'         => ['<p title="x srcset=\'https://a.test/1.png 1x, &quot; onmouseover=alert(1) x\' y">t</p>'],
            'style in a title'          => ['<p title="x style=\'background:url(https://a.test/1.png);&quot; onmouseover=alert(1) x\' y">t</p>'],
            'single outside, double in' => ["<img src='https://a.test/1.png' alt='x src=\"https://a.test/1.png?&#39; onload=alert(1) x\" y'>"],
            'href in a data attribute'  => ['<a href="https://a.test/1.png" data-note="href=\'https://a.test/1.png&quot; onclick=alert(1) x=\'">l</a>'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hidden')]
    public function testWhatIsWrittenInsideAValueDoesNotBecomeAnAttribute(string $html): void
    {
        $names = static function (string $html): array {
            $document = new \DOMDocument();

            @$document->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');

            $found = [];

            foreach ($document->getElementsByTagName('body')->item(0)->firstChild->attributes as $attribute) {
                $found[] = $attribute->name;
            }

            return $found;
        };

        [$rewritten] = $this->rewrite($html, ['https://a.test/1.png' => 'images/l/1.png', 'https://a.test/1.png?" onload=alert(1) x' => 'images/l/1.png']);

        $this->assertSame($names($html), $names($rewritten));
        $this->assertStringNotContainsString('" on', $rewritten);
        $this->assertStringNotContainsString("' on", $rewritten);
    }

    public function testBothKindsOfQuotesAreEncoded(): void
    {
        $lookup = static fn (string $url): ?string => $url === 'a.png' ? 'it\'s "new".png' : null;

        $this->assertSame(
            ['<img src="it&#039;s &quot;new&quot;.png"><img src=\'it&#039;s &quot;new&quot;.png\'><img src="it&#039;s &quot;new&quot;.png">', 3],
            (new ContentRewriter())->rewrite('<img src="a.png"><img src=\'a.png\'><img src=a.png>', $lookup)
        );
    }

    public function testACandidateOfASrcsetIsReplacedWhereItStands(): void
    {
        $this->assertSame(
            ['<img srcset="images/l/1.png 1x, other/https://a.test/1.png 2x, https://a.test/1.png.png 3x">', 1],
            $this->rewrite('<img srcset="https://a.test/1.png 1x, other/https://a.test/1.png 2x, https://a.test/1.png.png 3x">', ['https://a.test/1.png' => 'images/l/1.png'])
        );
    }

    public function testAUrlThatIsWrittenInAStringOfAStyleSheetIsText(): void
    {
        $css = '<style>.x{content:\'url("https://a.test/1.png?;}body{display:none}y{")\'} .y{background:url("https://a.test/1.png")} /* url(https://a.test/1.png) */</style>';

        $this->assertSame(
            ['<style>.x{content:\'url("https://a.test/1.png?;}body{display:none}y{")\'} .y{background:url("images/l/1.png")} /* url(https://a.test/1.png) */</style>', 1],
            $this->rewrite($css, ['https://a.test/1.png' => 'images/l/1.png', 'https://a.test/1.png?;}body{display:none}y{' => 'images/l/1.png'])
        );
    }

    public function testTagsThatNoBrowserWouldReadAreLeftAsTheyAre(): void
    {
        foreach (['<img src="https://a.test/9.png" "broken>', '<img =x src=y>', '<p a=`b` c>', '<br/>', '<img/src="https://a.test/9.png"/>'] as $html) {
            $this->assertSame([$html, 0], $this->rewrite($html, ['https://a.test/1.png' => 'images/l/1.png']));
        }
    }

    public function testLinkToAnImage(): void
    {
        $map = ['https://a.test/big.jpg' => 'images/l/big.jpg'];

        $this->assertSame(
            ['<a href="images/l/big.jpg"><img src="images/thumb.jpg"></a>', 1],
            $this->rewrite('<a href="https://a.test/big.jpg"><img src="images/thumb.jpg"></a>', $map)
        );
    }

    public function testTextThatLooksLikeAnAttributeIsLeftAlone(): void
    {
        $html = '<p>Write src="' . self::OLD . '" in the editor.</p><pre>&lt;img src="' . self::OLD . '"&gt;</pre>';

        $this->assertSame([$html, 0], $this->rewrite($html));
    }

    public function testAQuotedValueWithATagEnd(): void
    {
        $html = '<img alt="a > b" src="' . self::OLD . '">';

        $this->assertSame(['<img alt="a > b" src="' . self::NEW . '">', 1], $this->rewrite($html));
    }

    public function testNothingToReplace(): void
    {
        $html = '<p><img src="images/a.jpg"></p>';

        $this->assertSame([$html, 0], $this->rewrite($html));
        $this->assertSame(['', 0], $this->rewrite(''));
    }

    public function testLargeContentIsNotDamaged(): void
    {
        $html = '<img src="data:image/png;base64,' . str_repeat('A', 3 * 1024 * 1024) . '"><img src="' . self::OLD . '">';

        [$rewritten, $count] = $this->rewrite($html);

        // Replaced, or left as it was: never cut
        $this->assertContains($count, [0, 1]);
        $this->assertSame($count === 1 ? str_replace(self::OLD, self::NEW, $html) : $html, $rewritten);
    }

    public function testLostImagesBecomeANote(): void
    {
        $lost = static fn (string $url): bool => str_starts_with($url, 'https://lost.example.com/');
        $html = '<p>Step 1 <img alt="" src="https://lost.example.com/a/b.png?x=1&amp;y=2" width="600" /> then '
            . '<img src="https://lost.example.com/c.png" alt="The &quot;Save&quot; button"> and <img src="images/kept.jpg" alt="kept">'
            . '<a href="https://lost.example.com/page">link</a></p>';

        [$rewritten, $count] = (new ContentRewriter())->removeImages($html, $lost, '原圖遺失');

        $this->assertSame(2, $count);
        $this->assertSame(
            '<p>Step 1 <span class="mediaaudit-missing-image">原圖遺失</span> then '
            . '<span class="mediaaudit-missing-image" title="The &quot;Save&quot; button">原圖遺失</span> and <img src="images/kept.jpg" alt="kept">'
            . '<a href="https://lost.example.com/page">link</a></p>',
            $rewritten
        );
    }

    public function testTheNoteIsEncoded(): void
    {
        [$rewritten] = (new ContentRewriter())->removeImages('<img src="x.png">', static fn (): bool => true, '<b>lost</b> & gone');

        $this->assertSame('<span class="mediaaudit-missing-image">&lt;b&gt;lost&lt;/b&gt; &amp; gone</span>', $rewritten);
    }

    public function testNoImageIsLost(): void
    {
        $html = '<p><img src="images/a.jpg"><img alt="no source"></p>';

        $this->assertSame([$html, 0], (new ContentRewriter())->removeImages($html, static fn (): bool => false, 'x'));
        $this->assertSame(['<p>text</p>', 0], (new ContentRewriter())->removeImages('<p>text</p>', static fn (): bool => true, 'x'));
    }

    public function testTheNoteIsNotReadAsAnImage(): void
    {
        [$rewritten] = (new ContentRewriter())->removeImages('<img src="https://lost.example.com/c.png" alt="c.png">', static fn (): bool => true, '原圖遺失');

        $this->assertSame([], (new \Gss\Component\Mediaaudit\Administrator\Service\HtmlReferenceParser())->parse($rewritten));
    }

    public function testJson(): void
    {
        $rewriter = new ContentRewriter();
        $lookup   = static fn (string $url): ?string => [self::OLD => self::NEW][$url] ?? null;
        $json     = json_encode(['image_intro' => self::OLD, 'image_intro_alt' => '圖', 'nested' => ['html' => '<img src="' . htmlspecialchars(self::OLD) . '">'], 'n' => 3]);

        [$rewritten, $count] = $rewriter->rewriteJson($json, $lookup);

        $this->assertSame(2, $count);
        $this->assertSame(
            ['image_intro' => self::NEW, 'image_intro_alt' => '圖', 'nested' => ['html' => '<img src="' . self::NEW . '">'], 'n' => 3],
            json_decode($rewritten, true)
        );

        // Untouched JSON comes back as it was written
        $this->assertSame(['{"a" : "images/a.jpg"}', 0], $rewriter->rewriteJson('{"a" : "images/a.jpg"}', $lookup));
        $this->assertSame(['not json', 0], $rewriter->rewriteJson('not json', $lookup));
    }
}
