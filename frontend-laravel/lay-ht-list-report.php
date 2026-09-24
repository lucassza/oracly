<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Oracly\Services\DailyLayListService;

$date = $argv[1] ?? '2026-07-24';
$end = $argv[2] ?? '2026-09-01';

$byScore = [];
while ($date <= $end) {
    foreach (app(DailyLayListService::class)->forDate($date)['entries'] as $entry) {
        if (($entry['strategy']['label'] ?? '') !== 'LAY 0x1/1x0' || ($entry['status'] ?? '') !== 'finished') {
            continue;
        }
        $score = str_replace('x', '-', substr((string) $entry['strategy']['bet'], 4));
        $ht = ($entry['halftimeHomeScore'] === null || $entry['halftimeAwayScore'] === null)
            ? null
            : (int) $entry['halftimeHomeScore'] + (int) $entry['halftimeAwayScore'];
        $byScore[$score][$entry['providerMatchId']] = [
            'ht' => $ht,
            'layHit' => sprintf('%d-%d', (int) $entry['homeScore'], (int) $entry['awayScore']) !== $score,
        ];
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
