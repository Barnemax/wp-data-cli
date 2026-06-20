<?php
declare(strict_types=1);

namespace Barnemax\WpDataCli\Mapper;

use Barnemax\WpDataCli\Exception\MapperException;

class Mapper
{
    public function __construct(private readonly FieldMap $map) {}

    /**
     * Maps a raw reader row to a MappedRecord.
     *
     * @param array<string, mixed> $rawRow
     * @throws MapperException when required fields are absent or a transform throws.
     */
    public function map(array $rawRow): MappedRecord
    {
        $idColumn = $this->map->getIdColumn()
            ?? throw new MapperException('FieldMap has no ->id() column defined.');

        if (!isset($rawRow[$idColumn]) || (string) $rawRow[$idColumn] === '') {
            throw new MapperException("Source ID column '{$idColumn}' is missing or empty.");
        }

        $sourceId = (string) $rawRow[$idColumn];

        $postFields = ['post_status' => $this->map->getDefaultStatus()];

        if ($col = $this->map->getTitleColumn()) {
            $postFields['post_title'] = (string) ($rawRow[$col] ?? '');
        }

        if ($col = $this->map->getExcerptColumn()) {
            $postFields['post_excerpt'] = (string) ($rawRow[$col] ?? '');
        }

        if ($col = $this->map->getContentColumn()) {
            $postFields['post_content'] = (string) ($rawRow[$col] ?? '');
        }

        if ($col = $this->map->getStatusColumn()) {
            $value = (string) ($rawRow[$col] ?? '');
            $postFields['post_status'] = $value !== '' ? $value : $this->map->getDefaultStatus();
        }

        $meta = [];
        foreach ($this->map->getMetaFields() as $field) {
            $value = $rawRow[$field['source']] ?? null;
            if ($field['transform'] !== null) {
                try {
                    $value = ($field['transform'])($value);
                } catch (\Throwable $e) {
                    throw new MapperException(
                        "Transform failed for meta field '{$field['key']}': {$e->getMessage()}",
                        previous: $e,
                    );
                }
            }
            $meta[$field['key']] = $value;
        }

        $taxonomies = [];
        foreach ($this->map->getTaxonomies() as $tax) {
            $raw = (string) ($rawRow[$tax['source']] ?? '');
            $taxonomies[$tax['taxonomy']] = $this->parseTermPaths($raw, $tax['separator'], $tax['hierarchy']);
        }

        $media = [];
        foreach ($this->map->getMediaFields() as $field) {
            $url = \trim((string) ($rawRow[$field['source']] ?? ''));
            if ($url !== '') {
                $media[] = ['url' => $url, 'role' => $field['role']];
            }
        }

        return new MappedRecord(
            sourceId: $sourceId,
            postFields: $postFields,
            meta: $meta,
            taxonomies: $taxonomies,
            media: $media,
        );
    }

    /**
     * Parses "Electronics > Audio, Bestseller" into [['Electronics', 'Audio'], ['Bestseller']].
     *
     * @return list<list<string>>
     */
    private function parseTermPaths(string $raw, string $separator, string $hierarchy): array
    {
        if ($raw === '') {
            return [];
        }

        $terms = [];
        foreach (\explode($separator, $raw) as $term) {
            $term = \trim($term);
            if ($term === '') {
                continue;
            }
            $path = \array_values(\array_filter(
                \array_map('trim', \explode($hierarchy, $term)),
                static fn($v) => $v !== '',
            ));
            if ($path !== []) {
                $terms[] = $path;
            }
        }

        return $terms;
    }
}
