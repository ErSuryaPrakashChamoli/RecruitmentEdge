<?php

namespace App\Filament\Resources\RecruiterIncentiveCalculations\Schemas;

use Filament\Schemas\Schema;

/**
 * No create/edit form: calculations are produced only by RecruiterIncentiveCalculator. This
 * resource is read-only plus a set of lifecycle actions (see the table class).
 */
class RecruiterIncentiveCalculationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([]);
    }
}
