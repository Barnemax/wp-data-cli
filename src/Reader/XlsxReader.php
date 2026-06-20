<?php
declare(strict_types=1);

namespace Barnemax\WpDataCli\Reader;

use Barnemax\WpDataCli\Exception\ReaderException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Reads XLSX (and XLS) files via PhpSpreadsheet.
 *
 * Note: PhpSpreadsheet loads the entire workbook into memory before iteration begins.
 * This is the one reader that does not truly stream; very large files will use
 * proportionally more memory regardless of batch size.
 */
class XlsxReader implements RowReader
{
    private ?\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet = null;

    public function __construct(
        private readonly string $filePath,
        private readonly ?string $sheet = null,
    ) {}

    public function countRows(): int
    {
        return max(0, $this->getWorksheet()->getHighestDataRow() - 1);
    }

    public function getRows(): \Generator
    {
        yield from $this->iterateWorksheet($this->getWorksheet());
    }

    private function getWorksheet(): Worksheet
    {
        if ($this->spreadsheet === null) {
            try {
                $reader = IOFactory::createReaderForFile($this->filePath);
                $reader->setReadDataOnly(true);
                if ($this->sheet !== null) {
                    $reader->setLoadSheetsOnly([$this->sheet]);
                }
                $this->spreadsheet = $reader->load($this->filePath);
            } catch (\PhpOffice\PhpSpreadsheet\Exception $e) {
                throw new ReaderException("Cannot read XLSX file: {$this->filePath}", previous: $e);
            }
        }

        $worksheet = $this->sheet !== null
            ? $this->spreadsheet->getSheetByName($this->sheet)
            : $this->spreadsheet->getActiveSheet();

        if ($worksheet === null) {
            throw new ReaderException("Sheet '{$this->sheet}' not found in: {$this->filePath}");
        }

        return $worksheet;
    }

    private function iterateWorksheet(Worksheet $worksheet): \Generator
    {
        $headers = null;

        foreach ($worksheet->getRowIterator() as $row) {
            $cells = [];
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);

            foreach ($cellIterator as $cell) {
                $cells[] = $cell->getValue();
            }

            // Skip entirely empty rows.
            if (\array_filter($cells, static fn($v) => $v !== null && $v !== '') === []) {
                continue;
            }

            if ($headers === null) {
                $headers = \array_map(
                    static fn($v) => \is_string($v) ? \trim($v) : (string) $v,
                    $cells,
                );
                continue;
            }

            $padded = \array_slice(
                \array_pad($cells, \count($headers), null),
                0,
                \count($headers),
            );

            yield \array_combine($headers, $padded);
        }
    }
}
