<?php

/**
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Mediaaudit\Tests\Unit;

use Gss\Component\Mediaaudit\Administrator\Localizer\ContentRewriter;
use Gss\Component\Mediaaudit\Administrator\Localizer\SaveLocalizer;
use Gss\Component\Mediaaudit\Administrator\Service\HtmlReferenceParser;
use Gss\Component\Mediaaudit\Administrator\Service\JsonReferenceWalker;
use Gss\Component\Mediaaudit\Administrator\Service\PathNormalizer;
use Gss\Component\Mediaaudit\Administrator\Service\ReferenceBuilder;
use PHPUnit\Framework\TestCase;

final class SaveLocalizerTest extends TestCase
{
    /** @var string[]  The URLs that the test was asked to copy */
    private array $asked = [];

    private function localizer(): SaveLocalizer
    {
        $parser = new HtmlReferenceParser();

        return new SaveLocalizer(
            new ReferenceBuilder(new PathNormalizer(['support.example.com']), $parser, new JsonReferenceWalker($parser)),
            new ContentRewriter()
        );
    }

    /**
     * Copies everything but what has "broken" in its URL.
     */
    private function copier(): callable
    {
        $this->asked = [];

        return function (string $url, string $hash, float $left): ?string {
            $this->asked[] = $url;

            return $left <= 0 || str_contains($url, 'broken') ? null : 'images/localized/' . substr($hash, 0, 6) . '.png';
        };
    }

    public function testExternalImagesOfTheNamedHostsAreReplaced(): void
    {
        $old  = 'https://faq.example.com/s.aspx?cmd=d&id=1&fileName=a.png';
        $html = '<p><img src="' . htmlspecialchars($old) . '"><img src="https://other.example.net/b.png"><img src="images/local.jpg">'
            . '<img src="https://support.example.com/images/own.jpg"></p>';

        $result = $this->localizer()->localize(['introtext' => $html, 'fulltext' => ''], [], ['faq.example.com'], $this->copier());

        $copy = 'images/localized/' . substr(sha1($old), 0, 6) . '.png';

        $this->assertSame([$old], $this->asked);
        $this->assertSame(['introtext' => str_replace(htmlspecialchars($old), $copy, $html)], $result['columns']);
        $this->assertSame(1, $result['replaced']);
        $this->assertSame(1, $result['copied']);
        $this->assertSame(0, $result['failed']);
    }

    public function testNoHostsMeansEveryHost(): void
    {
        $html   = '<img src="https://a.example.net/1.png"><img src="//b.example.net/2.png">';
        $result = $this->localizer()->localize(['content' => $html], [], [], $this->copier());

        $this->assertSame(['https://a.example.net/1.png', 'https://b.example.net/2.png'], $this->asked);
        $this->assertSame(2, $result['replaced']);
        $this->assertStringNotContainsString('example.net', $result['columns']['content']);
    }

    public function testOnlyImagesAreFetched(): void
    {
        $html = '<a href="https://faq.example.com/page.html">page</a><iframe src="https://faq.example.com/embed"></iframe>'
            . '<script src="https://faq.example.com/x.js"></script><a href="https://faq.example.com/big.jpg">big</a>';

        $result = $this->localizer()->localize(['introtext' => $html], [], ['faq.example.com'], $this->copier());

        $this->assertSame(['https://faq.example.com/big.jpg'], $this->asked);
        $this->assertSame(1, $result['replaced']);
        $this->assertStringContainsString('<a href="https://faq.example.com/page.html">', $result['columns']['introtext']);
        $this->assertStringContainsString('<iframe src="https://faq.example.com/embed">', $result['columns']['introtext']);
    }

    public function testAnImageThatCannotBeCopiedStays(): void
    {
        $html   = '<img src="https://faq.example.com/broken.png"><img src="https://faq.example.com/fine.png">';
        $result = $this->localizer()->localize(['introtext' => $html], [], ['faq.example.com'], $this->copier());

        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $result['replaced']);
        $this->assertStringContainsString('<img src="https://faq.example.com/broken.png">', $result['columns']['introtext']);
    }

    public function testNoTimeLeft(): void
    {
        $html   = '<img src="https://faq.example.com/a.png">';
        $result = $this->localizer()->localize(['introtext' => $html], [], ['faq.example.com'], $this->copier(), 0.0);

        $this->assertSame([], $result['columns']);
        $this->assertSame(1, $result['postponed']);
        $this->assertSame(0, $result['failed']);
    }

    public function testTheSameImageTwiceIsCopiedOnce(): void
    {
        $html   = '<img src="https://faq.example.com/a.png">';
        $result = $this->localizer()->localize(['introtext' => $html, 'fulltext' => $html], [], ['faq.example.com'], $this->copier());

        $this->assertSame(['https://faq.example.com/a.png'], $this->asked);
        $this->assertSame(1, $result['copied']);
        $this->assertSame(2, $result['replaced']);
        $this->assertSame(['introtext', 'fulltext'], array_keys($result['columns']));
    }

    public function testJsonColumns(): void
    {
        $json   = json_encode(['image_intro' => 'https://faq.example.com/intro.png', 'image_intro_alt' => 'x', 'image_fulltext' => 'images/own.jpg']);
        $result = $this->localizer()->localize([], ['images' => $json], ['faq.example.com'], $this->copier());

        $this->assertSame(1, $result['replaced']);
        $this->assertSame(
            ['image_intro' => 'images/localized/' . substr(sha1('https://faq.example.com/intro.png'), 0, 6) . '.png', 'image_intro_alt' => 'x', 'image_fulltext' => 'images/own.jpg'],
            json_decode($result['columns']['images'], true)
        );
    }

    public function testNothingExternal(): void
    {
        $result = $this->localizer()->localize(['introtext' => '<img src="images/a.jpg">', 'fulltext' => null], ['images' => '{}'], [], $this->copier());

        $this->assertSame([], $this->asked);
        $this->assertSame([], $result['columns']);
    }
}
