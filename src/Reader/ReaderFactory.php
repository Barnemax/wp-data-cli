<?php
declare(strict_types=1);

namespace Barnemax\WpDataCli\Reader;

use Barnemax\WpDataCli\Exception\ReaderException;

class ReaderFactory
{
    public static function create(
        string $filePath,
        ?string $sheet = null,
        string $delimiter = ',',
        string $encoding = 'UTF-8',
    ): RowReader {
        $extension = \strtolower(\pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'csv'         => new CsvReader($filePath, $delimiter, $encoding),
            'xlsx', 'xls' => new XlsxReader($filePath, $sheet),
            default       => throw new ReaderException("Unsupported file extension '.{$extension}'."),
        };
    }
}
