<?php
declare(strict_types=1);

namespace Barnemax\WpDataCli\Mapper;

/**
 * Immutable value object representing one fully mapped, validated import row.
 * Passed from Mapper → Importer; never modified after construction.
 */
class MappedRecord
{
    /**
     * @param string                             $sourceId   The raw value of the upsert key column.
     * @param array<string, mixed>               $postFields post_title, post_content, post_status, etc.
     * @param array<string, mixed>               $meta       Meta key → value pairs (stored via update_post_meta).
     * @param array<string, list<list<string>>>  $taxonomies Taxonomy slug → list of term path arrays.
     *                                                       Each path is an ordered list from root → leaf.
     *                                                       E.g. [['Electronics', 'Audio'], ['Bestseller']]
     * @param list<array{url: string, role: string}> $media  Media attachments keyed by role.
     */
    public function __construct(
        public readonly string $sourceId,
        public readonly array $postFields,
        public readonly array $meta,
        public readonly array $taxonomies,
        public readonly array $media,
    ) {}
}
