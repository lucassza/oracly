<?php

namespace App\Oracly\Repositories;

use App\Oracly\Support\LeagueFilter;
use App\Oracly\Support\OraclyDb;

final class MatchSnapshotRepository
{
    /**
     * Latest snapshot per fixture (optionally filtered in SQL afterwards by caller).
     *
     * @return list<array<string, mixed>>
     */
    public function latest(): array
    {
        $table = OraclyDb::table('match_snapshots');
        $rows = OraclyDb::connection()->select("
            WITH latest AS (
                SELECT provider_match_id, MAX(collected_at) AS collected_at
                FROM {$table}
                GROUP BY provider_match_id
            )
            SELECT ms.match_json
            FROM {$table} ms
            INNER JOIN latest USING (provider_match_id, collected_at)
        ");

        return $this->decodeRows($rows, mainLeagueOnly: true);
    }

    /**
     * Latest snapshots whose kickoff falls on a Brasília calendar day.
     *
     * @return list<array<string, mixed>>
     */
    public function latestForBrasiliaDate(string $date): array
    {
        $table = OraclyDb::table('match_snapshots');
        $startUtc = $date.'T03:00:00.000Z';
        $endUtc = date('Y-m-d', strtotime($date.' +1 day')).'T03:00:00.000Z';

        $rows = OraclyDb::connection()->select("
            WITH latest AS (
                SELECT provider_match_id, MAX(collected_at) AS collected_at
                FROM {$table}
                GROUP BY provider_match_id
            )
            SELECT ms.match_json, ms.provider_match_id
            FROM {$table} ms
            INNER JOIN latest USING (provider_match_id, collected_at)
            WHERE ms.kickoff_at IS NOT NULL
              AND ms.kickoff_at >= ?
              AND ms.kickoff_at < ?
        ", [$startUtc, $endUtc]);

        return $this->decodeRows($rows, mainLeagueOnly: true);
    }

    /**
     * All snapshots for fixtures that kick off on a Brasília day (for daily picks pre-kickoff probs).
     *
     * @return list<array<string, mixed>>
     */
    public function allForBrasiliaDate(string $date): array
    {
        $table = OraclyDb::table('match_snapshots');
        // Brasília day D = UTC [D 03:00, D+1 03:00) with fixed UTC-3.
        $startUtc = $date.'T03:00:00.000Z';
        $endUtc = date('Y-m-d', strtotime($date.' +1 day')).'T03:00:00.000Z';

        $ids = OraclyDb::connection()->select("
            WITH latest AS (
                SELECT provider_match_id, MAX(collected_at) AS collected_at
                FROM {$table}
                GROUP BY provider_match_id
            )
            SELECT ms.provider_match_id
            FROM {$table} ms
            INNER JOIN latest USING (provider_match_id, collected_at)
            WHERE ms.kickoff_at IS NOT NULL
              AND ms.kickoff_at >= ?
              AND ms.kickoff_at < ?
        ", [$startUtc, $endUtc]);

        $providerIds = array_values(array_unique(array_map(fn ($r) => (string) $r->provider_match_id, $ids)));
        if ($providerIds === []) {
            return [];
        }

        return $this->allForProviderIds($providerIds);
    }

    /**
     * @param  list<string>  $providerIds
     * @return list<array<string, mixed>>
     */
    public function allForProviderIds(array $providerIds): array
    {
        if ($providerIds === []) {
            return [];
        }

        $table = OraclyDb::table('match_snapshots');
        $placeholders = implode(',', array_fill(0, count($providerIds), '?'));
        $rows = OraclyDb::connection()->select("
            SELECT match_json
            FROM {$table}
            WHERE provider_match_id IN ({$placeholders})
            ORDER BY collected_at ASC
        ", $providerIds);

        return $this->decodeRows($rows, mainLeagueOnly: true);
    }

    /**
     * Latest not_started fixtures with kickoff after $nowIso.
     *
     * @return list<array<string, mixed>>
     */
    public function latestUpcoming(string $nowIso): array
    {
        $table = OraclyDb::table('match_snapshots');
        $rows = OraclyDb::connection()->select("
            WITH latest AS (
                SELECT provider_match_id, MAX(collected_at) AS collected_at
                FROM {$table}
                GROUP BY provider_match_id
            )
            SELECT ms.match_json
            FROM {$table} ms
            INNER JOIN latest USING (provider_match_id, collected_at)
            WHERE ms.status = 'not_started'
              AND ms.kickoff_at IS NOT NULL
              AND ms.kickoff_at > ?
        ", [$nowIso]);

        return $this->decodeRows($rows, mainLeagueOnly: true);
    }

    /**
     * Finished fixtures (latest snapshot finished) — capped for history screens.
     *
     * @return list<array<string, mixed>>
     */
    public function finishedProviderIds(int $limit = 2000): array
    {
        $table = OraclyDb::table('match_snapshots');
        $rows = OraclyDb::connection()->select("
            WITH latest AS (
                SELECT provider_match_id, MAX(collected_at) AS collected_at
                FROM {$table}
                GROUP BY provider_match_id
            )
            SELECT ms.provider_match_id
            FROM {$table} ms
            INNER JOIN latest USING (provider_match_id, collected_at)
            WHERE ms.status = 'finished'
            ORDER BY ms.kickoff_at DESC NULLS LAST
            LIMIT ?
        ", [$limit]);

        return array_map(fn ($r) => (string) $r->provider_match_id, $rows);
    }

    /**
     * @deprecated Prefer scoped loaders — full table is too large for PHP memory.
     *
     * @return list<array<string, mixed>>
     */
    public function allMainLeague(): array
    {
        return $this->latest();
    }

    /**
     * @param  list<object>  $rows
     * @return list<array<string, mixed>>
     */
    private function decodeRows(array $rows, bool $mainLeagueOnly): array
    {
        $matches = [];
        foreach ($rows as $row) {
            $json = $row->match_json ?? null;
            if (is_string($json)) {
                $decoded = json_decode($json, true);
            } elseif (is_array($json)) {
                $decoded = $json;
            } else {
                continue;
            }
            if (! is_array($decoded)) {
                continue;
            }
            if ($mainLeagueOnly && ! LeagueFilter::isMainLeague($decoded['competition'] ?? null)) {
                continue;
            }
            $matches[] = $decoded;
        }

        return $matches;
    }
}
