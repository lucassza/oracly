<?php

namespace App\Filament\Resources\ApiClients;

use App\Filament\Resources\ApiClients\Pages\ListApiClients;
use App\Models\ApiClient;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ApiClientResource extends Resource
{
    protected static ?string $model = ApiClient::class;

    protected static ?string $navigationLabel = 'Clients API';

    protected static ?string $modelLabel = 'client API';

    protected static ?string $pluralModelLabel = 'clients API';

    protected static string|UnitEnum|null $navigationGroup = 'Configuração';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nome')->required()->maxLength(100),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nome')->searchable(),
                TextColumn::make('client_id')->label('Client ID')->copyable(),
                TextColumn::make('last_used_at')->label('Último uso')->dateTime('d/m/Y H:i')->placeholder('Nunca'),
                TextColumn::make('revoked_at')->label('Status')->formatStateUsing(fn (?string $state): string => $state === null ? 'Ativo' : 'Revogado')->badge(),
            ])
            ->headerActions([
                Action::make('createClient')
                    ->label('Novo client')
                    ->schema([TextInput::make('name')->label('Nome')->required()->maxLength(100)])
                    ->action(function (array $data): void {
                        [$client, $token] = ApiClient::createWithToken($data['name']);
                        self::sendCredentialNotification($client, $token, 'Client criado');
                    }),
            ])
            ->recordActions([
                Action::make('rotateToken')
                    ->label('Regenerar token')
                    ->requiresConfirmation()
                    ->action(function (ApiClient $record): void {
                        self::sendCredentialNotification($record, $record->rotateToken(), 'Token regenerado');
                    }),
                Action::make('revoke')
                    ->label('Revogar')
                    ->color('danger')
                    ->visible(fn (ApiClient $record): bool => $record->revoked_at === null)
                    ->requiresConfirmation()
                    ->action(fn (ApiClient $record) => $record->forceFill(['revoked_at' => now()])->save()),
            ]);
    }

    /** @return array<string, class-string> */
    public static function getPages(): array
    {
        return ['index' => ListApiClients::route('/')];
    }

    private static function sendCredentialNotification(ApiClient $client, string $token, string $title): void
    {
        Notification::make()
            ->title($title)
            ->body("Copie agora. Client ID: {$client->client_id}\nToken: {$token}")
            ->warning()
            ->persistent()
            ->send();
    }
}
