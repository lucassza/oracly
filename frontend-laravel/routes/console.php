<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sincronização das planilhas Punter (Google Sheets) — ver config/punter.php e app/Console/Commands/PunterSync.php.
// Requer que algo dispare `php artisan schedule:run` a cada minuto (cron do host ou `schedule:work`);
// este container roda só `php artisan serve`, sem esse driver — ver README/CLAUDE.md antes de contar com isto em produção.
Schedule::command('punter:sync --source=lay_signals_atuais')->everyThreeHours()->withoutOverlapping();
Schedule::command('punter:sync --source=panel_fixtures')->everyThreeHours()->withoutOverlapping();
Schedule::command('punter:sync --source=lay_signals_historico')->dailyAt('05:00')->timezone('America/Sao_Paulo')->withoutOverlapping();
Schedule::command('punter:sync --source=match_history')->dailyAt('05:10')->timezone('America/Sao_Paulo')->withoutOverlapping();
Schedule::command('punter:sync --source=column_dictionary')->dailyAt('05:15')->timezone('America/Sao_Paulo')->withoutOverlapping();
Schedule::command('punter:sync --source=bot_stats_until_apr2026')->dailyAt('05:20')->timezone('America/Sao_Paulo')->withoutOverlapping();
Schedule::command('punter:sync --source=bot_stats_after_apr2026')->dailyAt('05:22')->timezone('America/Sao_Paulo')->withoutOverlapping();
Schedule::command('punter:sync --source=bot_entries_until_apr2026')->dailyAt('05:24')->timezone('America/Sao_Paulo')->withoutOverlapping();
Schedule::command('punter:sync --source=bot_entries_after_apr2026')->dailyAt('05:28')->timezone('America/Sao_Paulo')->withoutOverlapping();
Schedule::command('punter:sync --source=league_summary')->dailyAt('05:30')->timezone('America/Sao_Paulo')->withoutOverlapping();
