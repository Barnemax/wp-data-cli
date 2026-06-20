<?php
declare(strict_types=1);

namespace Barnemax\WpDataCli\Mapper;

/**
 * Fluent builder that describes how source columns map to WordPress fields.
 *
 * Usage:
 *
 *     return FieldMap::make('products')
 *         ->id('sku')
 *         ->title('title')
 *         ->content('content')
 *         ->meta('price', 'price', transform: fn($v) => (float) $v)
 *         ->taxonomy('product_type', 'product_type_terms')
 *         ->featuredImage('image_url');
 */
class FieldMap
{
    private ?string $idColumn = null;
    private ?string $idMetaKey = null;
    private ?string $titleColumn = null;
    private ?string $excerptColumn = null;
    private ?string $contentColumn = null;
    private ?string $statusColumn = null;
    private string $defaultStatus = 'publish';

    /** @var list<array{source: string, key: string, transform: callable|null}> */
    private array $metaFields = [];

    /** @var list<array{taxonomy: string, source: string, separator: string, hierarchy: string}> */
    private array $taxonomies = [];

    /** @var list<array{source: string, role: string}> */
    private array $mediaFields = [];

    private function __construct(private readonly string $slug) {}

    public static function make(string $slug): self
    {
        return new self($slug);
    }

    /**
     * Declares the source column used as the unique import key.
     * The value is stored under $metaKey (defaults to _{slug}_id) for upsert lookups.
     */
    public function id(string $sourceColumn, ?string $metaKey = null): self
    {
        $this->idColumn = $sourceColumn;
        $this->idMetaKey = $metaKey ?? "_{$this->slug}_id";
        return $this;
    }

    public function title(string $sourceColumn): self
    {
        $this->titleColumn = $sourceColumn;
        return $this;
    }

    public function excerpt(string $sourceColumn): self
    {
        $this->excerptColumn = $sourceColumn;
        return $this;
    }

    public function content(string $sourceColumn): self
    {
        $this->contentColumn = $sourceColumn;
        return $this;
    }

    /**
     * Maps a source column to post_status.
     * Falls back to $default when the column value is empty.
     */
    public function status(string $sourceColumn, string $default = 'publish'): self
    {
        $this->statusColumn = $sourceColumn;
        $this->defaultStatus = $default;
        return $this;
    }

    /**
     * Maps a source column to a post meta key.
     * An optional transform callable receives the raw cell value and must return the stored value.
     */
    public function meta(string $sourceColumn, string $metaKey, ?callable $transform = null): self
    {
        $this->metaFields[] = ['source' => $sourceColumn, 'key' => $metaKey, 'transform' => $transform];
        return $this;
    }

    /**
     * Maps a source column to a WordPress taxonomy.
     *
     * The cell is split on $separator into individual terms.
     * Each term may contain $hierarchy to denote parent > child relationships.
     *
     * Example cell: "Electronics > Audio, Bestseller"
     */
    public function taxonomy(
        string $taxonomy,
        string $sourceColumn,
        string $separator = ',',
        string $hierarchy = '>',
    ): self {
        $this->taxonomies[] = [
            'taxonomy'  => $taxonomy,
            'source'    => $sourceColumn,
            'separator' => $separator,
            'hierarchy' => $hierarchy,
        ];
        return $this;
    }

    /** Maps a source column to the post featured image (post thumbnail). */
    public function featuredImage(string $sourceColumn): self
    {
        return $this->media($sourceColumn, 'featured');
    }

    /** Maps a source column to a media attachment with a named role. */
    public function media(string $sourceColumn, string $role = 'gallery'): self
    {
        $this->mediaFields[] = ['source' => $sourceColumn, 'role' => $role];
        return $this;
    }

    // --- Getters ---

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getIdColumn(): ?string
    {
        return $this->idColumn;
    }

    public function getIdMetaKey(): ?string
    {
        return $this->idMetaKey;
    }

    public function getTitleColumn(): ?string
    {
        return $this->titleColumn;
    }

    public function getExcerptColumn(): ?string
    {
        return $this->excerptColumn;
    }

    public function getContentColumn(): ?string
    {
        return $this->contentColumn;
    }

    public function getStatusColumn(): ?string
    {
        return $this->statusColumn;
    }

    public function getDefaultStatus(): string
    {
        return $this->defaultStatus;
    }

    /** @return list<array{source: string, key: string, transform: callable|null}> */
    public function getMetaFields(): array
    {
        return $this->metaFields;
    }

    /** @return list<array{taxonomy: string, source: string, separator: string, hierarchy: string}> */
    public function getTaxonomies(): array
    {
        return $this->taxonomies;
    }

    /** @return list<array{source: string, role: string}> */
    public function getMediaFields(): array
    {
        return $this->mediaFields;
    }
}
