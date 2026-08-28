<?php

namespace App\Services;

use App\Models\CodeSequence;
use Illuminate\Support\Facades\DB;

/**
 * Generates human-readable, gap-free-per-year codes like REQ-2026-000001 or CAND-2026-000001.
 * Backed by a row-locked counter (`code_sequences`) rather than "max + 1" queries, so concurrent
 * requests can never hand out the same number.
 */
class SequenceCodeGenerator
{
    public function next(string $prefix): string
    {
        $year = (int) now()->format('Y');
        $key = strtolower($prefix).':'.$year;

        return DB::transaction(function () use ($prefix, $year, $key): string {
            $sequence = CodeSequence::query()->lockForUpdate()->firstOrCreate(['key' => $key]);
            $sequence->increment('last_number');

            return sprintf('%s-%d-%06d', strtoupper($prefix), $year, $sequence->last_number);
        });
    }
}
