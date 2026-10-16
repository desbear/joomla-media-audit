<?php

/**
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Mediaaudit\Tests\Unit;

use Gss\Component\Mediaaudit\Administrator\Service\NormalizedPath;
use Gss\Component\Mediaaudit\Administrator\Service\PathNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PathNormalizerTest extends TestCase
{
    private function normalizer(string $basePath = ''): PathNormalizer
    {
        return new PathNormalizer(['mysite.example', 'faq.oldsite.example', 'www.example.com'], $basePath);
    }

    /**
     * @return  array<string, array{string, string}>
     */
    public static function localReferences(): array
    {
        return [
            // Rule 1: the suffix of the media field
            'media field suffix'            => ['images/a.jpg#joomlaImage://local-images/a.jpg?width=800&height=600', 'images/a.jpg'],
            'media field suffix, subfolder' => ['images/x/b.png#joomlaImage://local-images/x/b.png?width=1&height=2', 'images/x/b.png'],
            'media field suffix, any case'  => ['images/a.jpg#JOOMLAIMAGE://local-images/a.jpg', 'images/a.jpg'],

            // Rule 2: URL decoding
            'encoded Chinese name'          => ['images/%E7%94%A2%E5%93%81%E5%9C%96.png', 'images/產品圖.png'],
            'plain Chinese name'            => ['images/產品圖.png', 'images/產品圖.png'],
            'space as %20'                  => ['images/my%20photo.jpg', 'images/my photo.jpg'],
            'space as space'                => ['images/my photo.jpg', 'images/my photo.jpg'],
            'plus sign stays'               => ['images/a+b.jpg', 'images/a+b.jpg'],
            'plus sign as %2B'              => ['images/a%2Bb.jpg', 'images/a+b.jpg'],
            'encoded hash in the name'      => ['images/a%23b.jpg', 'images/a#b.jpg'],
            'encoded question mark'         => ['images/a%3Fb.jpg', 'images/a?b.jpg'],

            // Rule 3: query string and fragment
            'query string'                  => ['images/a.jpg?v=3', 'images/a.jpg'],
            'fragment'                      => ['images/a.svg#icon', 'images/a.svg'],
            'query string and fragment'     => ['images/a.jpg?v=3#top', 'images/a.jpg'],

            // Rule 4: absolute URLs of this site
            'https'                         => ['https://mysite.example/images/c.jpg', 'images/c.jpg'],
            'http'                          => ['http://mysite.example/images/c.jpg', 'images/c.jpg'],
            'domain of the old site'        => ['https://faq.oldsite.example/images/c.jpg', 'images/c.jpg'],
            'www added'                     => ['https://www.mysite.example/images/c.jpg', 'images/c.jpg'],
            'www dropped'                   => ['https://example.com/images/c.jpg', 'images/c.jpg'],
            'host in capitals'              => ['https://MYSITE.Example/images/c.jpg', 'images/c.jpg'],
            'with port'                     => ['https://mysite.example:8443/images/c.jpg', 'images/c.jpg'],
            'protocol relative, this site'  => ['//mysite.example/images/c.jpg', 'images/c.jpg'],
            'absolute with query string'    => ['https://mysite.example/images/c.jpg?x=1', 'images/c.jpg'],

            // Rule 5: leading slash
            'leading slash'                 => ['/images/f.jpg', 'images/f.jpg'],
            'two leading slashes inside'    => ['images//f.jpg', 'images/f.jpg'],

            // Rule 6: dots
            'dot segment'                   => ['./images/a.jpg', 'images/a.jpg'],
            'dot segment inside'            => ['images/./x/a.jpg', 'images/x/a.jpg'],
            'parent segment'                => ['images/x/../a.jpg', 'images/a.jpg'],
            'parent above the root'         => ['../../images/a.jpg', 'images/a.jpg'],

            // Rule 7: outside images/
            'media folder'                  => ['media/templates/site/cassiopeia/images/logo.svg', 'media/templates/site/cassiopeia/images/logo.svg'],
            'templates folder'              => ['/templates/vitalsupport/images/bg.png', 'templates/vitalsupport/images/bg.png'],

            // Rule 8: the case stays
            'capitals in the extension'     => ['images/Photo.JPG', 'images/Photo.JPG'],
            'capitals in the folder'        => ['Images/photo.jpg', 'Images/photo.jpg'],

            'whitespace around'             => ["  images/a.jpg\n", 'images/a.jpg'],
            'backslashes'                   => ['images\\x\\a.jpg', 'images/x/a.jpg'],
        ];
    }

    #[DataProvider('localReferences')]
    public function testLocalReference(string $raw, string $expected): void
    {
        $result = $this->normalizer()->normalize($raw);

        $this->assertNotNull($result);
        $this->assertFalse($result->isExternal);
        $this->assertSame($expected, $result->path);
    }

    /**
     * @return  array<string, array{string, string}>
     */
    public static function externalReferences(): array
    {
        return [
            'other site'                  => ['https://www.partner.example/images/a.jpg', 'https://www.partner.example/images/a.jpg'],
            'other site, http'            => ['http://km.oldsite.example/a.png', 'http://km.oldsite.example/a.png'],
            'protocol relative'           => ['//cdn.example.net/img/a.jpg', 'https://cdn.example.net/img/a.jpg'],
            'host in capitals'            => ['https://CDN.Example.NET/Img/A.jpg', 'https://cdn.example.net/Img/A.jpg'],
            'query string stays'          => ['https://cdn.example.net/a.jpg?w=200', 'https://cdn.example.net/a.jpg?w=200'],
            'fragment goes'               => ['https://cdn.example.net/a.jpg#x', 'https://cdn.example.net/a.jpg'],
            'subdomain of this site'      => ['https://other.mysite.example/images/a.jpg', 'https://other.mysite.example/images/a.jpg'],
            'script of another site'      => ['https://old.example.net/services/file.aspx?cmd=download&id=7', 'https://old.example.net/services/file.aspx?cmd=download&id=7'],
            'this site as a path elsewhere' => ['https://evil.test/mysite.example/images/a.jpg', 'https://evil.test/mysite.example/images/a.jpg'],
        ];
    }

    #[DataProvider('externalReferences')]
    public function testExternalReference(string $raw, string $expected): void
    {
        $result = $this->normalizer()->normalize($raw);

        $this->assertNotNull($result);
        $this->assertTrue($result->isExternal);
        $this->assertSame($expected, $result->path);
    }

    /**
     * @return  array<string, array{string}>
     */
    public static function ignoredReferences(): array
    {
        return [
            'data URI'         => ['data:image/png;base64,iVBORw0KGgo='],
            'data URI, capitals' => ['DATA:image/gif;base64,R0lGODlh'],
            'blob'             => ['blob:https://example.com/0f1e'],
            'javascript'       => ['javascript:void(0)'],
            'mailto'           => ['mailto:someone@example.com'],
            'anchor'           => ['#top'],
            'query only'       => ['?page=2'],
            'empty'            => [''],
            'whitespace'       => ['   '],
            'root of the site' => ['/'],
            'this site, no path' => ['https://mysite.example'],
            'script of this site' => ['index.php?option=com_x&task=image&id=3'],
            'script, no query' => ['/index.php'],
            'script of this site, absolute' => ['https://faq.oldsite.example/services/ajaxdocumentservice.aspx?cmd=downloadckfile&subjectId=ee8a591b'],
            'route of this site' => ['/component/gallery/image?id=3'],
        ];
    }

    #[DataProvider('ignoredReferences')]
    public function testIgnoredReference(string $raw): void
    {
        $this->assertNull($this->normalizer()->normalize($raw));
    }

    public function testAFileWithoutExtensionIsAFile(): void
    {
        $this->assertSame('images/README', $this->normalizer()->normalize('images/README')->path);
        $this->assertSame('images/a.jpg', $this->normalizer()->normalize('images/a.jpg?v=index.php')->path);
    }

    public function testBasePathOfASiteInASubfolder(): void
    {
        $normalizer = $this->normalizer('/portal/');

        $this->assertSame('images/a.jpg', $normalizer->normalize('/portal/images/a.jpg')->path);
        $this->assertSame('images/a.jpg', $normalizer->normalize('https://mysite.example/portal/images/a.jpg')->path);

        // Relative references start at the base of the site already
        $this->assertSame('images/a.jpg', $normalizer->normalize('images/a.jpg')->path);
        $this->assertSame('portal/images/a.jpg', $normalizer->normalize('portal/images/a.jpg')->path);

        // Another folder of the same domain is not this site, but it is a path of this domain
        $this->assertSame('other/images/a.jpg', $normalizer->normalize('/other/images/a.jpg')->path);
        $this->assertSame('portalx/a.jpg', $normalizer->normalize('/portalx/a.jpg')->path);

        $this->assertNull($normalizer->normalize('/portal/'));
    }

    public function testRelativeToTheFolderOfAStyleSheet(): void
    {
        $normalizer = $this->normalizer();
        $folder     = 'media/templates/site/cassiopeia/css';

        $this->assertSame('images/bg.png', $normalizer->normalize('../../../../../images/bg.png', $folder)->path);
        $this->assertSame('media/templates/site/cassiopeia/images/logo.svg', $normalizer->normalize('../images/logo.svg', $folder)->path);
        $this->assertSame('media/templates/site/cassiopeia/css/x.png', $normalizer->normalize('x.png', $folder)->path);

        // The folder does not matter for what starts at the root
        $this->assertSame('images/bg.png', $normalizer->normalize('/images/bg.png', $folder)->path);
        $this->assertSame('images/bg.png', $normalizer->normalize('https://mysite.example/images/bg.png', $folder)->path);
    }

    public function testUnicodeIsComposed(): void
    {
        // "é" as one code point, and as "e" followed by a combining accent
        $composed   = "images/caf\u{00E9}.jpg";
        $decomposed = "images/cafe\u{0301}.jpg";

        $normalizer = $this->normalizer();

        $this->assertSame($composed, $normalizer->normalize($decomposed)->path);
        $this->assertSame($normalizer->normalize($composed)->hash(), $normalizer->normalize($decomposed)->hash());
        $this->assertSame($composed, $normalizer->normalize(rawurlencode($decomposed))->path);
    }

    public function testCaseMakesAnotherPathButTheSameCaseInsensitiveHash(): void
    {
        $normalizer = $this->normalizer();
        $written    = $normalizer->normalize('images/Photo.JPG');
        $real       = $normalizer->normalize('images/photo.jpg');

        $this->assertNotSame($written->hash(), $real->hash());
        $this->assertSame($written->caseInsensitiveHash(), $real->caseInsensitiveHash());
        $this->assertSame(sha1('images/Photo.JPG'), $written->hash());
    }

    public function testWithoutSiteDomainsEveryAbsoluteUrlIsExternal(): void
    {
        $result = (new PathNormalizer())->normalize('https://mysite.example/images/a.jpg');

        $this->assertTrue($result->isExternal);
    }

    public function testDomainsFromTheOptions(): void
    {
        $normalizer = PathNormalizer::fromOptions("https://mysite.example/\n  faq.oldsite.example , WWW.Example.com:443\n\n");

        foreach (['mysite.example', 'faq.oldsite.example', 'example.com'] as $host) {
            $this->assertFalse($normalizer->normalize("https://$host/images/a.jpg")->isExternal, $host);
        }
    }

    public function testLooksLikeImage(): void
    {
        foreach (['a.jpg', 'a.JPEG', 'x/a.png?v=1', 'a.svg#icon', 'a.webp', 'a.avif', 'a.gif', 'images/a.jpg#joomlaImage://local-images/a.jpg?width=1'] as $value) {
            $this->assertTrue(PathNormalizer::looksLikeImage($value), $value);
        }

        foreach (['a.pdf', 'a.jpg.txt', 'jpg', 'index.php?file=a', '', 'a.jpgx'] as $value) {
            $this->assertFalse(PathNormalizer::looksLikeImage($value), $value);
        }
    }

    public function testIsUnder(): void
    {
        $this->assertTrue((new NormalizedPath('images/a.jpg'))->isUnder('images'));
        $this->assertTrue((new NormalizedPath('images/x/a.jpg'))->isUnder('images/'));
        $this->assertFalse((new NormalizedPath('images2/a.jpg'))->isUnder('images'));
        $this->assertFalse((new NormalizedPath('media/images/a.jpg'))->isUnder('images'));
        $this->assertFalse((new NormalizedPath('https://x.test/images/a.jpg', true))->isUnder('https://x.test'));
    }
}
