<?php

namespace App\Oracly\Punter;

use App\Oracly\Support\PunterDb;
use RuntimeException;

/**
 * Importador dedicado de punter.lay_signals — recebe linhas de DUAS abas diferentes
 * (o histórico apurado e as listas atuais, ainda pendentes) e faz merge por
 * (match_key, radar) sem nunca perder informação já gravada: um valor novo só
 * sobrescreve quando não é nulo (COALESCE), `settled` só vira true e nunca volta a
 * false, e `first_seen_at` guarda sempre o sync mais antigo que viu a linha.
 */
final class LaySignalsImporter
{
    private const MAX_BATCH = 300;

    private const PENDING_MARKERS = ['jogo ainda não ocorreu', 'jogo ainda nao ocorreu'];

    private const RADAR_MAP = [
        'Tendência de Lay 2x2' => 'lay_2x2',
        'Tendência de Lay 0x1' => 'lay_0x1',
    ];

    private const UPDATABLE_COLUMNS = [
        'kickoff_at', 'kickoff_raw', 'country', 'league', 'home_team', 'away_team',
        'odd_home', 'odd_draw', 'odd_away', 'flashscore_url',
        'ft_home', 'ft_away', 'ht_home', 'ht_away', 'ft_raw', 'ht_raw',
        'check_result', 'diagnosis',
        'flag_red_0x1_ht', 'flag_red_0x0_ht', 'flag_green_tranquilo_0x1',
        'flag_green_0x0_ht_2x2', 'flag_btts_ht_2x2', 'flag_leitura',
    ];

