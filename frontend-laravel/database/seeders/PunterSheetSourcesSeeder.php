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

            $attributes = [
                'label' => $source['label'],
                'spreadsheet_id' => $spreadsheet['id'],
                'spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/'.$spreadsheet['id'].'/edit',
                'sheet_name' => $source['sheet'],
                'range' => $source['range'] ?? null,
                'target' => $source['target'],
                'extra' => isset($source['extra']) ? json_encode($source['extra']) : null,
                'is_active' => true,
                'updated_at' => now(),
            ];

            $existing = (clone $table)->where('source_key', $key)->first();
            if ($existing) {
                (clone $table)->where('source_key', $key)->update($attributes);
            } else {
                (clone $table)->insert($attributes + ['source_key' => $key, 'created_at' => now()]);
            }
        }

        $this->command?->info(count($sources).' fontes Punter semeadas em punter.sheet_sources.');
    }
}
