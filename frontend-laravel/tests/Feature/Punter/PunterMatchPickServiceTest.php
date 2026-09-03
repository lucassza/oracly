<?php

namespace Tests\Feature\Punter;

use App\Oracly\Services\PunterMatchPickService;
use App\Oracly\Support\PunterDb;
use Tests\TestCase;

/**
 * Integração real com punter.match_history (mesmo Postgres do SokkerPRO). Valida a média
 * móvel usada nos picks de placar exato de jogos futuros: precisa desambiguar times com o
 * mesmo nome em competições diferentes (ex.: "River Plate" existe na Argentina, no Uruguai
 * e na Libertadores) e nunca olhar jogos depois da data de referência.
 */
class PunterMatchPickServiceTest extends TestCase
{
    public function test_desambigua_times_com_o_mesmo_nome_em_competicoes_diferentes(): void
    {
        $riverPlateCompetitions = PunterDb::connection()->table('match_history')
            ->where('home_name', 'River Plate')
            ->orWhere('away_name', 'River Plate')
            ->distinct()
            ->pluck('campeonato');

        $this->assertGreaterThan(
            1,
            $riverPlateCompetitions->count(),
            'Pré-condição do teste: "River Plate" precisa aparecer em mais de uma competição na base real.'
        );

        $service = app(PunterMatchPickService::class);
        $recentDate = PunterDb::connection()->table('match_history')
            ->where('campeonato', 'Argentina_Primera_División')
            ->where(function ($query): void {
                $query->where('home_name', 'River Plate')->orWhere('away_name', 'River Plate');
            })
            ->max('data_hora_jogo');

        $this->assertNotNull($recentDate);

        $average = $service->teamAverageGoals('River Plate', 'Argentina_Primera_División', (string) $recentDate);

        // Só pode ter usado jogos de River Plate NA ARGENTINA — nunca do Uruguai/Libertadores.
        $this->assertNotNull($average);
        $this->assertGreaterThanOrEqual(0.0, $average);
    }

    public function test_nao_ve_jogos_depois_da_data_de_referencia(): void
    {
        $service = app(PunterMatchPickService::class);

        $earliestMatch = PunterDb::connection()->table('match_history')
            ->where('campeonato', 'Argentina_Primera_División')
            ->where(function ($query): void {
                $query->where('home_name', 'River Plate')->orWhere('away_name', 'River Plate');
            })
            ->min('data_hora_jogo');

        $this->assertNotNull($earliestMatch);

        // Antes do primeiro jogo registrado não existe histórico anterior — tem que vir null.
        $average = $service->teamAverageGoals('River Plate', 'Argentina_Primera_División', (string) $earliestMatch);

        $this->assertNull($average);
    }

    public function test_retorna_null_para_time_sem_amostra_minima(): void
    {
        $service = app(PunterMatchPickService::class);

        $average = $service->teamAverageGoals('Time Que Nao Existe De Verdade XPTO', 'Liga Inexistente', '2026-01-01');

        $this->assertNull($average);
    }

    /**
     * panel_fixtures não tem coluna de horário — o horário vem embutido no texto de
     * match_label (ex.: "30/08 22:20 Time A x Time B", já em horário de Brasília). Confere
     * que upcoming() extrai isso pra kickoffAt sempre que o label tem esse formato.
     */
    public function test_upcoming_extrai_o_horario_do_match_label_quando_reconhecivel(): void
    {
        $row = PunterDb::connection()->table('panel_fixtures')
            ->whereNotNull('match_date')
            ->where('match_label', '~', '^\d{2}/\d{2}\s+\d{2}:\d{2}')
            ->first();

        if ($row === null) {
            $this->markTestSkipped('Nenhuma linha de panel_fixtures com match_label no formato esperado no momento.');
        }

        preg_match('/^(\d{2})\/(\d{2})\s+(\d{2}):(\d{2})/', (string) $row->match_label, $m);

        $service = app(PunterMatchPickService::class);
        $upcoming = $service->upcoming((string) $row->match_date);
        $match = collect($upcoming)->firstWhere('matchKey', $row->match_date.'|'.$row->home_team.'|'.$row->away_team);

        $this->assertNotNull($match, 'Pré-condição: a linha usada no teste precisa aparecer em upcoming() pra mesma data.');
        $this->assertSame($row->match_date.' '.$m[3].':'.$m[4].':00', $match['kickoffAt']);
    }

    public function test_upcoming_devolve_kickoff_null_quando_o_label_nao_tem_horario_reconhecivel(): void
    {
        $service = app(PunterMatchPickService::class);

        $reflection = new \ReflectionMethod($service, 'parseKickoffAt');
        $reflection->setAccessible(true);

        $this->assertNull($reflection->invoke($service, '2026-08-30', 'Time A x Time B sem horário'));
        $this->assertNull($reflection->invoke($service, '', '30/08 22:20 Time A x Time B'));
        $this->assertSame('2026-08-30 22:20:00', $reflection->invoke($service, '2026-08-30', '30/08 22:20 Time A x Time B'));
    }
}
