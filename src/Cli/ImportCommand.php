
<?php
declare(strict_types=1);

namespace Barnemax\WpDataCli\Cli;

use Barnemax\WpDataCli\Importer\ImportConfig;
use Barnemax\WpDataCli\Importer\Importer;
use Barnemax\WpDataCli\Mapper\FieldMap;
use Barnemax\WpDataCli\Reader\ReaderFactory;

/**
 * Import CSV or XLSX data into WordPress.
 *
 * ## EXAMPLES
 *
 *     wp import run --file=products.xlsx --mapping=mappings/products.php
 */
class ImportCommand
{
    /**
     * Import a source file into WordPress.
     *
     * ## OPTIONS
     *
     * --file=<path>
     * : Path to the CSV or XLSX source file.
     *
     * --mapping=<path>
     * : Path to a PHP file that returns a FieldMap instance.
     *
     * [--post-type=<type>]
     * : WordPress post type to import into. Default: post.
     *
     * [--batch=<n>]
     * : Number of rows to process per batch. Default: 50.
     *
     * [--sheet=<name>]
     * : Worksheet name (XLSX only). Defaults to the active sheet.
     *
     * [--encoding=<charset>]
     * : Source file character encoding (CSV only). Default: UTF-8.
     *
     * [--delimiter=<char>]
     * : CSV column delimiter. Default: ,.
     *
     * [--offset=<n>]
     * : Number of data rows to skip before importing (for resuming a partial run).
     *
     * [--dry-run]
     * : Run the full pipeline without writing anything to WordPress.
     *
     * [--continue-on-error]
     * : Log row failures and continue instead of aborting on the first error.
     *
     * ## EXAMPLES
     *
     *     wp import run --file=products.xlsx --mapping=mappings/products.php --dry-run
     *     wp import run --file=data.csv --mapping=mappings/products.php --post-type=product --batch=100
     *     wp import run --file=data.xlsx --mapping=mappings/products.php --offset=500 --continue-on-error
     *
     * @param array<string>              $args
     * @param array<string, string|bool> $assocArgs
     */
    public function run(array $args, array $assocArgs): void
    {
        $mappingFile = (string) ($assocArgs['mapping'] ?? '');

        if ($mappingFile === '' || !\file_exists($mappingFile)) {
            \WP_CLI::error("Mapping file not found: {$mappingFile}");
            return;
        }

        $map = require $mappingFile;

        if (!$map instanceof FieldMap) {
            \WP_CLI::error('The mapping file must return a FieldMap instance.');
            return;
        }

        $config = ImportConfig::fromCliArgs($assocArgs, $map);

        if (!\file_exists($config->file)) {
            \WP_CLI::error("Source file not found: {$config->file}");
            return;
        }

        if ($config->dryRun) {
            \WP_CLI::log('Dry-run mode — nothing will be written to WordPress.');
        }

        $reader   = ReaderFactory::create($config->file, $config->sheet, $config->delimiter, $config->encoding);
        $total    = max(1, $reader->countRows() - $config->offset);
        $progress = \WP_CLI\Utils\make_progress_bar('Importing', $total);

        $importer = new Importer($config);
        $report   = $importer->run(
            onProgress: static function () use ($progress): void { $progress->tick(); },
            reader: $reader,
        );

        $progress->finish();

        \WP_CLI::log($report->summary());

        foreach ($report->getErrors() as $error) {
            \WP_CLI::warning("Row {$error['row']} ({$error['source_id']}): {$error['message']}");
        }

        if ($report->getFailed() > 0) {
            \WP_CLI::error('Import finished with failures. See warnings above.');
        } else {
            \WP_CLI::success('Import complete.');
        }
    }
}
