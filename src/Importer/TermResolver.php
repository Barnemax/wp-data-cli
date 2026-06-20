<?php
declare(strict_types=1);

namespace Barnemax\WpDataCli\Importer;

/**
 * Resolves taxonomy term paths to term IDs, creating missing terms as needed.
 *
 * Pre-loading a taxonomy with preload() replaces per-row term_exists() calls
 * with O(1) array lookups for the duration of the run. Terms created mid-run
 * are added to the cache immediately so later rows find them without a DB hit.
 */
class TermResolver
{
    /**
     * Cache keyed as taxonomy → "parentId:name_lower" → term_id.
     *
     * A null entry for a taxonomy means it has not been pre-loaded yet;
     * lookups fall back to term_exists() in that case.
     *
     * @var array<string, array<string, int>>
     */
    private array $cache = [];

    /**
     * Bulk-loads all terms for a taxonomy into the in-memory cache.
     * Call once per taxonomy at the start of a run.
     */
    public function preload(string $taxonomy): void
    {
        $terms = \get_terms([
            'taxonomy'               => $taxonomy,
            'hide_empty'             => false,
            'fields'                 => 'all',
            'number'                 => 0,
            'update_term_meta_cache' => false,
        ]);

        $this->cache[$taxonomy] = [];

        if (\is_wp_error($terms) || empty($terms)) {
            return;
        }

        foreach ($terms as $term) {
            $key = $this->key((int) $term->parent, $term->name);
            $this->cache[$taxonomy][$key] = (int) $term->term_id;
        }
    }

    /**
     * Resolves a list of term paths to their leaf term IDs.
     * Creates any missing term along each path.
     *
     * @param list<list<string>> $termPaths e.g. [['Electronics', 'Audio'], ['Bestseller']]
     * @return list<int>
     */
    public function resolve(string $taxonomy, array $termPaths): array
    {
        $ids = [];

        foreach ($termPaths as $path) {
            $parentId = 0;

            foreach ($path as $termName) {
                $termId = $this->findOrCreate($termName, $taxonomy, $parentId);
                if ($termId === null) {
                    continue 2;
                }
                $parentId = $termId;
            }

            if ($parentId !== 0) {
                $ids[] = $parentId;
            }
        }

        return $ids;
    }

    private function findOrCreate(string $name, string $taxonomy, int $parentId): ?int
    {
        $key = $this->key($parentId, $name);

        if (isset($this->cache[$taxonomy][$key])) {
            return $this->cache[$taxonomy][$key];
        }

        // Cache miss — fall back to DB (handles terms created outside this run).
        $existing = \term_exists($name, $taxonomy, $parentId);

        if ($existing !== null && $existing !== 0) {
            $termId = (int) (\is_array($existing) ? $existing['term_id'] : $existing);
            $this->cache[$taxonomy][$key] = $termId;
            return $termId;
        }

        $result = \wp_insert_term($name, $taxonomy, ['parent' => $parentId]);

        if (\is_wp_error($result)) {
            return null;
        }

        $termId = (int) $result['term_id'];
        $this->cache[$taxonomy][$key] = $termId;
        return $termId;
    }

    private function key(int $parentId, string $name): string
    {
        return $parentId . ':' . \strtolower($name);
    }

    public function reset(): void
    {
        $this->cache = [];
    }
}
