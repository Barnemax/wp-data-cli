<?php
declare(strict_types=1);

namespace Barnemax\WpDataCli\Tests\Importer;

use Barnemax\WpDataCli\Importer\ImportConfig;
use Barnemax\WpDataCli\Importer\Importer;
use Barnemax\WpDataCli\Importer\MediaHandler;
use Barnemax\WpDataCli\Importer\TermResolver;
use Barnemax\WpDataCli\Importer\UpsertStrategy;
use Barnemax\WpDataCli\Mapper\FieldMap;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class ImporterTest extends TestCase
{
    private string $csvFixture;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->csvFixture = __DIR__ . '/../fixtures/products.csv';
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // --- Dry run ---

    public function testDryRunSkipsAllRowsWithoutWriting(): void
    {
        $config = new ImportConfig(
            file: $this->csvFixture,
            map: $this->baseMap(),
            dryRun: true,
        );

        $report = (new Importer($config, ...$this->mockDeps()))->run();

        self::assertSame(5, $report->getSkipped());
        self::assertSame(0, $report->getInserted());
        self::assertSame(0, $report->getUpdated());
        self::assertSame(0, $report->getFailed());
    }

    // --- Insert ---

    public function testAllRowsInsertedWhenNoExistingPosts(): void
    {
        $this->stubPerformanceFunctions();
        Functions\when('wp_insert_post')->justReturn(42);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('update_post_meta')->justReturn(true);

        [$upsert, $termResolver, $media] = $this->mockDeps();
        $upsert->method('findExisting')->willReturn(null);

        $config = new ImportConfig(file: $this->csvFixture, map: $this->baseMap());
        $report = (new Importer($config, $upsert, $termResolver, $media))->run();

        self::assertSame(5, $report->getInserted());
        self::assertSame(0, $report->getUpdated());
        self::assertSame(0, $report->getFailed());
    }

    public function testInsertRecordsSourceIdMeta(): void
    {
        $this->stubPerformanceFunctions();
        Functions\when('wp_insert_post')->justReturn(42);
        Functions\when('is_wp_error')->justReturn(false);

        $capturedMeta = [];
        Functions\when('update_post_meta')->alias(
            function (int $postId, string $key, mixed $value) use (&$capturedMeta) {
                $capturedMeta[] = [$postId, $key, $value];
                return true;
            },
        );

        [$upsert, $termResolver, $media] = $this->mockDeps();
        $upsert->method('findExisting')->willReturn(null);

        $config = new ImportConfig(file: $this->csvFixture, map: $this->baseMap());
        (new Importer($config, $upsert, $termResolver, $media))->run();

        $sourceIdMetas = array_filter($capturedMeta, fn($m) => $m[1] === '_products_id');
        self::assertCount(5, $sourceIdMetas);

        $values = array_column(array_values($sourceIdMetas), 2);
        self::assertContains('PROD-0001', $values);
    }

    // --- Update ---

    public function testAllRowsUpdatedWhenExistingPostsFound(): void
    {
        $this->stubPerformanceFunctions();
        Functions\when('wp_update_post')->justReturn(42);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('update_post_meta')->justReturn(true);

        [$upsert, $termResolver, $media] = $this->mockDeps();
        $upsert->method('findExisting')->willReturn(42);

        $config = new ImportConfig(file: $this->csvFixture, map: $this->baseMap());
        $report = (new Importer($config, $upsert, $termResolver, $media))->run();

        self::assertSame(0, $report->getInserted());
        self::assertSame(5, $report->getUpdated());
        self::assertSame(0, $report->getFailed());
    }

    // --- WP_Error handling ---

    public function testWpInsertPostErrorIsRecordedAndAbortsWhenNotContinueOnError(): void
    {
        $this->stubPerformanceFunctions();

        $wpError = new \WP_Error('db_error', 'DB error');

        Functions\when('wp_insert_post')->justReturn($wpError);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);

        [$upsert, $termResolver, $media] = $this->mockDeps();
        $upsert->method('findExisting')->willReturn(null);

        $config = new ImportConfig(
            file: $this->csvFixture,
            map: $this->baseMap(),
            continueOnError: false,
        );

        $this->expectException(\Barnemax\WpDataCli\Exception\ImportException::class);
        (new Importer($config, $upsert, $termResolver, $media))->run();
    }

    public function testContinueOnErrorLogsFailureAndProcessesRemainingRows(): void
    {
        $this->stubPerformanceFunctions();
        Functions\when('update_post_meta')->justReturn(true);

        $wpError = new \WP_Error('db_error', 'DB error');

        $call = 0;
        Functions\when('wp_insert_post')->alias(function () use (&$call, $wpError) {
            $call++;
            return $call === 1 ? $wpError : 42; // first row fails, rest succeed
        });
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);

        [$upsert, $termResolver, $media] = $this->mockDeps();
        $upsert->method('findExisting')->willReturn(null);

        $config = new ImportConfig(
            file: $this->csvFixture,
            map: $this->baseMap(),
            continueOnError: true,
        );

        $report = (new Importer($config, $upsert, $termResolver, $media))->run();

        self::assertSame(1, $report->getFailed());
        self::assertSame(4, $report->getInserted());
        self::assertCount(1, $report->getErrors());
    }

    // --- Offset ---

    public function testOffsetSkipsLeadingRows(): void
    {
        $this->stubPerformanceFunctions();
        Functions\when('wp_insert_post')->justReturn(42);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('update_post_meta')->justReturn(true);

        [$upsert, $termResolver, $media] = $this->mockDeps();
        $upsert->method('findExisting')->willReturn(null);

        $config = new ImportConfig(
            file: $this->csvFixture,
            map: $this->baseMap(),
            offset: 3,
        );

        $report = (new Importer($config, $upsert, $termResolver, $media))->run();

        self::assertSame(2, $report->getInserted()); // 5 rows total − 3 skipped
    }

    // --- Taxonomy ---

    public function testTaxonomyIsResolvedAndAssigned(): void
    {
        $this->stubPerformanceFunctions();
        Functions\when('wp_insert_post')->justReturn(42);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('update_post_meta')->justReturn(true);

        $assignedTerms = [];
        Functions\when('wp_set_object_terms')->alias(
            function (int $postId, array $termIds, string $taxonomy) use (&$assignedTerms) {
                $assignedTerms[] = ['taxonomy' => $taxonomy, 'ids' => $termIds];
                return [];
            },
        );

        [$upsert, $termResolver, $media] = $this->mockDeps();
        $upsert->method('findExisting')->willReturn(null);
        $termResolver->method('resolve')->willReturn([7, 8]);

        $map = $this->baseMap()->taxonomy('product_type', 'product_type_terms');
        $config = new ImportConfig(file: $this->csvFixture, map: $map);

        $report = (new Importer($config, $upsert, $termResolver, $media))->run();

        self::assertSame(0, $report->getFailed());
        self::assertNotEmpty($assignedTerms);
        self::assertSame('product_type', $assignedTerms[0]['taxonomy']);
        self::assertSame([7, 8], $assignedTerms[0]['ids']);
    }

    // --- Media ---

    public function testFeaturedImageIsSetWhenSideloadSucceeds(): void
    {
        $this->stubPerformanceFunctions();
        Functions\when('wp_insert_post')->justReturn(42);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('update_post_meta')->justReturn(true);

        $thumbnailSet = false;
        Functions\when('set_post_thumbnail')->alias(function () use (&$thumbnailSet) {
            $thumbnailSet = true;
            return true;
        });

        [$upsert, $termResolver, $media] = $this->mockDeps();
        $upsert->method('findExisting')->willReturn(null);
        $media->method('sideload')->willReturn(99);

        $map = $this->baseMap()->featuredImage('image_url');
        $config = new ImportConfig(file: $this->csvFixture, map: $map);

        $report = (new Importer($config, $upsert, $termResolver, $media))->run();

        self::assertSame(0, $report->getFailed());
        self::assertTrue($thumbnailSet);
    }

    public function testFailedMediaSideloadDoesNotAbortRow(): void
    {
        $this->stubPerformanceFunctions();
        Functions\when('wp_insert_post')->justReturn(42);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('update_post_meta')->justReturn(true);

        [$upsert, $termResolver, $media] = $this->mockDeps();
        $upsert->method('findExisting')->willReturn(null);
        $media->method('sideload')->willReturn(null); // download failure

        $map = $this->baseMap()->featuredImage('image_url');
        $config = new ImportConfig(file: $this->csvFixture, map: $map);

        $report = (new Importer($config, $upsert, $termResolver, $media))->run();

        self::assertSame(5, $report->getInserted());
        self::assertSame(0, $report->getFailed());
    }

    // --- Helpers ---

    private function baseMap(): FieldMap
    {
        return FieldMap::make('products')
            ->id('sku')
            ->title('title')
            ->content('content');
    }

    /** @return array{UpsertStrategy&\PHPUnit\Framework\MockObject\MockObject, TermResolver&\PHPUnit\Framework\MockObject\MockObject, MediaHandler&\PHPUnit\Framework\MockObject\MockObject} */
    private function mockDeps(): array
    {
        // void methods (preload, recordInsert, reset, clearCache) need no willReturn — PHPUnit handles void correctly.
        $upsert = $this->createMock(UpsertStrategy::class);

        $termResolver = $this->createMock(TermResolver::class);

        $media = $this->createMock(MediaHandler::class);

        return [$upsert, $termResolver, $media];
    }

    private function stubPerformanceFunctions(): void
    {
        Functions\when('wp_suspend_cache_addition')->justReturn(null);
        Functions\when('wp_defer_term_counting')->justReturn(null);
        Functions\when('wp_defer_comment_counting')->justReturn(null);
        Functions\when('wp_cache_flush')->justReturn(true);
    }
}
