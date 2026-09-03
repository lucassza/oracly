<?php

namespace App\Filament\Resources\SheetSources;

use App\Filament\Resources\SheetSources\Pages\ListSheetSources;
use App\Models\Punter\SheetSource;
use App\Oracly\Punter\SheetFetcher;
use App\Oracly\Punter\SheetImporter;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use RuntimeException;
use Throwable;
use UnitEnum;

/**
 * Gerencia de onde vêm as planilhas Punter (Google Sheets) sincronizadas para o
 * schema `punter`. As 10 fontes são pré-cadastradas por PunterSheetSourcesSeeder a
 * partir de config/punter.php — aqui só se atualiza URL/aba (caso o Punter mude a
 * planilha) e se dispara a sincronização manual. O "destino" (tabela + colunas
 * esperadas) fica travado em config/punter.php de propósito: reatribuí-lo aqui
 * gravaria dados de uma aba no formato de outra.
 */
class SheetSourceResource extends Resource
{
    protected static ?string $model = SheetSource::class;

    protected static ?string $navigationLabel = 'Fontes Punter';

    protected static ?string $modelLabel = 'fonte Punter';

    protected static ?string $pluralModelLabel = 'fontes Punter';

    protected static string|UnitEnum|null $navigationGroup = 'Configuração';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('label')->label('Rótulo')->required()->maxLength(150),
            TextInput::make('spreadsheet_url')
                ->label('URL da planilha (Google Sheets)')
                ->required()
                ->url()
                ->helperText('Cole o link completo — o ID é extraído automaticamente.'),
            TextInput::make('sheet_name')->label('Nome da aba')->required()->maxLength(150),
            TextInput::make('range')->label('Intervalo (opcional)')->helperText('Ex.: B3:F — deixe vazio para a aba inteira.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')->label('Fonte')->searchable(),
                TextColumn::make('sheet_name')->label('Aba')->wrap(),
                TextColumn::make('target')->label('Destino')->badge()->color('gray'),
                TextColumn::make('last_sync_at')->label('Último sync')->dateTime('d/m/Y H:i')->placeholder('Nunca'),
                TextColumn::make('last_row_count')->label('Linhas')->numeric(),
                TextColumn::make('last_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'success' => 'success',
                        'error' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'success' => 'OK', 'error' => 'Erro', default => 'Nunca sincronizado',
                    }),
                TextColumn::make('last_error')->label('Erro')->limit(60)->tooltip(fn (?string $state): ?string => $state)->placeholder('—'),
                IconColumn::make('is_active')->label('Ativa')->boolean(),
            ])
            ->headerActions([
                Action::make('updateSpreadsheetUrl')
                    ->label('Atualizar URL de uma planilha')
                    ->icon('heroicon-o-link')
                    ->color('gray')
                    ->schema([
                        Select::make('spreadsheet_key')
                            ->label('Planilha')
                            ->options(self::spreadsheetOptions())
                            ->required()
                            ->native(false),
                        TextInput::make('new_url')
                            ->label('Nova URL do Google Sheets')
                            ->required()
                            ->url()
                            ->helperText('Todas as fontes dessa planilha são revalidadas (cabeçalho de cada aba) e só são atualizadas se todas baterem.'),
                    ])
                    ->action(function (array $data): void {
                        $spreadsheetKey = $data['spreadsheet_key'];
                        $newId = self::extractSpreadsheetId($data['new_url']);
                        if ($newId === null) {
                            Notification::make()->title('URL inválida')->body('Não encontrei um ID de planilha do Google Sheets nesse link.')->danger()->send();

                            return;
                        }

                        $sourceKeys = array_keys(array_filter(
                            config('punter.sources'),
                            fn (array $s): bool => $s['spreadsheet'] === $spreadsheetKey
                        ));
                        $rows = SheetSource::whereIn('source_key', $sourceKeys)->get();

                        $failures = [];
                        foreach ($rows as $row) {
                            $source = config('punter.sources.'.$row->source_key);
                            $expected = $source['expected_header'] ?? array_keys($source['columns'] ?? []);

                            try {
                                $fetched = app(SheetFetcher::class)->fetch($newId, $row->sheet_name, $row->range);
                            } catch (RuntimeException $e) {
                                $failures[] = "{$row->label}: não consegui baixar ({$e->getMessage()})";

                                continue;
                            }

                            $header = fgetcsv(fopen($fetched['path'], 'rb'), escape: '') ?: [];
                            @unlink($fetched['path']);
                            $actual = array_map('strval', array_slice($header, 0, count($expected)));

                            if ($actual !== $expected) {
                                $failures[] = "{$row->label}: cabeçalho da aba \"{$row->sheet_name}\" não bate — confira se essa nova planilha tem as mesmas abas.";
                            }
                        }

                        if ($failures !== []) {
                            Notification::make()
                                ->title('Nada foi alterado — algumas abas não validaram')
                                ->body(implode("\n", $failures))
                                ->danger()
                                ->persistent()
                                ->send();

                            return;
                        }

                        SheetSource::whereIn('source_key', $sourceKeys)->update([
                            'spreadsheet_id' => $newId,
                            'spreadsheet_url' => $data['new_url'],
                        ]);

                        Notification::make()
                            ->title('Planilha atualizada')
                            ->body(count($rows).' fonte(s) revalidada(s) e apontada(s) para a nova URL.')
                            ->success()
                            ->send();
                    }),
            ])
            ->recordActions([
                Action::make('editSource')
                    ->label('Editar')
                    ->icon('heroicon-o-pencil-square')
                    ->schema(fn (SheetSource $record): array => [
                        TextInput::make('label')->label('Rótulo')->default($record->label)->required()->maxLength(150),
                        TextInput::make('spreadsheet_url')->label('URL da planilha')->default($record->spreadsheet_url)->required()->url(),
                        TextInput::make('sheet_name')->label('Nome da aba')->default($record->sheet_name)->required()->maxLength(150),
                        TextInput::make('range')->label('Intervalo (opcional)')->default($record->range),
                    ])
                    ->action(function (SheetSource $record, array $data): void {
                        $spreadsheetId = self::extractSpreadsheetId($data['spreadsheet_url']);
                        if ($spreadsheetId === null) {
                            Notification::make()->title('URL inválida')->body('Não encontrei um ID de planilha do Google Sheets nesse link.')->danger()->send();

                            return;
                        }

                        // O Google Sheets não retorna erro quando a aba pedida não existe — ele
                        // silenciosamente devolve a PRIMEIRA aba da planilha. Baixar não basta:
                        // é preciso comparar o cabeçalho com o que config/punter.php espera para
                        // essa fonte, senão um nome de aba errado gravaria dados desalinhados.
                        $source = config('punter.sources.'.$record->source_key);
                        $expected = $source['expected_header'] ?? array_keys($source['columns'] ?? []);

                        try {
                            $fetched = app(SheetFetcher::class)->fetch($spreadsheetId, $data['sheet_name'], $data['range'] ?: null);
                        } catch (RuntimeException $e) {
                            Notification::make()->title('Não foi possível baixar essa aba')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        $header = fgetcsv(fopen($fetched['path'], 'rb'), escape: '') ?: [];
                        @unlink($fetched['path']);
                        $actual = array_map('strval', array_slice($header, 0, count($expected)));

                        if ($actual !== $expected) {
                            Notification::make()
                                ->title('Cabeçalho não bate com o esperado')
                                ->body("Essa aba não tem o formato de \"{$record->label}\" — confira o nome da aba (o Google Sheets cai na primeira aba quando o nome não existe).")
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->update([
                            'label' => $data['label'],
                            'spreadsheet_id' => $spreadsheetId,
                            'spreadsheet_url' => $data['spreadsheet_url'],
                            'sheet_name' => $data['sheet_name'],
                            'range' => $data['range'] ?: null,
                        ]);

                        Notification::make()->title('Fonte atualizada')->success()->send();
                    }),
                Action::make('syncNow')
                    ->label('Sincronizar agora')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function (SheetSource $record): void {
                        try {
                            $result = app(SheetImporter::class)->run($record->source_key, force: true);
                            $body = $result['skipped_unchanged']
                                ? 'Conteúdo igual ao último sync — nada a gravar.'
                                : $result['rows_inserted'].' linhas gravadas.';
                            Notification::make()->title('Sincronizado')->body($body)->success()->send();
                        } catch (Throwable $e) {
                            Notification::make()->title('Falha na sincronização')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }

    public static function extractSpreadsheetId(string $url): ?string
    {
        return preg_match('/\/spreadsheets\/d\/([A-Za-z0-9_-]+)/', $url, $m) ? $m[1] : null;
    }

    /**
     * Uma planilha do Google Sheets alimenta várias fontes (ex.: "Base de Dados nova
     * 3.1" tem 7 abas mapeadas). Agrupa por config('punter.spreadsheets') para o select
     * do header action, mostrando quantas fontes cada uma tem e a URL em uso hoje.
     *
     * @return array<string, string>
     */
    private static function spreadsheetOptions(): array
    {
        $sources = config('punter.sources');
        $options = [];

        foreach (config('punter.spreadsheets') as $key => $spreadsheet) {
            $sourceKeys = array_keys(array_filter($sources, fn (array $s): bool => $s['spreadsheet'] === $key));
            $count = count($sourceKeys);
            $currentUrl = SheetSource::whereIn('source_key', $sourceKeys)->value('spreadsheet_url') ?? $spreadsheet['id'];

            $options[$key] = sprintf(
                '%s (%d %s) — %s',
                $spreadsheet['label'],
                $count,
                $count === 1 ? 'fonte' : 'fontes',
                $currentUrl,
            );
        }

        return $options;
    }

    /** @return array<string, class-string> */
    public static function getPages(): array
    {
        return ['index' => ListSheetSources::route('/')];
    }
}
