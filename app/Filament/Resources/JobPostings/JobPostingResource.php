<?php

namespace App\Filament\Resources\JobPostings;

use App\Filament\Resources\JobPostings\Pages\CreateJobPosting;
use App\Filament\Resources\JobPostings\Pages\EditJobPosting;
use App\Filament\Resources\JobPostings\Pages\ListJobPostings;
use App\Filament\Resources\JobPostings\Pages\ViewJobPosting;
use App\Filament\Resources\JobPostings\RelationManagers\DistributionsRelationManager;
use App\Filament\Resources\JobPostings\Schemas\JobPostingForm;
use App\Filament\Resources\JobPostings\Schemas\JobPostingInfolist;
use App\Filament\Resources\JobPostings\Tables\JobPostingsTable;
use App\Filament\Resources\RecruitmentRequisitions\RecruitmentRequisitionResource;
use App\Models\JobPosting;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Phase 5 Job Publishing: public adverts for requisitions and their distribution to the career
 * site, the XML feed and job boards. Scoped to requisitions the user can see; every publishing
 * operation goes through JobDistributionService.
 */
class JobPostingResource extends Resource
{
    protected static ?string $model = JobPosting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'Distribution';

    protected static ?string $navigationLabel = 'Job Publishing';

    protected static ?string $modelLabel = 'job posting';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return JobPostingForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return JobPostingInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return JobPostingsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('requisition_id', RecruitmentRequisitionResource::getEloquentQuery()->select('recruitment_requisitions.id'));
    }

    public static function getRelations(): array
    {
        return [DistributionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListJobPostings::route('/'),
            'create' => CreateJobPosting::route('/create'),
            'view' => ViewJobPosting::route('/{record}'),
            'edit' => EditJobPosting::route('/{record}/edit'),
        ];
    }
}
