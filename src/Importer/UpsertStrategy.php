<?php
declare(strict_types=1);

namespace Barnemax\WpDataCli\Importer;

/**
 * Finds or records whether a post already exists for a given import source ID.
 *
 * Call preload() once at the start of a run to bulk-fetch all existing mappings
 * into memory. Subsequent findExisting() calls are then O(1) array lookups.
 * New inserts must be registered via recordInsert() to keep the map consistent
 * within the same run (handles duplicate source IDs in the source file).
 */
class UpsertStrategy
{
    /**
     * source_id → post_id map. Null means preload() has not been called;
     * findExisting() falls back to a live DB query in that case.
     *
     * @var array<string, int>|null
     */
    private ?array $map = null;

    /**
     * Bulk-fetches all existing source_id → post_id mappings with one query.
     * Must be called before the import loop begins.
     */
    public function preload(string $metaKey, string $postType): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pm.meta_value AS source_id, pm.post_id
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = %s
                   AND p.post_type = %s
                   AND p.post_status != 'trash'",
                $metaKey,
                $postType,
            ),
            ARRAY_A,
        );

        $this->map = [];
        foreach ($rows as $row) {
            $this->map[(string) $row['source_id']] = (int) $row['post_id'];
        }
    }

    /**
     * Returns the existing post ID for a source ID, or null when none exists.
     * Consults the in-memory map when preload() has been called; otherwise queries the DB.
     */
    public function findExisting(string $metaKey, string $sourceId, string $postType): ?int
    {
        if ($this->map !== null) {
            return $this->map[$sourceId] ?? null;
        }

        $posts = \get_posts([
            'meta_key'       => $metaKey,
            'meta_value'     => $sourceId, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- fallback path only; preload() is the intended hot path
            'post_type'      => $postType,
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);

        return !empty($posts) ? (int) $posts[0] : null;
    }

    /**
     * Registers a newly inserted post so later rows in the same run find it in the map.
     */
    public function recordInsert(string $sourceId, int $postId): void
    {
        if ($this->map !== null) {
            $this->map[$sourceId] = $postId;
        }
    }

    public function reset(): void
    {
        $this->map = null;
    }
}
