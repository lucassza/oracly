<?php

namespace App\Console\Commands;

use App\Oracly\Services\AgainstOneGoalAiService;
use App\Oracly\Services\PredictionService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Throwable;

class AnalyzeAgainstOneGoalWithAi extends Command
{
    protected $signature = 'oracly:analyze-against-one-goal
        {--limit=25 : Quantidade máxima de jogos históricos elegíveis}
        {--threshold=75 : Corte mínimo de probabilidade O1.5 FT}
        {--force : Reconsulta partidas já analisadas}
        {--dry-run : Mostra a amostra, sem chamar o OpenRouter}';

    protected $description = 'Executa o experimento IA para a estratégia contra 0x1 ou 1x0 e mede o resultado histórico';

    public function handle(PredictionService $predictions, AgainstOneGoalAiService $ai): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $threshold = (float) $this->option('threshold');
        $candidates = [];

        foreach ($predictions->history('over_15_ft', 0) as $row) {
            if ((float) ($row['probability'] ?? 0) < $threshold || $ai->baselineMethodology($row) === null) {
                continue;
            }
            $candidates[] = $row;
            if (count($candidates) >= $limit) {
                break;
            }
        }

        if ($candidates === []) {
            $this->warn('Nenhuma partida histórica elegível para os filtros informados.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->table(['Partida', 'Data', 'Baseline'], array_map(fn (array $row): array => [
                ($row['homeTeam'] ?? '—').' x '.($row['awayTeam'] ?? '—'),
                $row['kickoffAt'] ?? '—',
                $ai->baselineMethodology($row),
            ], $candidates));
            $this->info(count($candidates).' partidas seriam analisadas. Nenhuma chamada foi realizada.');

            return self::SUCCESS;
        }

        $summary = ['analysed' => 0, 'cached' => 0, 'entered' => 0, 'aiFtWins' => 0, 'aiHtWins' => 0, 'baselineFtWins' => 0, 'errors' => 0];
        foreach ($candidates as $row) {
            try {
                $analysis = $ai->analyse($row, (bool) $this->option('force'));
                $summary['analysed']++;
                $summary['cached'] += $analysis['cached'] ? 1 : 0;
                if ($analysis['decision'] !== 'enter') {
                    continue;
                }

                $summary['entered']++;
                $actualFt = $this->score($row, 'homeScore', 'awayScore');
                $actualHt = $this->score($row, 'halftimeHomeScore', 'halftimeAwayScore');
                $summary['aiFtWins'] += $actualFt !== null && $actualFt !== $analysis['methodology'] ? 1 : 0;
                $summary['aiHtWins'] += $actualHt !== null && $actualHt !== $analysis['methodology'] ? 1 : 0;
                $baseline = $ai->baselineMethodology($row);
                $summary['baselineFtWins'] += $actualFt !== null && $actualFt !== $baseline ? 1 : 0;
            } catch (RequestException $exception) {
                $summary['errors']++;
                if ($exception->response->status() === 429) {
                    $this->error('OpenRouter limitou temporariamente as requisições. Interrompendo o lote para evitar novas tentativas.');

                    return self::FAILURE;
                }
                $this->warn(($row['homeTeam'] ?? '—').' x '.($row['awayTeam'] ?? '—').': '.$exception->getMessage());
            } catch (Throwable $exception) {
                $summary['errors']++;
                $this->warn(($row['homeTeam'] ?? '—').' x '.($row['awayTeam'] ?? '—').': '.$exception->getMessage());
            }
        }

        $entered = $summary['entered'];
        $this->table(
            ['Analisadas', 'Cache', 'Entradas IA', 'FT IA', 'HT IA', 'FT baseline', 'Falhas'],
            [[
                $summary['analysed'],
                $summary['cached'],
                $entered,
                $entered > 0 ? $summary['aiFtWins'].' / '.$entered.' ('.number_format(($summary['aiFtWins'] / $entered) * 100, 1).'%)' : '—',
                $entered > 0 ? $summary['aiHtWins'].' / '.$entered.' ('.number_format(($summary['aiHtWins'] / $entered) * 100, 1).'%)' : '—',
                $entered > 0 ? $summary['baselineFtWins'].' / '.$entered.' ('.number_format(($summary['baselineFtWins'] / $entered) * 100, 1).'%)' : '—',
                $summary['errors'],
            ]],
        );
        $this->line('O resultado é exploratório: valide ROI e odds de entrada antes de usar como recomendação real.');

        return $summary['errors'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<string, mixed> $row */
    private function score(array $row, string $homeKey, string $awayKey): ?string
    {
        if (! is_numeric($row[$homeKey] ?? null) || ! is_numeric($row[$awayKey] ?? null)) {
            return null;
        }

        return sprintf('%d-%d', (int) $row[$homeKey], (int) $row[$awayKey]);
    }
}
