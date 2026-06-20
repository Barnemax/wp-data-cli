<?php
declare(strict_types=1);

namespace Barnemax\WpDataCli\Tests\Reader;

use Barnemax\WpDataCli\Exception\ReaderException;
use Barnemax\WpDataCli\Reader\CsvReader;
use PHPUnit\Framework\TestCase;

class CsvReaderTest extends TestCase
{
    private string $fixture;
    private string $pipeFixture;

    protected function setUp(): void
    {
        $this->fixture     = __DIR__ . '/../fixtures/products.csv';
        $this->pipeFixture = __DIR__ . '/../fixtures/products-pipe.csv';
    }

    // --- Default comma delimiter ---

    public function testYieldsRowsKeyedByHeader(): void
    {
        $rows = \iterator_to_array((new CsvReader($this->fixture))->getRows(), false);

        self::assertNotEmpty($rows);
        self::assertArrayHasKey('sku', $rows[0]);
        self::assertArrayHasKey('title', $rows[0]);
        self::assertArrayHasKey('price', $rows[0]);
    }

    public function testHeaderNamesAreExact(): void
    {
        $rows = \iterator_to_array((new CsvReader($this->fixture))->getRows(), false);

        self::assertSame(
            ['title', 'content', 'image_url', 'product_type_terms', 'rating', 'price', 'sku'],
            \array_keys($rows[0]),
        );
    }

    public function testRowCount(): void
    {
        $rows = \iterator_to_array((new CsvReader($this->fixture))->getRows(), false);

        self::assertCount(5, $rows);
    }

    public function testFirstRowValues(): void
    {
        $rows = \iterator_to_array((new CsvReader($this->fixture))->getRows(), false);

        self::assertSame('PROD-0001', $rows[0]['sku']);
        self::assertSame('Acme Wireless Headphones', $rows[0]['title']);
        self::assertSame('199.99', $rows[0]['price']);
    }

    public function testQuotedFieldContainingCommaIsPreserved(): void
    {
        $rows = \iterator_to_array((new CsvReader($this->fixture))->getRows(), false);

        // "Electronics > Audio, Bestseller" is a single field despite the internal comma.
        self::assertStringContainsString(',', $rows[0]['product_type_terms']);
        self::assertSame('Electronics > Audio, Bestseller', $rows[0]['product_type_terms']);
    }

    // --- Pipe delimiter ---

    public function testPipeDelimiterYieldsCorrectRowCount(): void
    {
        $rows = \iterator_to_array((new CsvReader($this->pipeFixture, '|'))->getRows(), false);

        self::assertCount(5, $rows);
    }

    public function testPipeDelimiterHeaderNamesAreExact(): void
    {
        $rows = \iterator_to_array((new CsvReader($this->pipeFixture, '|'))->getRows(), false);

        self::assertSame(
            ['title', 'content', 'image_url', 'product_type_terms', 'rating', 'price', 'sku'],
            \array_keys($rows[0]),
        );
    }

    public function testPipeDelimiterFieldValuesMatch(): void
    {
        $rows = \iterator_to_array((new CsvReader($this->pipeFixture, '|'))->getRows(), false);

        self::assertSame('PROD-0001', $rows[0]['sku']);
        self::assertSame('199.99', $rows[0]['price']);
    }

    public function testPipeDelimiterPreservesCommaInsideField(): void
    {
        $rows = \iterator_to_array((new CsvReader($this->pipeFixture, '|'))->getRows(), false);

        // No quoting needed in PSV; the comma in "Electronics > Audio, Bestseller" is literal.
        self::assertSame('Electronics > Audio, Bestseller', $rows[0]['product_type_terms']);
    }

    public function testCommaReaderDoesNotParsesPipeFile(): void
    {
        // Reading a pipe file with the default comma delimiter should collapse all columns into one.
        $rows = \iterator_to_array((new CsvReader($this->pipeFixture))->getRows(), false);

        self::assertCount(1, \array_keys($rows[0]), 'Expected all columns collapsed into a single key.');
    }

    // --- Error handling ---

    public function testThrowsOnMissingFile(): void
    {
        $this->expectException(ReaderException::class);

        \iterator_to_array((new CsvReader('/nonexistent/file.csv'))->getRows(), false);
    }
}
