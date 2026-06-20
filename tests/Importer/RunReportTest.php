<?php
declare(strict_types=1);

namespace Barnemax\WpDataCli\Tests\Importer;

use Barnemax\WpDataCli\Importer\RunReport;
use PHPUnit\Framework\TestCase;

class RunReportTest extends TestCase
{
    public function testInitialStateIsAllZero(): void
    {
        $r = new RunReport();

        self::assertSame(0, $r->getInserted());
        self::assertSame(0, $r->getUpdated());
        self::assertSame(0, $r->getSkipped());
        self::assertSame(0, $r->getFailed());
        self::assertSame(0, $r->total());
        self::assertSame([], $r->getErrors());
    }

    public function testRecordInsertedIncrementsCount(): void
    {
        $r = new RunReport();
        $r->recordInserted();
        $r->recordInserted();

        self::assertSame(2, $r->getInserted());
        self::assertSame(2, $r->total());
    }

    public function testRecordUpdatedIncrementsCount(): void
    {
        $r = new RunReport();
        $r->recordUpdated();

        self::assertSame(1, $r->getUpdated());
    }

    public function testRecordSkippedIncrementsCount(): void
    {
        $r = new RunReport();
        $r->recordSkipped();
        $r->recordSkipped();
        $r->recordSkipped();

        self::assertSame(3, $r->getSkipped());
    }

    public function testRecordFailedIncrementsCountAndStoresError(): void
    {
        $r = new RunReport();
        $r->recordFailed(5, 'PROD-0005', 'Something went wrong.');

        self::assertSame(1, $r->getFailed());
        self::assertCount(1, $r->getErrors());
        self::assertSame(
            ['row' => 5, 'source_id' => 'PROD-0005', 'message' => 'Something went wrong.'],
            $r->getErrors()[0],
        );
    }

    public function testMultipleFailuresAccumulate(): void
    {
        $r = new RunReport();
        $r->recordFailed(1, 'A', 'err A');
        $r->recordFailed(2, 'B', 'err B');

        self::assertSame(2, $r->getFailed());
        self::assertCount(2, $r->getErrors());
    }

    public function testTotalSumsAllCounters(): void
    {
        $r = new RunReport();
        $r->recordInserted();
        $r->recordInserted();
        $r->recordUpdated();
        $r->recordSkipped();
        $r->recordFailed(9, 'X', 'e');

        self::assertSame(5, $r->total());
    }

    public function testSummaryContainsAllCounts(): void
    {
        $r = new RunReport();
        $r->recordInserted();
        $r->recordUpdated();
        $r->recordSkipped();
        $r->recordFailed(1, 'X', 'e');

        $summary = $r->summary();

        self::assertStringContainsString('4', $summary);
        self::assertStringContainsString('1 inserted', $summary);
        self::assertStringContainsString('1 updated', $summary);
        self::assertStringContainsString('1 skipped', $summary);
        self::assertStringContainsString('1 failed', $summary);
    }
}
