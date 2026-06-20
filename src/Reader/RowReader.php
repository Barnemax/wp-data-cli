<?php
declare(strict_types=1);

namespace Barnemax\WpDataCli\Reader;

interface RowReader
{
    /**
     * Yields one row per call, keyed by the raw header names from the source file.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function getRows(): \Generator;

    /**
     * Returns the number of data rows (excluding the header).
     * May be approximate — intended for progress-bar sizing, not validation.
     */
    public function countRows(): int;
}
