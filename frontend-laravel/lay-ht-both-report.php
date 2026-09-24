<?php

// Reimplementa a seleção da Lista LAY para a estratégia "Contra 0x1/1x0" SEM o corte que hoje
// descarta o sub-placar 1x0 (DailyDecision.php:134), para medir os dois lados. Não altera o app.

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Oracly\Services\AgainstOneGoalStrategy;
use App\Oracly\Services\DailyPickService;
use App\Oracly\Services\FavoritesService;
use Carbon\Carbon;

$date = $argv[1] ?? '2026-07-24';
$end = $argv[2] ?? '2026-09-01';

$strategy = app(AgainstOneGoalStrategy::class);
$leagues = app(FavoritesService::class)->get()['leagues'];
$byScore = [];

while ($date <= $end) {
    $actions = [];
    foreach (app(DailyPickService::class)->forDate($date) as $row) {
        if (! in_array(($row['country'] ?? '').'::'.($row['competition'] ?? ''), $leagues, true)) {
            continue;
        }
        $id = (string) ($row['providerMatchId'] ?? '');
        $choice = $strategy->choice($row);
        if ($id === '' || $choice === null || (float) ($row['over15'] ?? 0) < 75) {
            continue;
        }
        $hour = Carbon::parse($row['kickoffAt'])->timezone('America/Sao_Paulo')->format('Y-m-d H');
        $actions[$hour][] = ['row' => $row, 'score' => $choice['score'], 'p' => $choice['probability']];
    }

    foreach ($actions as $group) {
        usort($group, fn ($a, $b) => $a['p'] <=> $b['p']);
        foreach (array_slice($group, 0, 3) as $action) {
            $row = $action['row'];
            if (($row['status'] ?? '') !== 'finished') {
                continue;
            }
            $ht = ($row['halftimeHomeScore'] === null || $row['halftimeAwayScore'] === null)
                ? null
                : (int) $row['halftimeHomeScore'] + (int) $row['halftimeAwayScore'];
            $byScore[$action['score']][$row['providerMatchId']] = [
                'ht' => $ht,
                'layHit' => sprintf('%d-%d', (int) $row['homeScore'], (int) $row['awayScore']) !== $action['score'],
            ];
        }
    }
    fwrite(STDERR, "$date ok\n");
    $date = date('Y-m-d', strtotime($date.' +1 day'));
}

ksort($byScore);
$all = [];
foreach ($byScore as $score => $entries) {
    $all = array_merge($all, array_values($entries));
    report('LAY '.str_replace('-', 'x', $score), array_values($entries));
}
report('LAY 0x1 + 1x0 (total)', $all);

function report(string $label, array $entries): void
{
    $withHt = array_values(array_filter($entries, fn ($e) => $e['ht'] !== null));
    $over = count(array_filter($withHt, fn ($e) => $e['ht'] >= 1));
    $lay = count(array_filter($entries, fn ($e) => $e['layHit']));
    printf(
        "%-24s indicacoes=%-5d comHT=%-5d over0.5HT=%-5d (%s) semHT=%-4d layGreen=%s\n",
        $label,
        count($entries),
        count($withHt),
        $over,
        count($withHt) ? number_format($over / count($withHt) * 100, 1).'%' : '-',
        count($entries) - count($withHt),
        count($entries) ? number_format($lay / count($entries) * 100, 1).'%' : '-',
    );
}