    /**
     * @return array{rows_read: int, rows_inserted: int, rows_updated: int, rows_skipped: int}
     */
    public function import(string $sourceKey, array $source, string $path, bool $dryRun): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Não foi possível abrir {$path}");
        }

        $header = fgetcsv($handle, escape: '');
        $expected = $source['expected_header'];
        $actual = array_map('strval', array_slice($header ?: [], 0, count($expected)));
        if ($actual !== $expected) {
            fclose($handle);
            throw new RuntimeException(
                "Cabeçalho da aba \"{$source['label']}\" mudou — a importação foi abortada. ".
                'Esperado: ['.implode(', ', $expected).']. Recebido: ['.implode(', ', $actual).'].'
            );
        }

        $rows = [];
        $rowsRead = 0;
        while (($line = fgetcsv($handle, escape: '')) !== false) {
            if ($line === [null]) {
                continue;
            }
            $rowsRead++;
            $row = $sourceKey === 'lay_signals_historico'
                ? $this->fromHistorico($line)
                : $this->fromAtuais($line);
            if ($row !== null) {
                $rows[$row['match_key'].'|'.$row['radar']] = $row;
            }
        }
        fclose($handle);

        $skipped = $rowsRead - count($rows);

        if ($dryRun || $rows === []) {
            return ['rows_read' => $rowsRead, 'rows_inserted' => 0, 'rows_updated' => 0, 'rows_skipped' => $skipped];
        }

        $now = now();
        foreach ($rows as &$row) {
            $row['first_seen_at'] = $now;
            $row['last_synced_at'] = $now;
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }
        unset($row);

        $written = 0;
        foreach (array_chunk(array_values($rows), self::MAX_BATCH) as $chunk) {
            $this->upsertChunk($chunk);
            $written += count($chunk);
        }

        return ['rows_read' => $rowsRead, 'rows_inserted' => $written, 'rows_updated' => 0, 'rows_skipped' => $skipped];
    }

    /** @param  list<string|null>  $f */
    private function fromHistorico(array $f): ?array
    {
        $matchKey = ValueNormalizer::text($f[12] ?? null);
        $radar = self::RADAR_MAP[trim((string) ($f[8] ?? ''))] ?? null;
        if ($matchKey === null || $radar === null) {
            return null;
        }

        $ft = ValueNormalizer::score($f[9] ?? null);
        $ht = ValueNormalizer::score($f[11] ?? null);
        $checkRaw = trim((string) ($f[10] ?? ''));
        $diagnosisRaw = $radar === 'lay_2x2' ? ($f[20] ?? null) : ($f[19] ?? null);
        $diagnosis = ValueNormalizer::text($diagnosisRaw);
        if ($diagnosis === '0') {
            $diagnosis = null;
        }

        return [
            'match_key' => $matchKey,
            'radar' => $radar,
            'kickoff_at' => ValueNormalizer::datetimeBrToUtc($f[0] ?? null),
            'kickoff_raw' => ValueNormalizer::text($f[0] ?? null),
            'country' => ValueNormalizer::text($f[1] ?? null),
            'league' => ValueNormalizer::text($f[2] ?? null),
            'home_team' => ValueNormalizer::text($f[3] ?? null),
            'away_team' => ValueNormalizer::text($f[4] ?? null),
            'odd_home' => ValueNormalizer::numeric($f[5] ?? null),
            'odd_draw' => ValueNormalizer::numeric($f[6] ?? null),
            'odd_away' => ValueNormalizer::numeric($f[7] ?? null),
            'flashscore_url' => null,
            'ft_home' => $ft['home'] ?? null,
            'ft_away' => $ft['away'] ?? null,
            'ht_home' => $ht['home'] ?? null,
            'ht_away' => $ht['away'] ?? null,
            'ft_raw' => ValueNormalizer::text($f[9] ?? null),
            'ht_raw' => ValueNormalizer::text($f[11] ?? null),
            'settled' => ! in_array(mb_strtolower(trim((string) ($f[9] ?? ''))), self::PENDING_MARKERS, true)
                && trim((string) ($f[9] ?? '')) !== '',
            'check_result' => $checkRaw === '✔' ? 'green' : ($checkRaw === 'X' ? 'red' : null),
            'diagnosis' => $diagnosis,
            'flag_red_0x1_ht' => ValueNormalizer::bool01($f[13] ?? null),
            'flag_red_0x0_ht' => ValueNormalizer::bool01($f[14] ?? null),
            'flag_green_tranquilo_0x1' => ValueNormalizer::bool01($f[15] ?? null),
            'flag_green_0x0_ht_2x2' => ValueNormalizer::bool01($f[16] ?? null),
            'flag_btts_ht_2x2' => ValueNormalizer::bool01($f[17] ?? null),
            'flag_leitura' => ValueNormalizer::bool01($f[18] ?? null),
        ];
    }

    /** @param  list<string|null>  $f */
    private function fromAtuais(array $f): ?array
    {
        $matchKey = ValueNormalizer::text($f[10] ?? null);
        $radar = self::RADAR_MAP[trim((string) ($f[9] ?? ''))] ?? null;
        if ($matchKey === null || $radar === null) {
            return null;
        }

        return [
            'match_key' => $matchKey,
            'radar' => $radar,
            'kickoff_at' => ValueNormalizer::datetimeBrToUtc($f[0] ?? null),
            'kickoff_raw' => ValueNormalizer::text($f[0] ?? null),
            'country' => ValueNormalizer::text($f[1] ?? null),
            'league' => ValueNormalizer::text($f[2] ?? null),
            'home_team' => ValueNormalizer::text($f[3] ?? null),
            'away_team' => ValueNormalizer::text($f[4] ?? null),
            'odd_home' => ValueNormalizer::numeric($f[5] ?? null),
            'odd_draw' => ValueNormalizer::numeric($f[6] ?? null),
            'odd_away' => ValueNormalizer::numeric($f[7] ?? null),
            'flashscore_url' => ValueNormalizer::text($f[8] ?? null),
            'ft_home' => null,
            'ft_away' => null,
            'ht_home' => null,
            'ht_away' => null,
            'ft_raw' => null,
            'ht_raw' => null,
            'settled' => false,
            'check_result' => null,
            'diagnosis' => null,
            'flag_red_0x1_ht' => null,
            'flag_red_0x0_ht' => null,
            'flag_green_tranquilo_0x1' => null,
            'flag_green_0x0_ht_2x2' => null,
            'flag_btts_ht_2x2' => null,
            'flag_leitura' => null,
        ];
    }

    /** @param  list<array<string, mixed>>  $chunk */
    private function upsertChunk(array $chunk): void
    {
        $columns = array_keys($chunk[0]);
        $placeholders = [];
        $bindings = [];
        foreach ($chunk as $row) {
            $placeholders[] = '('.implode(', ', array_fill(0, count($columns), '?')).')';
            foreach ($columns as $col) {
                $value = $row[$col];
                $bindings[] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:sP') : $value;
            }
        }

        $updateSet = [];
        foreach (self::UPDATABLE_COLUMNS as $col) {
            $updateSet[] = "\"{$col}\" = COALESCE(EXCLUDED.\"{$col}\", \"lay_signals\".\"{$col}\")";
        }
        $updateSet[] = '"settled" = "lay_signals"."settled" OR EXCLUDED."settled"';
        $updateSet[] = '"first_seen_at" = LEAST("lay_signals"."first_seen_at", EXCLUDED."first_seen_at")';
        $updateSet[] = '"last_synced_at" = EXCLUDED."last_synced_at"';
        $updateSet[] = '"updated_at" = EXCLUDED."updated_at"';

        $quotedColumns = implode(', ', array_map(fn (string $c): string => "\"{$c}\"", $columns));

        $sql = 'INSERT INTO "punter"."lay_signals" ('.$quotedColumns.') VALUES '.implode(', ', $placeholders).
            ' ON CONFLICT ("match_key", "radar") DO UPDATE SET '.implode(', ', $updateSet);

        PunterDb::connection()->insert($sql, $bindings);
    }
}
