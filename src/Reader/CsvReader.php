<?php
declare(strict_types=1);

namespace Barnemax\WpDataCli\Reader;

use Barnemax\WpDataCli\Exception\ReaderException;

class CsvReader implements RowReader
{
    public function __construct(
        private readonly string $filePath,
        private readonly string $delimiter = ',',
        private readonly string $encoding = 'UTF-8',
    ) {}

    public function countRows(): int
    {
        try {
            $file = new \SplFileObject($this->filePath);
        } catch (\RuntimeException) {
            return 0;
        }

        $file->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY | \SplFileObject::READ_AHEAD);
        $file->setCsvControl($this->delimiter);

        $count = -1; // start at -1 so the header row brings us to 0
        foreach ($file as $row) {
            if (\is_array($row)) {
                $count++;
            }
        }

        return max(0, $count);
    }

    public function getRows(): \Generator
    {
        try {
            $file = new \SplFileObject($this->filePath);
        } catch (\RuntimeException $e) {
            throw new ReaderException("Cannot open file: {$this->filePath}", previous: $e);
        }

        $file->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY | \SplFileObject::READ_AHEAD);
        $file->setCsvControl($this->delimiter);

        $headers = null;

        foreach ($file as $row) {
            if (!\is_array($row)) {
                continue;
            }

            if ($headers === null) {
                $headers = \array_map(
                    static fn($v) => \is_string($v) ? \trim($v) : (string) $v,
                    $row,
                );
                continue;
            }

            $padded = \array_slice(
                \array_pad($row, \count($headers), null),
                0,
                \count($headers),
            );

            $mapped = \array_combine($headers, $padded);

            if ($this->encoding !== 'UTF-8') {
                $mapped = \array_map(
                    static fn($v) => \is_string($v)
                        ? \mb_convert_encoding($v, 'UTF-8', $this->encoding)
                        : $v,
                    $mapped,
                );
            }

            yield $mapped;
        }

        if ($headers === null) {
            throw new ReaderException("CSV file is empty or has no headers: {$this->filePath}");
        }
    }
}
