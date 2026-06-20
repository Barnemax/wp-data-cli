<?php
declare(strict_types=1);

namespace Barnemax\WpDataCli\Tests\Reader;

use Barnemax\WpDataCli\Exception\ReaderException;
use Barnemax\WpDataCli\Reader\XlsxReader;
use PHPUnit\Framework\TestCase;

class XlsxReaderTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        $this->fixture = __DIR__ . '/../fixtures/products.xlsx';
    }

    public function testYieldsRowsKeyedByHeader(): void
    {
        $rows = \iterator_to_array((new XlsxReader($this->fixture))->getRows(), false);

        self::assertNotEmpty($rows);
        self::assertArrayHasKey('sku', $rows[0]);
        self::assertArrayHasKey('title', $rows[0]);
    }

    public function testHeaderNamesAreExact(): void
    {
        $rows = \iterator_to_array((new XlsxReader($this->fixture))->getRows(), false);

        self::assertSame(
            ['title', 'content', 'image_url', 'product_type_terms', 'rating', 'price', 'sku'],
            \array_keys($rows[0]),
        );
    }

    public function testRowCount(): void
    {
        $rows = \iterator_to_array((new XlsxReader($this->fixture))->getRows(), false);

        self::assertCount(23, $rows);
    }

    public function testFirstRowValues(): void
    {
        $rows = \iterator_to_array((new XlsxReader($this->fixture))->getRows(), false);

        self::assertSame('PROD-0001', (string) $rows[0]['sku']);
        self::assertSame('Acme Wireless Headphones', (string) $rows[0]['title']);
    }

    public function testSelectSheetByName(): void
    {
        $rows = \iterator_to_array((new XlsxReader($this->fixture, sheet: 'products'))->getRows(), false);

        self::assertCount(23, $rows);
    }

    public function testThrowsOnUnknownSheet(): void
    {
        $this->expectException(ReaderException::class);

        \iterator_to_array((new XlsxReader($this->fixture, sheet: 'nonexistent'))->getRows(), false);
    }
}
