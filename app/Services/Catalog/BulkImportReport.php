<?php

namespace App\Services\Catalog;

/**
 * What an import did, line by line.
 *
 * Kept per line rather than as four totals because a paste of three hundred
 * addresses that reports "280 added" without saying which twenty were not is a
 * report nobody can act on. The screen prints the totals and lists everything
 * that did not become a new row.
 */
class BulkImportReport
{
    public int $added = 0;

    public int $restored = 0;

    public int $skipped = 0;

    /** @var list<array{line: int, address: string, slug: string, kind: string}> */
    public array $entries = [];

    /** @var list<array{line: int, input: string, reason: string}> */
    public array $rejected = [];

    public function add(int $line, string $address, string $slug): void
    {
        $this->added++;
        $this->entries[] = ['line' => $line, 'address' => $address, 'slug' => $slug, 'kind' => 'added'];
    }

    public function restored(int $line, string $address, string $slug): void
    {
        $this->restored++;
        $this->entries[] = ['line' => $line, 'address' => $address, 'slug' => $slug, 'kind' => 'restored'];
    }

    public function skip(int $line, string $address, string $slug): void
    {
        $this->skipped++;
        $this->entries[] = ['line' => $line, 'address' => $address, 'slug' => $slug, 'kind' => 'skipped'];
    }

    public function reject(int $line, string $input, string $reason): void
    {
        $this->rejected[] = ['line' => $line, 'input' => $input, 'reason' => $reason];
    }

    public function total(): int
    {
        return $this->added + $this->restored + $this->skipped + count($this->rejected);
    }

    /** @return list<array{line: int, address: string, slug: string, kind: string}> */
    public function skippedEntries(): array
    {
        return array_values(array_filter($this->entries, fn (array $e) => $e['kind'] === 'skipped'));
    }

    /** A one-line summary for the flash message above the form. */
    public function summary(): string
    {
        $parts = ["{$this->added} added"];

        if ($this->restored > 0) {
            $parts[] = "{$this->restored} restored";
        }

        if ($this->skipped > 0) {
            $parts[] = "{$this->skipped} already listed";
        }

        if ($this->rejected !== []) {
            $parts[] = count($this->rejected).' could not be read';
        }

        return implode(', ', $parts).'. Queued for verification — they appear in the listings once our monitor reaches them.';
    }
}
