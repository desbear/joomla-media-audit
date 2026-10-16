<?php

/**
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Mediaaudit\Tests\Unit;

use Gss\Component\Mediaaudit\Administrator\Service\HtmlReferenceParser;
use Gss\Component\Mediaaudit\Administrator\Service\JsonReferenceWalker;
use PHPUnit\Framework\TestCase;

final class JsonReferenceWalkerTest extends TestCase
{
    /**
     * @return  array<string, string>  The reference of every field.
     */
    private function walk($json, string $prefix = 'params'): array
    {
        $found = (new JsonReferenceWalker(new HtmlReferenceParser()))->walk($json, $prefix);

        return array_column($found, 'raw', 'field');
    }

    public function testImagesOfAnArticle(): void
    {
        $json = json_encode([
            'image_intro'        => 'images/b.jpg#joomlaImage://local-images/b.jpg?width=800&height=600',
            'image_intro_alt'    => 'A photo.jpg of something',
            'float_intro'        => '',
            'image_fulltext'     => 'images/full.png',
            'image_fulltext_alt' => '',
        ]);

        $this->assertSame(
            [
                // The alternative text names a file, but it does not end like one
                'images.image_intro'    => 'images/b.jpg#joomlaImage://local-images/b.jpg?width=800&height=600',
                'images.image_fulltext' => 'images/full.png',
            ],
            $this->walk($json, 'images')
        );
    }

    public function testNestedValues(): void
    {
        $json = ['menu_image' => 'images/menu.png', 'slides' => [['image' => 'images/s1.jpg'], ['image' => 'images/s2.jpg']], 'count' => 3, 'on' => true];

        $this->assertSame(
            ['params.menu_image' => 'images/menu.png', 'params.slides.0.image' => 'images/s1.jpg', 'params.slides.1.image' => 'images/s2.jpg'],
            $this->walk($json)
        );
    }

    public function testJsonInsideJson(): void
    {
        $json = ['rows' => json_encode(['row0' => ['picture' => 'images/inner.webp']])];

        $this->assertSame(['params.rows.row0.picture' => 'images/inner.webp'], $this->walk($json));
    }

    public function testHtmlInsideJson(): void
    {
        $json = ['text' => '<p><img src="images/in-html.jpg"></p>', 'css' => 'background: url(images/in-css.png)'];

        $this->assertSame(['params.text' => 'images/in-html.jpg', 'params.css' => 'images/in-css.png'], $this->walk($json));
    }

    public function testObjects(): void
    {
        $this->assertSame(['params.image' => 'images/cat.jpg'], $this->walk((object) ['image' => 'images/cat.jpg']));
    }

    public function testWhatIsNotJson(): void
    {
        $this->assertSame([], $this->walk(''));
        $this->assertSame([], $this->walk('not json'));
        $this->assertSame([], $this->walk('"images/a.jpg"'));
        $this->assertSame([], $this->walk(null));
        $this->assertSame([], $this->walk('{}'));
    }
}
