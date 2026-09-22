<?php

namespace App\Filament\Resources\Candidates\Schemas;

use App\Models\Candidate;
use Filament\Forms\Components\Select;

/**
 * The "pick a candidate" field for forms whose model has a `candidate` relationship. Names repeat,
 * so each option shows the candidate's mobile number too, and search matches the name, mobile or
 * candidate code.
 */
class CandidatePicker
{
    public static function make(string $name = 'candidate_id'): Select
    {
        return Select::make($name)
            ->label('Candidate')
            ->relationship('candidate', 'full_name')
            ->getOptionLabelFromRecordUsing(fn (Candidate $record): string => self::label($record))
            ->searchable(['full_name', 'mobile', 'candidate_code'])
            ->preload();
    }

    public static function label(Candidate $candidate): string
    {
        return collect([$candidate->full_name, $candidate->mobile])->filter()->implode(' · ');
    }
}
