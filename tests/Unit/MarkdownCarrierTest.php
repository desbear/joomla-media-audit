<?php

/**
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Mediaaudit\Tests\Unit;

use Gss\Component\Mediaaudit\Administrator\Converter\UrlSwapper;
use Gss\Component\Mediaaudit\Administrator\Localizer\ContentRewriter;
use Gss\Component\Mediaaudit\Administrator\Service\HtmlReferenceParser;
use Gss\Component\Mediaaudit\Administrator\Service\JsonReferenceWalker;
use Gss\Component\Mediaaudit\Administrator\Service\MarkdownCarrier;
use Gss\Component\Mediaaudit\Administrator\Service\PathNormalizer;
use Gss\Component\Mediaaudit\Administrator\Service\ReferenceBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownCarrierTest extends TestCase
{
    private const FINGERPRINT = '0123456789abcdef';

    private static function carrier(string $markdown): string
    {
        return '<div class="gssmd-source" hidden="hidden" data-gssmd="v1:' . self::FINGERPRINT . ':' . base64_encode($markdown) . '"></div>';
    }

    /**
     * Every PNG below images/ has an AVIF.
     */
    private static function lookup(): callable
    {
        return static function (string $url): ?string {
            if (!preg_match('#(^|/)images/.*\.png(?:[?\#]|$)#', rawurldecode($url))) {
                return null;
            }

            $name = rawurldecode(basename(preg_replace('/[?#].*$/', '', $url)));

            return UrlSwapper::swap($url, $name, preg_replace('/\.png$/', '.avif', $name));
        };
    }

    /**
     * @return  array<string, array{string, string}>
     */
    public static function markdown(): array
    {
        return [
            'an image'                  => ['![a](images/a.png)', '![a](images/a.avif)'],
            'with a title'              => ['![a](images/a.png "The title")', '![a](images/a.avif "The title")'],
            'a link to an image'        => ['[see](/images/a.png)', '[see](/images/a.avif)'],
            'an image in a link'        => ['[![a](images/a.png)](images/b.png)', '[![a](images/a.avif)](images/b.avif)'],
            'in brackets, with a space' => ['![a](<images/my photo.png>)', '![a](<images/my photo.avif>)'],
            'escaped space'             => ['![a](images/my%20photo.png)', '![a](images/my%20photo.avif)'],
            'brackets in the name'      => ['![a](images/photo(1).png)', '![a](images/photo(1).avif)'],
            'text with brackets'        => ['![a [b] c](images/a.png)', '![a [b] c](images/a.avif)'],
            'a definition'              => ["![a][one]\n\n[one]: images/a.png \"Title\"", "![a][one]\n\n[one]: images/a.avif \"Title\""],
            'with the domain'           => ['![a](https://example.com/images/a.png?v=2)', '![a](https://example.com/images/a.avif?v=2)'],
            'Chinese name'              => ['![圖](images/截圖.png)', '![圖](images/截圖.avif)'],
            'two in a line'             => ['![a](images/a.png) and ![b](images/b.png)', '![a](images/a.avif) and ![b](images/b.avif)'],
            'another image stays'       => ['![a](images/a.gif) ![b](media/b.png)', '![a](images/a.gif) ![b](media/b.png)'],
            'a link to a page stays'    => ['[page](index.php?id=1)', '[page](index.php?id=1)'],
            'code between fences stays' => ["```md\n![a](images/a.png)\n```\n\n![b](images/b.png)", "```md\n![a](images/a.png)\n```\n\n![b](images/b.avif)"],
            'code in a line stays'      => ['Write `![a](images/a.png)` for ![b](images/b.png)', 'Write `![a](images/a.png)` for ![b](images/b.avif)'],
            'fence that is not closed'  => ["![b](images/b.png)\n\n~~~\n![a](images/a.png)", "![b](images/b.avif)\n\n~~~\n![a](images/a.png)"],
        ];
    }

    #[DataProvider('markdown')]
    public function testReplace(string $markdown, string $expected): void
    {
        $count = 0;

        $this->assertSame($expected, MarkdownCarrier::replace($markdown, self::lookup(), $count));
        $this->assertSame($markdown === $expected ? 0 : substr_count($expected, '.avif'), $count);
    }

    public function testDestinations(): void
    {
        $found = MarkdownCarrier::destinations(
            "![a](images/a.png \"T\") [b](images/b.png) [page](index.php) ![c](<images/c d.png>)\n\n[one]: images/e.png\n\n```\n![x](images/x.png)\n```"
        );

        $this->assertSame(
            [
                ['url' => 'images/a.png', 'image' => true],
                ['url' => 'images/b.png', 'image' => false],
                ['url' => 'index.php', 'image' => false],
                ['url' => 'images/c d.png', 'image' => true],
                ['url' => 'images/e.png', 'image' => false],
            ],
            $found
        );
    }

    public function testContentWithMarkdownIsRewrittenInBothPlaces(): void
    {
        $markdown = "# Title\n\n![a](images/a.png)\n\n<img src=\"images/b.png\" width=\"300\">\n";
        $content  = '<h1>Title</h1><p><img src="images/a.png" alt="a"></p><p><img src="images/b.png" width="300"></p>' . "\n" . self::carrier($markdown);

        [$rewritten, $count] = (new ContentRewriter())->rewrite($content, self::lookup());

        $this->assertSame(2, $count);
        $this->assertStringStartsWith('<h1>Title</h1><p><img src="images/a.avif" alt="a"></p><p><img src="images/b.avif" width="300"></p>', $rewritten);
        $this->assertSame(
            ["# Title\n\n![a](images/a.avif)\n\n<img src=\"images/b.avif\" width=\"300\">\n"],
            MarkdownCarrier::sources($rewritten)
        );

        // The fingerprint is one of the text that is read, and the text is as it was
        $this->assertStringContainsString('data-gssmd="v1:' . self::FINGERPRINT . ':', $rewritten);
        $this->assertStringContainsString('<div class="gssmd-source" hidden="hidden" data-gssmd=', $rewritten);
        $this->assertStringEndsWith('"></div>', $rewritten);
    }

    public function testMarkdownThatWasLeftBehindIsRewritten(): void
    {
        $content = '<p><img src="images/a.avif" alt="a"></p>' . "\n" . self::carrier('![a](images/a.png)');

        [$rewritten, $count] = (new ContentRewriter())->rewrite($content, self::lookup());

        $this->assertSame(1, $count);
        $this->assertSame(['![a](images/a.avif)'], MarkdownCarrier::sources($rewritten));
    }

    public function testTheFirstWayToKeepMarkdownIsRead(): void
    {
        $content = '<p><img src="images/a.png"></p><!--GSSMD:v1:base64:' . base64_encode('![a](images/a.png)') . '-->';

        [$rewritten] = (new ContentRewriter())->rewrite($content, self::lookup());

        $this->assertSame(['![a](images/a.avif)'], MarkdownCarrier::sources($rewritten));
    }

    public function testContentWithoutMarkdownIsAsBefore(): void
    {
        $content = '<p><img src="images/a.png"> <span data-gssmd-x="1">![a](images/b.png)</span></p>';

        [$rewritten, $count] = (new ContentRewriter())->rewrite($content, self::lookup());

        $this->assertSame('<p><img src="images/a.avif"> <span data-gssmd-x="1">![a](images/b.png)</span></p>', $rewritten);
        $this->assertSame(1, $count);
    }

    public function testWhatIsNoMarkdownIsLeftAlone(): void
    {
        $content = '<p>x</p><div data-gssmd="v1:' . self::FINGERPRINT . ':' . base64_encode("\xff\xfe not text") . '"></div>';

        $this->assertSame([$content, 0], (new ContentRewriter())->rewrite($content, self::lookup()));
        $this->assertSame([], MarkdownCarrier::sources($content));
    }

    public function testReferencesOfTheMarkdown(): void
    {
        $parser  = new HtmlReferenceParser();
        $builder = new ReferenceBuilder(new PathNormalizer(['example.com']), $parser, new JsonReferenceWalker($parser));

        // The HTML loads the AVIF; the Markdown is of before
        $content = '<p><img src="images/a.avif"><img src="images/same.png"></p>'
            . self::carrier("![a](images/a.png) ![s](images/same.png) [page](index.php?id=2) <img src=\"https://example.com/images/h.png\">");

        $references = ReferenceBuilder::unique($builder->fromHtml($content, 'article', 7, 'introtext', 1));
        $fields     = [];

        foreach ($references as $reference) {
            $fields[$reference->target->path] = $reference->field;
        }

        ksort($fields);

        $this->assertSame(
            ['images/a.avif' => 'introtext', 'images/a.png' => 'introtext#markdown', 'images/h.png' => 'introtext#markdown', 'images/same.png' => 'introtext'],
            $fields
        );
    }
}
