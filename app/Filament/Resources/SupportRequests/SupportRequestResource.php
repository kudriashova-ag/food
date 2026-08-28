<?php

namespace App\Filament\Resources\SupportRequests;

use App\Filament\Resources\SupportRequests\Pages\ListSupportRequests;
use App\Filament\Resources\SupportRequests\Tables\SupportRequestsTable;
use App\Models\SupportRequest;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Питання з форми «Допомога».
 *
 * Тільки перегляд: звернення приходять поштою й у Telegram, а тут лежать
 * на випадок, якщо канал відмовив або лист загубився.
 */
class SupportRequestResource extends Resource
{
    protected static ?string $model = SupportRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $navigationLabel = 'Звернення';

    protected static ?string $modelLabel = 'звернення';

    protected static ?string $pluralModelLabel = 'звернення';

    protected static ?int $navigationSort = 7;

    public static function table(Table $table): Table
    {
        return SupportRequestsTable::configure($table);
    }

    /** Повний текст питання в окремому вікні: у колонці він обрізаний. */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Звернення')
                ->columns(2)
                ->schema([
                    TextEntry::make('name')->label('Від'),
                    TextEntry::make('email')->label('Email')->copyable(),
                    TextEntry::make('created_at')
                        ->label('Надійшло')
                        ->formatStateUsing(fn ($state): string => $state->translatedFormat('d.m.Y о H:i')),
                    TextEntry::make('user.login')->label('Обліковий запис')->placeholder('гість'),
                    TextEntry::make('message')->label('Питання')->columnSpanFull(),
                ]),
        ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** Свіжі звернення видно з меню — щоб питання не чекали тижнями. */
    public static function getNavigationBadge(): ?string
    {
        $count = SupportRequest::query()
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupportRequests::route('/'),
        ];
    }
}
