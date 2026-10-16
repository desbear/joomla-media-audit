<?php

/**
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Mediaaudit\Tests\Unit;

use Gss\Component\Mediaaudit\Administrator\Localizer\ContentRewriter;
use Gss\Component\Mediaaudit\Administrator\Service\CsvWriter;
use Gss\Component\Mediaaudit\Administrator\Service\HtmlReferenceParser;
use Gss\Component\Mediaaudit\Administrator\Service\JsonReferenceWalker;
use Gss\Component\Mediaaudit\Administrator\Service\MarkdownCarrier;
use Gss\Component\Mediaaudit\Administrator\Service\PathNormalizer;
use Gss\Component\Mediaaudit\Administrator\Service\ProgressStore;
use Gss\Component\Mediaaudit\Administrator\Service\ReferenceBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Content is written by authors, and not all of them mean well: what they write must not stop the
 * scan of the site, nor take minutes to read.
 */
final class HostileContentTest extends TestCase
{
    /**
     * @return  array<string, array{string}>
     */
    public static function noReferences(): array
    {
        return [
            'a byte that is no text'   => ['images/%FF.jpg'],
            'half a character'         => ['images/a%C0%AF.png'],
            'a zero byte'              => ['images/a%00.jpg'],
            'a line end'               => ['images/a%0Ab.jpg'],
            'a tab in front'           => ['%09=1+1.png'],
            'a sentence for a host'    => ['https://warning - log in at www.evil.test to fix it/x.png'],
            'a user in the URL'        => ['https://user@cdn.example.net/a.png'],
        ];
    }

    #[DataProvider('noReferences')]
    public function testWhatCannotBeStoredOrOpenedIsNoReference(string $raw): void
    {
        $this->assertNull((new PathNormalizer(['example.com']))->normalize($raw));
    }

    public function testHostsThatAreNames(): void
    {
        $normalizer = new PathNormalizer(['example.com']);

        foreach (['https://cdn.example.net/a.png', '//cdn.example.net:8080/a.png', 'https://my_host.example.net/a.png', 'https://例え.jp/a.png', 'http://cdn.example.net./a.png'] as $raw) {
            $this->assertTrue($normalizer->normalize($raw)?->isExternal, $raw);
        }

        // The domain of the site, written in full: a file of the site
        $own = $normalizer->normalize('http://www.example.com./images/a.png');

        $this->assertFalse($own->isExternal);
        $this->assertSame('images/a.png', $own->path);
    }

    /**
     * @return  array<string, array{string}>
     */
    public static function slow(): array
    {
        return [
            'backticks without end'    => [str_repeat('`', 40000) . str_repeat('a', 40000)],
            'runs of every length'     => [implode('a', array_map(static fn (int $k): string => str_repeat('`', $k), range(1, 600)))],
            'single backticks'         => [str_repeat('`a', 100000)],
            'fences that are not closed' => [str_repeat("```\nx\n", 30000)],
            'brackets'                 => [str_repeat('](', 50000)],
            'paths without end'        => [str_repeat('images/!', 25000)],
            'attributes and text'      => [implode('', array_map(static fn (int $i): string => '<img src="images/' . $i . '.png?v=1"> images/' . $i . '.png ', range(1, 10000)))],
            'many tags'                => [implode('', array_map(static fn (int $i): string => '<img src=images/' . $i . '.png>', range(1, 30000)))],
            'tags inside tags'         => [str_repeat('<div>', 30000)],
        ];
    }

    #[DataProvider('slow')]
    public function testContentThatIsMadeToBeSlowIsReadInNoTime(string $text): void
    {
        $parser  = new HtmlReferenceParser();
        $builder = new ReferenceBuilder(new PathNormalizer(['example.com']), $parser, new JsonReferenceWalker($parser));
        $carrier = '<p>x</p><div class="gssmd-source" hidden="hidden" data-gssmd="v1:0123456789abcdef:' . base64_encode($text) . '"></div>';
        $start   = microtime(true);
        $count   = 0;

        $builder->fromHtml($text, 'article', 1, 'introtext', 1);
        $builder->fromHtml($carrier, 'article', 1, 'introtext', 1);
        (new ContentRewriter())->rewrite($text, static fn (string $url): ?string => null);
        (new ContentRewriter())->rewrite($carrier, static fn (string $url): ?string => null);
        MarkdownCarrier::replace($text, static fn (string $url): ?string => null, $count);

        // Seconds for what took minutes; the machines that run the tests are not all fast
        $this->assertLessThan(5.0, microtime(true) - $start);
    }

    public function testCodeIsStillToldFromText(): void
    {
        $seen = [];

        $out = MarkdownCarrier::outsideCode(
            "a `b` c ``d ` e`` f ` g\n```\nh\n```\ni\n~~~~\nj\n~~~\nk\n~~~~\nl `m",
            static function (string $text) use (&$seen): string {
                $seen[] = $text;

                return strtoupper($text);
            }
        );

        $this->assertSame("A `b` C ``d ` e`` F ` G\n```\nh\n```\nI\n~~~~\nj\n~~~\nk\n~~~~\nL `M", $out);
    }

    public function testCellsThatASpreadsheetWouldCompute(): void
    {
        $csv = CsvWriter::write([['=1+1', "\t=1+1", "\r=1+1", ' =1+1', '+1', '-1', '@a', 'images/a.png', 12, null]]);

        $this->assertSame("'=1+1,\"'\t=1+1\",\"'\r=1+1\",\"' =1+1\",'+1,'-1,'@a,images/a.png,12,\n", $csv);
    }

    public function testATokenIsThirtyTwoHexadecimalDigitsAndNothingElse(): void
    {
        $this->assertTrue(ProgressStore::isToken(str_repeat('a1', 16)));

        foreach ([str_repeat('a1', 16) . "\n", str_repeat('A1', 16), '../' . str_repeat('a', 29), str_repeat('a', 31), ''] as $token) {
            $this->assertFalse(ProgressStore::isToken($token));
        }
    }
}
