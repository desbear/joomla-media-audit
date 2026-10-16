<?php

/**
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Mediaaudit\Tests\Unit;

use Gss\Component\Mediaaudit\Administrator\Localizer\ContentRewriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RewriteValueTest extends TestCase
{
    /**
     * @return  array<string, array{string, string, int}>
     */
    public static function values(): array
    {
        return [
            'a path and nothing else'  => ['images/a.png', 'images/a.avif', 1],
            'with spaces around it'    => ["  images/a.png\n", 'images/a.avif', 1],
            'a path of another image'  => ['images/a.gif', 'images/a.gif', 0],
            'text that is no path'     => ['A photo of images/a.png, more or less', 'A photo of images/a.png, more or less', 0],
            'HTML'                     => ['<p><img src="images/a.png"> images/a.png</p>', '<p><img src="images/a.avif"> images/a.png</p>', 1],
            'a style'                  => ['<div style="background:url(images/a.png)">x</div>', '<div style="background:url(images/a.avif)">x</div>', 1],
            'the value of a media field' => [
                '{"imagefile":"images\/a.png#joomlaImage:\/\/local-images\/a.png?width=8&height=6","alt_text":"a.png"}',
                '{"imagefile":"images\/a.avif#joomlaImage:\/\/local-images\/a.avif?width=8&height=6","alt_text":"a.png"}',
                1,
            ],
            'rows of a subform'        => [
                '{"row0":{"photo":{"imagefile":"images\/a.png","alt_text":""},"text":"<p><img src=\"images\/b.png\"><\/p>"},"row1":{"photo":"images\/c.gif"}}',
                '{"row0":{"photo":{"imagefile":"images\/a.avif","alt_text":""},"text":"<p><img src=\"images\/b.avif\"><\/p>"},"row1":{"photo":"images\/c.gif"}}',
                2,
            ],
            'JSON that holds no image' => ['{"a":1,"b":{}}', '{"a":1,"b":{}}', 0],
            'JSON stays what it was'   => [
                '{"photo":"images/a.png","empty":{},"none":[],"keys":{"0":"x","1":"y"},"one":1.0,"text":"\u4e2d"}',
                '{"photo":"images/a.avif","empty":{},"none":[],"keys":{"0":"x","1":"y"},"one":1.0,"text":"\u4e2d"}',
                1,
            ],
            'JSON with its own spelling' => ['{"photo":"images\/a.png","text":"中"}', '{"photo":"images\/a.avif","text":"中"}', 1],
            'nothing'                  => ['', '', 0],
        ];
    }

    #[DataProvider('values')]
    public function testRewriteValue(string $value, string $expected, int $count): void
    {
        $lookup = static fn (string $url): ?string => preg_match('#^images/[a-z]\.png#', $url) ? preg_replace('/\.png/', '.avif', $url) : null;

        $this->assertSame([$expected, $count], (new ContentRewriter())->rewriteValue($value, $lookup));
    }
}
