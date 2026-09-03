<?php

namespace App\Oracly\Punter;

use App\Oracly\Support\PunterDb;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Orquestra a sincronização de uma fonte declarada em config/punter.php:
 * baixa a aba (SheetFetcher), valida o cabeçalho, normaliza (ValueNormalizer)
 * e grava — full refresh para as tabelas "burras", merge dedicado para lay_signals
 * (via a classe declarada em `importer`). Sempre registra um punter.import_runs.
 */
final class SheetImporter
{
    /** Limite de binds do Postgres (65535) — controla o tamanho do lote de insert. */
    private const MAX_BIND_PARAMS = 60000;

    public function __construct(private readonly SheetFetcher $fetcher)
    {
    }

    /**
     * @return array{status: string, rows_read: int, rows_inserted: int, rows_updated: int, rows_skipped: int, bytes: int, sha256: string, skipped_unchanged: bool, error: ?string}
     */
    public function run(string $sourceKey, bool $force = false, bool $dryRun = false): array
    {
        $source = config("punter.sources.{$sourceKey}");
        if ($source === null) {
            throw new RuntimeException("Fonte Punter desconhecida: {$sourceKey}");
        }
        $spreadsheet = config("punter.spreadsheets.{$source['spreadsheet']}");

        $sheetSourceRow = PunterDb::connection()->table('sheet_sources')->where('source_key', $sourceKey)->first();
        if ($sheetSourceRow === null) {
            throw new RuntimeException("sheet_sources não tem registro para '{$sourceKey}'. Rode o seeder PunterSheetSourcesSeeder.");
        }

        $runId = $dryRun ? null : PunterDb::connection()->table('import_runs')->insertGetId([
            'sheet_source_id' => $sheetSourceRow->id,
            'started_at' => now(),
            'status' => 'running',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $fetched = null;

        try {
            $fetched = $this->fetcher->fetch($spreadsheet['id'], $source['sheet'], $source['range'] ?? null);

            if (! $force && $sheetSourceRow->last_status === 'success') {
                $lastRun = PunterDb::connection()->table('import_runs')
                    ->where('sheet_source_id', $sheetSourceRow->id)
                    ->where('status', 'success')
                    ->orderByDesc('id')
                    ->first();

                if ($lastRun !== null && $lastRun->content_sha256 === $fetched['sha256']) {
                    $result = [
                        'status' => 'success', 'rows_read' => 0, 'rows_inserted' => 0,
                        'rows_updated' => 0, 'rows_skipped' => 0, 'bytes' => $fetched['bytes'],
                        'sha256' => $fetched['sha256'], 'skipped_unchanged' => true, 'error' => null,
                    ];
                    $this->finish($sheetSourceRow, $runId, $dryRun, $result);

                    return $result;
                }
            }

            $stats = match ($source['mode']) {
                'refresh' => $this->importRefresh($source, $fetched['path'], $dryRun),
                'merge_custom' => $this->importMergeCustom($sourceKey, $source, $fetched['path'], $dryRun),
                default => throw new RuntimeException("Modo de importação desconhecido: {$source['mode']}"),
            };

            $result = [
                'status' => 'success',
                'rows_read' => $stats['rows_read'],
                'rows_inserted' => $stats['rows_inserted'],
                'rows_updated' => $stats['rows_updated'],
                'rows_skipped' => $stats['rows_skipped'],
                'bytes' => $fetched['bytes'],
                'sha256' => $fetched['sha256'],
                'skipped_unchanged' => false,
                'error' => null,
            ];
            $this->finish($sheetSourceRow, $runId, $dryRun, $result);

            return $result;
        } catch (Throwable $e) {
            $result = [
                'status' => 'error', 'rows_read' => 0, 'rows_inserted' => 0, 'rows_updated' => 0,
                'rows_skipped' => 0, 'bytes' => $fetched['bytes'] ?? 0, 'sha256' => $fetched['sha256'] ?? null,
                'skipped_unchanged' => false, 'error' => $e->getMessage(),
            ];
            $this->finish($sheetSourceRow, $runId, $dryRun, $result);

            throw $e;
        } finally {
            if ($fetched !== null && is_file($fetched['path'])) {
                @unlink($fetched['path']);
            }
        }
    }

    private function finish($sheetSourceRow, ?int $runId, bool $dryRun, array $result): void
    {
        if ($dryRun) {
            return;
        }

        PunterDb::connection()->table('import_runs')->where('id', $runId)->update([
            'finished_at' => now(),
            'status' => $result['status'],
            'rows_read' => $result['rows_read'],
            'rows_inserted' => $result['rows_inserted'],
            'rows_updated' => $result['rows_updated'],
            'rows_skipped' => $result['rows_skipped'],
            'bytes_downloaded' => $result['bytes'],
            'content_sha256' => $result['sha256'],
            'error' => $result['error'],
            'updated_at' => now(),
        ]);

        PunterDb::connection()->table('sheet_sources')->where('id', $sheetSourceRow->id)->update([
            'last_sync_at' => now(),
            'last_row_count' => $result['rows_read'],
            'last_status' => $result['status'],
            'last_error' => $result['error'],
            'updated_at' => now(),
        ]);
    }

    /**
     * Lê e grava em streaming, por blocos — nunca guarda as ~44 mil linhas x 164
     * colunas do match_history inteiras em memória, só o bloco corrente.
     *
     * @return array{rows_read: int, rows_inserted: int, rows_updated: int, rows_skipped: int}
     */
    private function importRefresh(array $source, string $path, bool $dryRun): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Não foi possível abrir {$path}");
        }

        $header = fgetcsv($handle, escape: '');
        $specs = array_values($source['columns']);
        $columnCount = count($specs);
        $expected = array_keys($source['columns']);
        $actual = array_map('strval', array_slice($header ?: [], 0, $columnCount));
        $this->assertHeader($expected, $actual, $source['label']);

        $extra = $source['extra'] ?? [];
        $bindsPerRow = $columnCount + count($extra) + 3 + (isset($source['natural_key']) ? 1 : 0); // + row_seq, created_at, updated_at, match_key
        $chunkSize = max(1, intdiv(self::MAX_BIND_PARAMS, $bindsPerRow));

        $connection = PunterDb::connection();
        $table = $source['target'];
        $naturalKey = $source['natural_key'] ?? null;
        $rowsRead = 0;
        $rowsInserted = 0;

        $connection->transaction(function () use ($connection, $table, $extra, $handle, $specs, $columnCount, $chunkSize, $naturalKey, $dryRun, &$rowsRead, &$rowsInserted): void {
            if (! $dryRun) {
                $query = $connection->table($table);
                if ($extra !== []) {
                    foreach ($extra as $col => $val) {
                        $query->where($col, $val);
                    }
                    $query->delete();
                } else {
                    $connection->statement('TRUNCATE TABLE "punter"."'.$table.'"');
                }
            }

            $buffer = [];
            $rowSeq = 0;
            $now = now();

            while (($line = fgetcsv($handle, escape: '')) !== false) {
                if ($line === [null]) {
                    continue;
                }
                $rowsRead++;
                $rowSeq++;

                if ($dryRun) {
                    continue;
                }

                $record = $extra;
                for ($i = 0; $i < $columnCount; $i++) {
                    [$dbColumn, $type] = $specs[$i];
                    $record[$dbColumn] = ValueNormalizer::normalize($type, $line[$i] ?? null);
                }
                if ($naturalKey !== null) {
                    $record['match_key'] = mb_strtolower(trim(implode('|', array_map(
                        static fn (string $col): string => (string) ($record[$col] ?? ''),
                        $naturalKey
                    ))));
                }
                $record['row_seq'] = $rowSeq;
                $record['created_at'] = $now;
                $record['updated_at'] = $now;
                $buffer[] = $record;

                if (count($buffer) >= $chunkSize) {
                    $connection->table($table)->insert($buffer);
                    $rowsInserted += count($buffer);
                    $buffer = [];
                }
            }

            if ($buffer !== []) {
                $connection->table($table)->insert($buffer);
                $rowsInserted += count($buffer);
            }
        });

        fclose($handle);

        return ['rows_read' => $rowsRead, 'rows_inserted' => $rowsInserted, 'rows_updated' => 0, 'rows_skipped' => 0];
    }

    /**
     * @return array{rows_read: int, rows_inserted: int, rows_updated: int, rows_skipped: int}
     */
    private function importMergeCustom(string $sourceKey, array $source, string $path, bool $dryRun): array
    {
        /** @var LaySignalsImporter $importer */
        $importer = app($source['importer']);

        return $importer->import($sourceKey, $source, $path, $dryRun);
    }

    private function assertHeader(array $expected, array $actual, string $label): void
    {
        if ($expected !== $actual) {
            $diff = array_diff_assoc($expected, $actual);
            throw new RuntimeException(
                "Cabeçalho da aba \"{$label}\" mudou — a importação foi abortada para não gravar dados desalinhados. ".
                'Esperado: ['.implode(', ', $expected).']. Recebido: ['.implode(', ', $actual).']. '.
                'Divergências: ['.implode(', ', array_keys($diff)).'].'
            );
        }
    }
}
