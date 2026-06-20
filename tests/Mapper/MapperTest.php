<?php
declare(strict_types=1);

namespace Barnemax\WpDataCli\Tests\Mapper;

use Barnemax\WpDataCli\Exception\MapperException;
use Barnemax\WpDataCli\Mapper\FieldMap;
use Barnemax\WpDataCli\Mapper\Mapper;
use PHPUnit\Framework\TestCase;

class MapperTest extends TestCase
{
    private function defaultMap(): FieldMap
    {
        return FieldMap::make('products')
            ->id('sku')
            ->title('title')
            ->content('content')
            ->meta('price', 'price', transform: static fn($v) => (float) $v)
            ->meta('rating', 'rating', transform: static fn($v) => (float) $v)
            ->taxonomy('product_type', 'product_type_terms')
            ->featuredImage('image_url');
    }

    /** @return array<string, mixed> */
    private function sampleRow(): array
    {
        return [
            'sku'                => 'PROD-0001',
            'title'              => 'Acme Wireless Headphones',
            'content'            => 'Premium headphones.',
            'image_url'          => 'https://example.com/images/headphones.jpg',
            'product_type_terms' => 'Electronics > Audio, Bestseller',
            'rating'             => '4.7',
            'price'              => '199.99',
        ];
    }

    public function testMapsSourceId(): void
    {
        $record = (new Mapper($this->defaultMap()))->map($this->sampleRow());

        self::assertSame('PROD-0001', $record->sourceId);
    }

    public function testMapsPostFields(): void
    {
        $record = (new Mapper($this->defaultMap()))->map($this->sampleRow());

        self::assertSame('Acme Wireless Headphones', $record->postFields['post_title']);
        self::assertSame('Premium headphones.', $record->postFields['post_content']);
        self::assertSame('publish', $record->postFields['post_status']);
    }

    public function testAppliesMetaTransforms(): void
    {
        $record = (new Mapper($this->defaultMap()))->map($this->sampleRow());

        self::assertSame(199.99, $record->meta['price']);
        self::assertSame(4.7, $record->meta['rating']);
    }

    public function testParsesFlatTerm(): void
    {
        $row    = \array_merge($this->sampleRow(), ['product_type_terms' => 'Bestseller']);
        $record = (new Mapper($this->defaultMap()))->map($row);

        self::assertSame([['Bestseller']], $record->taxonomies['product_type']);
    }

    public function testParsesHierarchicalTerm(): void
    {
        $row    = \array_merge($this->sampleRow(), ['product_type_terms' => 'Electronics > Audio']);
        $record = (new Mapper($this->defaultMap()))->map($row);

        self::assertSame([['Electronics', 'Audio']], $record->taxonomies['product_type']);
    }

    public function testParsesMultipleTermsWithHierarchy(): void
    {
        $record = (new Mapper($this->defaultMap()))->map($this->sampleRow());

        self::assertSame(
            [['Electronics', 'Audio'], ['Bestseller']],
            $record->taxonomies['product_type'],
        );
    }

    public function testEmptyTermCellYieldsEmptyArray(): void
    {
        $row    = \array_merge($this->sampleRow(), ['product_type_terms' => '']);
        $record = (new Mapper($this->defaultMap()))->map($row);

        self::assertSame([], $record->taxonomies['product_type']);
    }

    public function testMapsFeaturedImageToMediaArray(): void
    {
        $record = (new Mapper($this->defaultMap()))->map($this->sampleRow());

        self::assertCount(1, $record->media);
        self::assertSame('https://example.com/images/headphones.jpg', $record->media[0]['url']);
        self::assertSame('featured', $record->media[0]['role']);
    }

    public function testEmptyImageUrlIsOmittedFromMedia(): void
    {
        $row    = \array_merge($this->sampleRow(), ['image_url' => '']);
        $record = (new Mapper($this->defaultMap()))->map($row);

        self::assertSame([], $record->media);
    }

    public function testThrowsWhenNoIdDefined(): void
    {
        $map = FieldMap::make('products')->title('title');

        $this->expectException(MapperException::class);
        (new Mapper($map))->map($this->sampleRow());
    }

    public function testThrowsWhenIdValueIsEmpty(): void
    {
        $this->expectException(MapperException::class);
        (new Mapper($this->defaultMap()))->map(\array_merge($this->sampleRow(), ['sku' => '']));
    }

    public function testDefaultStatusIsPublish(): void
    {
        $record = (new Mapper($this->defaultMap()))->map($this->sampleRow());

        self::assertSame('publish', $record->postFields['post_status']);
    }

    public function testCustomStatusColumn(): void
    {
        $map    = $this->defaultMap()->status('status');
        $row    = \array_merge($this->sampleRow(), ['status' => 'draft']);
        $record = (new Mapper($map))->map($row);

        self::assertSame('draft', $record->postFields['post_status']);
    }

    public function testIdMetaKeyDerivesFromSlug(): void
    {
        self::assertSame('_products_id', FieldMap::make('products')->id('sku')->getIdMetaKey());
    }

    public function testIdMetaKeyIsOverridable(): void
    {
        self::assertSame('_my_key', FieldMap::make('products')->id('sku', '_my_key')->getIdMetaKey());
    }
}
