<?php
declare(strict_types=1);

namespace Barnemax\WpDataCli\Importer;

class RunReport
{
    private int $inserted = 0;
    private int $updated = 0;
    private int $skipped = 0;
    private int $failed = 0;

    /** @var list<array{row: int, source_id: string, message: string}> */
    private array $errors = [];

    public function recordInserted(): void
    {
        $this->inserted++;
    }

    public function recordUpdated(): void
    {
        $this->updated++;
    }

    public function recordSkipped(): void
    {
        $this->skipped++;
    }

    public function recordFailed(int $row, string $sourceId, string $message): void
    {
        $this->failed++;
        $this->errors[] = ['row' => $row, 'source_id' => $sourceId, 'message' => $message];
    }

    public function getInserted(): int
    {
        return $this->inserted;
    }

    public function getUpdated(): int
    {
        return $this->updated;
    }

    public function getSkipped(): int
    {
        return $this->skipped;
    }

    public function getFailed(): int
    {
        return $this->failed;
    }

    /** @return list<array{row: int, source_id: string, message: string}> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function total(): int
    {
        return $this->inserted + $this->updated + $this->skipped + $this->failed;
    }

    public function summary(): string
    {
        return \sprintf(
            'Processed %d rows: %d inserted, %d updated, %d skipped, %d failed.',
            $this->total(),
            $this->inserted,
            $this->updated,
            $this->skipped,
            $this->failed,
        );
    }
}
