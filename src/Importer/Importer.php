<?php
declare(strict_types=1);

namespace Barnemax\WpDataCli\Importer;

use Barnemax\WpDataCli\Exception\ImportException;
use Barnemax\WpDataCli\Mapper\Mapper;
use Barnemax\WpDataCli\Mapper\MappedRecord;
use Barnemax\WpDataCli\Reader\ReaderFactory;
use Barnemax\WpDataCli\Reader\RowReader;

class Importer
{
    private UpsertStrategy $upsert;
    private TermResolver $termResolver;
    private MediaHandler $media;

    public function __construct(
        private readonly ImportConfig $config,
        ?UpsertStrategy $upsert = null,
        ?TermResolver $termResolver = null,
        ?MediaHandler $media = null,
    ) {
        $this->upsert       = $upsert ?? new UpsertStrategy();
        $this->termResolver = $termResolver ?? new TermResolver();
        $this->media        = $media ?? new MediaHandler();
    }

    /**
     * @param callable(): void $onProgress  Called once per processed row (success, failure, or dry-run).
     */
    public function run(?callable $onProgress = null, ?RowReader $reader = null): RunReport
    {
        $reader ??= ReaderFactory::create(
            $this->config->file,
            $this->config->sheet,
            $this->config->delimiter,
            $this->config->encoding,
        );
        $mapper = new Mapper($this->config->map);
        $report = new RunReport();

        $this->beginRun();

        try {
            $batch    = [];
            $rowIndex = 0;

            foreach ($reader->getRows() as $rawRow) {
                $rowIndex++;

                if ($rowIndex <= $this->config->offset) {
                    continue;
                }

                try {
                    $record  = $mapper->map($rawRow);
                    $batch[] = ['index' => $rowIndex, 'record' => $record];
                } catch (\Throwable $e) {
                    $idColumn = $this->config->map->getIdColumn() ?? '';
                    $sourceId = isset($rawRow[$idColumn]) ? (string) $rawRow[$idColumn] : '?';
                    $report->recordFailed($rowIndex, $sourceId, $e->getMessage());
                    if ($onProgress !== null) {
                        ($onProgress)();
                    }
                    if (!$this->config->continueOnError) {
                        throw new ImportException("Mapping failed at row {$rowIndex}: {$e->getMessage()}", previous: $e);
                    }
                    continue;
                }

                if (\count($batch) >= $this->config->batchSize) {
                    $this->processBatch($batch, $report, $onProgress);
                    $batch = [];
                    $this->freeBatchMemory();
                }
            }

            if ($batch !== []) {
                $this->processBatch($batch, $report, $onProgress);
            }
        } finally {
            $this->endRun();
        }

        return $report;
    }

    /** @param list<array{index: int, record: MappedRecord}> $batch */
    private function processBatch(array $batch, RunReport $report, ?callable $onProgress = null): void
    {
        foreach ($batch as ['index' => $index, 'record' => $record]) {
            try {
                $this->processRecord($record, $report);
            } catch (\Throwable $e) {
                $report->recordFailed($index, $record->sourceId, $e->getMessage());
                if (!$this->config->continueOnError) {
                    throw new ImportException(
                        "Import failed at row {$index} (source ID: {$record->sourceId}): {$e->getMessage()}",
                        previous: $e,
                    );
                }
            }
            if ($onProgress !== null) {
                ($onProgress)();
            }
        }
    }

    private function processRecord(MappedRecord $record, RunReport $report): void
    {
        if ($this->config->dryRun) {
            $report->recordSkipped();
            return;
        }

        $existingId = $this->upsert->findExisting(
            $this->config->map->getIdMetaKey(),
            $record->sourceId,
            $this->config->postType,
        );

        $postData = \array_merge($record->postFields, ['post_type' => $this->config->postType]);

        if ($existingId !== null) {
            $postData['ID'] = $existingId;
            $postId = \wp_update_post($postData, true);
        } else {
            $postId = \wp_insert_post($postData, true);
        }

        if (\is_wp_error($postId)) {
            throw new ImportException($postId->get_error_message());
        }

        if ($existingId === null) {
            $this->upsert->recordInsert($record->sourceId, $postId);
        }

        \update_post_meta($postId, $this->config->map->getIdMetaKey(), $record->sourceId);

        foreach ($record->meta as $key => $value) {
            \update_post_meta($postId, $key, $value);
        }

        foreach ($record->taxonomies as $taxonomy => $termPaths) {
            $termIds = $this->termResolver->resolve($taxonomy, $termPaths);
            \wp_set_object_terms($postId, $termIds, $taxonomy);
        }

        foreach ($record->media as ['url' => $url, 'role' => $role]) {
            $attachmentId = $this->media->sideload($url, $postId);
            if ($attachmentId === null) {
                continue;
            }
            if ($role === 'featured') {
                \set_post_thumbnail($postId, $attachmentId);
            }
        }

        if ($existingId !== null) {
            $report->recordUpdated();
        } else {
            $report->recordInserted();
        }
    }

    private function beginRun(): void
    {
        if ($this->config->dryRun) {
            return;
        }

        \wp_suspend_cache_addition(true);
        \wp_defer_term_counting(true);
        \wp_defer_comment_counting(true);

        // One query per taxonomy instead of term_exists() on every row.
        foreach ($this->config->map->getTaxonomies() as $tax) {
            $this->termResolver->preload($tax['taxonomy']);
        }

        // One query total instead of get_posts() on every row.
        $this->upsert->preload(
            $this->config->map->getIdMetaKey(),
            $this->config->postType,
        );
    }

    private function endRun(): void
    {
        if ($this->config->dryRun) {
            return;
        }

        \wp_suspend_cache_addition(false);
        \wp_defer_term_counting(false);
        \wp_defer_comment_counting(false);
        \wp_cache_flush();

        $this->upsert->reset();
        $this->termResolver->reset();
        $this->media->clearCache();
    }

    private function freeBatchMemory(): void
    {
        \wp_cache_flush();
        $this->media->clearCache();

        if (\defined('SAVEQUERIES') && SAVEQUERIES) {
            global $wpdb;
            $wpdb->queries = [];
        }

        \gc_collect_cycles();
    }
}
