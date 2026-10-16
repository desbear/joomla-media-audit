<?php

/**
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Mediaaudit\Tests\Unit;

use Gss\Component\Mediaaudit\Administrator\Service\CaseSensitiveFilesystem;
use Gss\Component\Mediaaudit\Administrator\Service\FilePlacer;
use PHPUnit\Framework\TestCase;

final class FilePlacerTest extends TestCase
{
    private string $folder = '';

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir() . '/mediaaudit-placer-' . bin2hex(random_bytes(4));

        mkdir($this->folder . '/images/a', 0755, true);
        mkdir($this->folder . '/outside', 0755, true);
        file_put_contents($this->folder . '/part', 'new');
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->folder, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($files as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->folder);
    }

    public function testAFreeNameGetsTheFile(): void
    {
        $this->assertTrue(FilePlacer::place($this->folder . '/part', $this->folder . '/images/a/new.webp'));
        $this->assertSame('new', file_get_contents($this->folder . '/images/a/new.webp'));
        $this->assertFileDoesNotExist($this->folder . '/part');
    }

    public function testAFileThatIsThereIsNotWrittenOver(): void
    {
        file_put_contents($this->folder . '/images/a/taken.webp', 'old');

        $this->assertFalse(FilePlacer::place($this->folder . '/part', $this->folder . '/images/a/taken.webp'));
        $this->assertSame('old', file_get_contents($this->folder . '/images/a/taken.webp'));
        $this->assertFileExists($this->folder . '/part');
    }

    public function testALinkIsNotFollowed(): void
    {
        // A link that leads to where nothing is yet: writing through it would make a file outside
        symlink($this->folder . '/outside/made.webp', $this->folder . '/images/a/link.webp');

        $this->assertFalse(FilePlacer::place($this->folder . '/part', $this->folder . '/images/a/link.webp'));
        $this->assertFileDoesNotExist($this->folder . '/outside/made.webp');
    }

    public function testAFolderThatIsALinkIsNotInside(): void
    {
        symlink($this->folder . '/outside', $this->folder . '/images/elsewhere');
        file_put_contents($this->folder . '/outside/x.png', 'x');

        $disk = new CaseSensitiveFilesystem($this->folder);

        $this->assertTrue($disk->isInside('images/a/x.png', 'images'));
        $this->assertFalse($disk->isInside('images/elsewhere/x.png', 'images'));
        $this->assertFalse($disk->isInside('outside/x.png', 'images'));
    }
}
