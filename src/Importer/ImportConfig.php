<?php
declare(strict_types=1);

namespace Barnemax\WpDataCli\Importer;

use Barnemax\WpDataCli\Mapper\FieldMap;

class ImportConfig
{
    public function __construct(
        public readonly string $file,
        public readonly FieldMap $map,
        public readonly string $postType = 'post',
        public readonly int $batchSize = 50,
        public readonly bool $dryRun = false,
        public readonly bool $continueOnError = false,
        public readonly int $offset = 0,
        public readonly ?string $sheet = null,
        public readonly string $encoding = 'UTF-8',
        public readonly string $delimiter = ',',
    ) {}

    /**
     * Constructs from WP-CLI $assoc_args.
     *
     * @param array<string, string|bool> $assocArgs
     */
    public static function fromCliArgs(array $assocArgs, FieldMap $map): self
    {
        return new self(
            file: (string) ($assocArgs['file'] ?? ''),
            map: $map,
            postType: isset($assocArgs['post-type']) ? (string) $assocArgs['post-type'] : 'post',
            batchSize: isset($assocArgs['batch']) ? (int) $assocArgs['batch'] : 50,
            dryRun: (bool) ($assocArgs['dry-run'] ?? false),
            continueOnError: (bool) ($assocArgs['continue-on-error'] ?? false),
            offset: isset($assocArgs['offset']) ? (int) $assocArgs['offset'] : 0,
            sheet: isset($assocArgs['sheet']) ? (string) $assocArgs['sheet'] : null,
            encoding: isset($assocArgs['encoding']) ? (string) $assocArgs['encoding'] : 'UTF-8',
            delimiter: isset($assocArgs['delimiter']) ? (string) $assocArgs['delimiter'] : ',',
        );
    }
}
