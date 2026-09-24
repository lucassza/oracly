<?php

namespace Database\Seeders;

use App\Oracly\Support\PunterDb;
use Illuminate\Database\Seeder;

class PunterSheetSourcesSeeder extends Seeder
{
    public function run(): void
    {
        $spreadsheets = config('punter.spreadsheets');
        $sources = config('punter.sources');
        $table = PunterDb::connection()->table('sheet_sources');

        foreach ($sources as $key => $source) {
            $spreadsheet = $spreadsheets[$source['spreadsheet']];

            // Destino/formato sempre seguem o config; planilha, aba, intervalo, rótulo e
            // ativa são editáveis no admin e só são semeados na criação — re-rodar o
            // seeder (punter:install) não pode desfazer um link trocado pelo admin.
            $structural = [
                'target' => $source['target'],
                'extra' => isset($source['extra']) ? json_encode($source['extra']) : null,
                'updated_at' => now(),
            ];

            $existing = (clone $table)->where('source_key', $key)->first();
            if ($existing) {
                (clone $table)->where('source_key', $key)->update($structural);
            } else {
                (clone $table)->insert($structural + [
                    'source_key' => $key,
                    'label' => $source['label'],
                    'spreadsheet_id' => $spreadsheet['id'],
                    'spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/'.$spreadsheet['id'].'/edit',
                    'sheet_name' => $source['sheet'],
                    'range' => $source['range'] ?? null,
                    'is_active' => true,
                    'created_at' => now(),
                ]);
            }
        }

        $this->command?->info(count($sources).' fontes Punter semeadas em punter.sheet_sources.');
    }
}
