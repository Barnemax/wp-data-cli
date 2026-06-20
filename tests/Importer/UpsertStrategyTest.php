<?php
declare(strict_types=1);

namespace Barnemax\WpDataCli\Tests\Importer;

use Barnemax\WpDataCli\Importer\UpsertStrategy;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class UpsertStrategyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // --- Without preload: falls back to get_posts() ---

    public function testFindExistingWithoutPreloadReturnsPostIdFromDb(): void
    {
        Functions\when('get_posts')->justReturn([42]);

        $strategy = new UpsertStrategy();

        self::assertSame(42, $strategy->findExisting('_products_id', 'PROD-0001', 'post'));
    }

    public function testFindExistingWithoutPreloadReturnsNullWhenNotFound(): void
    {
        Functions\when('get_posts')->justReturn([]);

        $strategy = new UpsertStrategy();

        self::assertNull($strategy->findExisting('_products_id', 'PROD-UNKNOWN', 'post'));
    }

    // --- After preload: uses in-memory map, no DB call ---

    public function testPreloadFillsMapFromWpdb(): void
    {
        $this->setUpWpdb([
            ['source_id' => 'PROD-0001', 'post_id' => '42'],
            ['source_id' => 'PROD-0002', 'post_id' => '43'],
        ]);

        $strategy = new UpsertStrategy();
        $strategy->preload('_products_id', 'post');

        self::assertSame(42, $strategy->findExisting('_products_id', 'PROD-0001', 'post'));
        self::assertSame(43, $strategy->findExisting('_products_id', 'PROD-0002', 'post'));
    }

    public function testFindExistingAfterPreloadReturnsNullForUnknownId(): void
    {
        $this->setUpWpdb([]);

        $strategy = new UpsertStrategy();
        $strategy->preload('_products_id', 'post');

        // get_posts must never be called when the map is loaded.
        Functions\expect('get_posts')->never();

        self::assertNull($strategy->findExisting('_products_id', 'PROD-NONE', 'post'));
    }

    public function testFindExistingAfterPreloadDoesNotQueryDb(): void
    {
        $this->setUpWpdb([
            ['source_id' => 'PROD-0001', 'post_id' => '42'],
        ]);

        $strategy = new UpsertStrategy();
        $strategy->preload('_products_id', 'post');

        Functions\expect('get_posts')->never();

        $result = $strategy->findExisting('_products_id', 'PROD-0001', 'post');
        self::assertSame(42, $result, 'Value must come from the pre-loaded map, not a DB query.');
    }

    // --- recordInsert ---

    public function testRecordInsertMakesNewIdAvailableInMap(): void
    {
        $this->setUpWpdb([]);

        $strategy = new UpsertStrategy();
        $strategy->preload('_products_id', 'post');

        self::assertNull($strategy->findExisting('_products_id', 'PROD-NEW', 'post'));

        $strategy->recordInsert('PROD-NEW', 99);

        Functions\expect('get_posts')->never();
        self::assertSame(99, $strategy->findExisting('_products_id', 'PROD-NEW', 'post'));
    }

    public function testRecordInsertIsNoopWhenMapNotLoaded(): void
    {
        // recordInsert before preload should not blow up and should not affect later DB queries.
        Functions\when('get_posts')->justReturn([77]);

        $strategy = new UpsertStrategy();
        $strategy->recordInsert('PROD-NEW', 99); // no-op, map is null

        self::assertSame(77, $strategy->findExisting('_products_id', 'PROD-NEW', 'post'));
    }

    // --- reset ---

    public function testResetClearsMapAndFallsBackToDb(): void
    {
        $this->setUpWpdb([
            ['source_id' => 'PROD-0001', 'post_id' => '42'],
        ]);

        $strategy = new UpsertStrategy();
        $strategy->preload('_products_id', 'post');

        self::assertSame(42, $strategy->findExisting('_products_id', 'PROD-0001', 'post'));

        $strategy->reset();

        Functions\when('get_posts')->justReturn([55]);
        self::assertSame(55, $strategy->findExisting('_products_id', 'PROD-0001', 'post'));
    }

    // --- Helpers ---

    /** @param list<array{source_id: string, post_id: string}> $rows */
    private function setUpWpdb(array $rows): void
    {
        $GLOBALS['wpdb'] = new class ($rows) {
            public string $postmeta = 'wp_postmeta';
            public string $posts    = 'wp_posts';

            public function __construct(private readonly array $rows) {}

            public function prepare(string $sql, mixed ...$args): string
            {
                return $sql;
            }

            public function get_results(string $sql, string $output = ARRAY_A): array
            {
                return $this->rows;
            }
        };
    }
}
