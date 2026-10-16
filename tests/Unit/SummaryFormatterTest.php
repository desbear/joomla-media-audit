<?php

/**
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Mediaaudit\Tests\Unit;

use Gss\Component\Mediaaudit\Administrator\Report\SummaryFormatter;
use PHPUnit\Framework\TestCase;

final class SummaryFormatterTest extends TestCase
{
    private const LABELS = [
        'files'               => 'Files',
        'in_use'              => 'In use',
        'semi_orphan'         => 'Unpublished only',
        'orphan'              => 'Orphaned',
        'broken'              => 'Broken',
        'duplicate_groups'    => 'Duplicated',
        'external_references' => 'External',
    ];

    private const CURRENT = [
        'files' => 1200, 'in_use' => 1100, 'semi_orphan' => 20, 'orphan' => 80, 'broken' => 3,
        'duplicate_groups' => 4, 'external_references' => 0, 'wasted_bytes' => 9,
    ];

    public function testFirstScan(): void
    {
        $this->assertSame(
            "Files             1,200\nIn use            1,100\nUnpublished only  20\nOrphaned          80\nBroken            3\nDuplicated        4\nExternal          0",
            SummaryFormatter::format(self::CURRENT, null, self::LABELS)
        );
    }

    public function testChanges(): void
    {
        $previous = ['files' => 1190, 'in_use' => 1100, 'semi_orphan' => 25, 'orphan' => 65, 'broken' => 3, 'duplicate_groups' => 4, 'external_references' => 2885];

        $this->assertSame(
            "Files             1,200 (+10)\nIn use            1,100\nUnpublished only  20 (-5)\nOrphaned          80 (+15)\nBroken            3\nDuplicated        4\nExternal          0 (-2,885)",
            SummaryFormatter::format(self::CURRENT, $previous, self::LABELS)
        );
    }

    public function testChineseLabelsLineUp(): void
    {
        $text  = SummaryFormatter::format(['files' => 1, 'in_use' => 2], null, ['files' => '檔案', 'in_use' => '使用中']);
        $lines = explode("\n", $text);

        // A Chinese character is as wide as two letters
        $this->assertSame('檔案' . '  ' . '  1', $lines[0]);
        $this->assertSame('使用中' . '  2', $lines[1]);
    }

    public function testHasChanged(): void
    {
        $this->assertTrue(SummaryFormatter::hasChanged(self::CURRENT, null));
        $this->assertFalse(SummaryFormatter::hasChanged(self::CURRENT, self::CURRENT));
        $this->assertFalse(SummaryFormatter::hasChanged(self::CURRENT, ['wasted_bytes' => 1] + self::CURRENT));
        $this->assertTrue(SummaryFormatter::hasChanged(self::CURRENT, ['orphan' => 79] + self::CURRENT));
    }
}
