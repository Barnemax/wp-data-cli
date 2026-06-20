<?php
declare(strict_types=1);

namespace Barnemax\WpDataCli\Tests\Importer;

use Barnemax\WpDataCli\Importer\TermResolver;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class TermResolverTest extends TestCase
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

    // --- preload ---

    public function testPreloadWithNoTermsLeavesEmptyCache(): void
    {
        Functions\when('get_terms')->justReturn([]);
        Functions\when('is_wp_error')->justReturn(false);
        // Cache is empty → resolve falls through to term_exists, then wp_insert_term.
        Functions\when('term_exists')->justReturn(null);
        Functions\when('wp_insert_term')->justReturn(['term_id' => 10]);

        $resolver = new TermResolver();
        $resolver->preload('product_type');

        $ids = $resolver->resolve('product_type', [['NewTerm']]);
        self::assertSame([10], $ids);
    }

    public function testPreloadWithWpErrorLeavesEmptyCache(): void
    {
        Functions\when('get_terms')->justReturn(new \WP_Error('failed', 'err'));
        // alias so the WP_Error from get_terms returns true but the array from wp_insert_term returns false.
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);
        Functions\when('term_exists')->justReturn(null);
        Functions\when('wp_insert_term')->justReturn(['term_id' => 5]);

        $resolver = new TermResolver();
        $resolver->preload('product_type');

        $ids = $resolver->resolve('product_type', [['Anything']]);
        self::assertSame([5], $ids);
    }

    // --- resolve from cache ---

    public function testResolveFindsExistingTermFromCache(): void
    {
        Functions\when('get_terms')->justReturn([
            $this->makeTerm(1, 0, 'Electronics'),
        ]);
        Functions\when('is_wp_error')->justReturn(false);

        $resolver = new TermResolver();
        $resolver->preload('product_type');

        Functions\expect('term_exists')->never();
        Functions\expect('wp_insert_term')->never();

        $ids = $resolver->resolve('product_type', [['Electronics']]);
        self::assertSame([1], $ids);
    }

    public function testResolveHandlesHierarchicalPathFromCache(): void
    {
        Functions\when('get_terms')->justReturn([
            $this->makeTerm(1, 0, 'Electronics'),
            $this->makeTerm(2, 1, 'Audio'),
        ]);
        Functions\when('is_wp_error')->justReturn(false);

        $resolver = new TermResolver();
        $resolver->preload('product_type');

        Functions\expect('term_exists')->never();
        Functions\expect('wp_insert_term')->never();

        $ids = $resolver->resolve('product_type', [['Electronics', 'Audio']]);
        self::assertSame([2], $ids);
    }

    public function testResolveMultipleTermPaths(): void
    {
        Functions\when('get_terms')->justReturn([
            $this->makeTerm(1, 0, 'Electronics'),
            $this->makeTerm(2, 1, 'Audio'),
            $this->makeTerm(3, 0, 'Bestseller'),
        ]);
        Functions\when('is_wp_error')->justReturn(false);

        $resolver = new TermResolver();
        $resolver->preload('product_type');

        $ids = $resolver->resolve('product_type', [['Electronics', 'Audio'], ['Bestseller']]);
        self::assertSame([2, 3], $ids);
    }

    // --- cache miss: falls back to DB, then creates ---

    public function testResolveFallsBackToTermExistsOnCacheMiss(): void
    {
        Functions\when('get_terms')->justReturn([]);
        Functions\when('is_wp_error')->justReturn(false);

        Functions\expect('term_exists')
            ->once()
            ->with('Electronics', 'product_type', 0)
            ->andReturn(['term_id' => 7]);

        $resolver = new TermResolver();
        $resolver->preload('product_type');

        $ids = $resolver->resolve('product_type', [['Electronics']]);
        self::assertSame([7], $ids);
    }

    public function testResolveCreatesTermWhenNotFoundAnywhere(): void
    {
        Functions\when('get_terms')->justReturn([]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('term_exists')->justReturn(null);
        Functions\when('wp_insert_term')->justReturn(['term_id' => 8]);

        $resolver = new TermResolver();
        $resolver->preload('product_type');

        $ids = $resolver->resolve('product_type', [['NewCategory']]);
        self::assertSame([8], $ids);
    }

    public function testCreatedTermIsAddedToCacheForSubsequentRows(): void
    {
        Functions\when('get_terms')->justReturn([]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('term_exists')->justReturn(null);

        $callCount = 0;
        Functions\when('wp_insert_term')->alias(function () use (&$callCount) {
            $callCount++;
            return ['term_id' => 20];
        });

        $resolver = new TermResolver();
        $resolver->preload('product_type');

        $resolver->resolve('product_type', [['NewTerm']]);
        $resolver->resolve('product_type', [['NewTerm']]); // second call should hit cache

        self::assertSame(1, $callCount, 'wp_insert_term should be called only once — second resolve hits cache.');
    }

    public function testResolveSkipsPathIfInsertTermReturnsWpError(): void
    {
        Functions\when('get_terms')->justReturn([]);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);
        Functions\when('term_exists')->justReturn(null);
        Functions\when('wp_insert_term')->justReturn(new \WP_Error('insert_failed', 'Failed'));

        $resolver = new TermResolver();
        $resolver->preload('product_type');

        $ids = $resolver->resolve('product_type', [['BadTerm']]);
        self::assertSame([], $ids);
    }

    // --- reset ---

    public function testResetClearsCache(): void
    {
        Functions\when('get_terms')->justReturn([
            $this->makeTerm(1, 0, 'Electronics'),
        ]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('term_exists')->justReturn(null);
        Functions\when('wp_insert_term')->justReturn(['term_id' => 99]);

        $resolver = new TermResolver();
        $resolver->preload('product_type');
        $resolver->reset();

        // After reset, 'Electronics' is no longer cached — falls through to term_exists.
        $ids = $resolver->resolve('product_type', [['Electronics']]);
        self::assertSame([99], $ids);
    }

    // --- Helper ---

    private function makeTerm(int $termId, int $parentId, string $name): \stdClass
    {
        $term          = new \stdClass();
        $term->term_id = $termId;
        $term->parent  = $parentId;
        $term->name    = $name;
        return $term;
    }
}
