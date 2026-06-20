<?php
declare(strict_types=1);

namespace Barnemax\WpDataCli\Importer;

class MediaHandler
{
    private const SOURCE_URL_META = '_import_source_url';

    /** @var array<string, int> Per-run URL → attachment ID cache to avoid redundant sideloads. */
    private array $cache = [];

    /**
     * Sideloads a remote URL into the media library and returns the attachment ID.
     * Returns null on download or sideload failure — the caller should skip the field, not abort.
     *
     * Deduplication is by source URL: an attachment already tagged with the URL is reused.
     */
    public function sideload(string $url, int $postId): ?int
    {
        if (isset($this->cache[$url])) {
            return $this->cache[$url];
        }

        $existing = $this->findBySourceUrl($url);
        if ($existing !== null) {
            $this->cache[$url] = $existing;
            return $existing;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $tmpFile = \download_url($url);
        if (\is_wp_error($tmpFile)) {
            return null;
        }

        $fileArray = [
            'name'     => \basename(\wp_parse_url($url, PHP_URL_PATH) ?: 'media'),
            'tmp_name' => $tmpFile,
        ];

        $attachmentId = \media_handle_sideload($fileArray, $postId);

        // Always clean up the temp file, even on failure.
        if (\file_exists($tmpFile)) {
            \wp_delete_file($tmpFile);
        }

        if (\is_wp_error($attachmentId)) {
            return null;
        }

        \update_post_meta($attachmentId, self::SOURCE_URL_META, $url);
        $this->cache[$url] = $attachmentId;

        return $attachmentId;
    }

    private function findBySourceUrl(string $url): ?int
    {
        $posts = \get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'meta_key'       => self::SOURCE_URL_META,
            'meta_value'     => $url, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- bounded to 1 row, dedup-only path
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);

        return !empty($posts) ? (int) $posts[0] : null;
    }

    public function clearCache(): void
    {
        $this->cache = [];
    }
}
