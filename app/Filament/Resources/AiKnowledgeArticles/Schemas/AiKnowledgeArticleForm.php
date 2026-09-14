<?php

namespace App\Filament\Resources\AiKnowledgeArticles\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class AiKnowledgeArticleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true),
                Select::make('category')
                    ->options([
                        'policy' => 'Policy',
                        'process' => 'Process',
                        'faq' => 'FAQ',
                        'general' => 'General',
                    ])
                    ->default('general')
                    ->required(),
                Textarea::make('content')
                    ->required()
                    ->rows(8)
                    ->columnSpanFull()
                    ->helperText('Used by the AI Copilot: semantically indexed for AI answers once published, and keyword-searched when full AI is not configured. Write it the way you would answer the question directly.'),
                Toggle::make('is_published')
                    ->default(true)
                    ->required(),
            ]);
    }
}
