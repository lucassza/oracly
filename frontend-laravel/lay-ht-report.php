<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Oracly\Services\AgainstOneGoalStrategy;
use App\Oracly\Services\FavoritesService;
use App\Oracly\Services\PredictionService;

$strategy = app(AgainstOneGoalStrategy::class);
$leagues = app(FavoritesService::class)->get()['leagues'];
$rows = app(PredictionService::class)->history('over_15_ft', 0, 20000);

$buckets = [];
foreach ($rows as $row) {
    $choice = $strategy->choice($row);
    if ($choice === null || (float) ($row['probability'] ?? 0) < 75) {
        continue;
    }
    $favorite = in_array(($row['country'] ?? '').'::'.($row['competition'] ?? ''), $leagues, true);
    foreach ([['todas', true], ['favoritas', $favorite]] as [$scope, $include]) {
        if (! $include) {
            continue;
        }
        $ht = ($row['halftimeHomeScore'] === null || $row['halftimeAwayScore'] === null)
            ? null
            : (int) $row['halftimeHomeScore'] + (int) $row['halftimeAwayScore'];
        $buckets[$scope][$choice['score']][] = [
            'ht' => $ht,
            'kickoffAt' => $row['kickoffAt'],
            'layHit' => sprintf('%d-%d', (int) $row['homeScore'], (int) $row['awayScore']) !== $choice['score'],
        ];
    }
}

foreach ($buckets as $scope => $byScore) {
    ksort($byScore);
    echo "===== Ligas {$scope} =====\n";
    $all = [];
    foreach ($byScore as $score => $entries) {
        $all = array_merge($all, $entries);
        report('LAY '.str_replace('-', 'x', $score), $entries);
    }
    report('LAY 0x1 + 1x0 (total)', $all);
    echo "\n";
}

function report(string $label, array $entries): void
{
    $total = count($entries);
    $withHt = array_values(array_filter($entries, fn ($e) => $e['ht'] !== null));
    $over = count(array_filter($withHt, fn ($e) => $e['ht'] >= 1));
    $lay = count(array_filter($entries, fn ($e) => $e['layHit']));
    $dates = array_map(fn ($e) => substr((string) $e['kickoffAt'], 0, 10), $entries);
    sort($dates);
    printf(
        "%-24s indicacoes=%-5d comHT=%-5d over0.5HT=%-5d (%s do que tem HT) semHT=%-4d layGreen=%s  periodo=%s..%s\n",
        $label,
        $total,
        count($withHt),
        $over,
        count($withHt) ? number_format($over / count($withHt) * 100, 1).'%' : '-',
        $total - count($withHt),
        $total ? number_format($lay / $total * 100, 1).'%' : '-',
        $dates[0] ?? '-',
        $dates[count($dates) - 1] ?? '-',
    );
}
