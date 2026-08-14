<?php

namespace Tests\Feature;

use App\Oracly\Services\AgainstOneGoalAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgainstOneGoalAiServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_a_structured_pre_kickoff_analysis(): void
    {
        config()->set('services.openrouter.key', 'test-key');
        config()->set('services.openrouter.model', 'test-model');
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [[
                    'message' => ['content' => json_encode([
                        'decision' => 'enter',
                        'methodology' => '0-1',
                        'confidence' => 74,
                        'rationale' => 'Sinais de gols consistentes para evitar um placar exato baixo.',
                        'riskFlags' => ['amostra limitada'],
                    ])],
                ]],
            ]),
        ]);

        $result = app(AgainstOneGoalAiService::class)->analyse($this->row());

        $this->assertSame('enter', $result['decision']);
        $this->assertFalse($result['cached']);
        $this->assertDatabaseHas('ai_strategy_analyses', [
            'strategy' => AgainstOneGoalAiService::STRATEGY,
            'provider_match_id' => 'fixture-123',
            'model' => 'test-model',
            'decision' => 'enter',
            'methodology' => '0-1',
        ]);
        Http::assertSent(function (Request $request): bool {
            $content = $request->data()['messages'][1]['content'];

            return ! str_contains($content, 'homeScore')
                && ! str_contains($content, 'awayScore')
                && str_contains($content, 'homeGoalsAverage');
        });
    }

    public function test_it_uses_the_poisson_baseline_to_choose_a_methodology(): void
    {
        $service = app(AgainstOneGoalAiService::class);

        $this->assertSame('0-1', $service->baselineMethodology([
            'homeGoalsAverage' => 1.8,
            'awayGoalsAverage' => 0.9,
        ]));
        $this->assertSame('1-0', $service->baselineMethodology([
            'homeGoalsAverage' => 0.9,
            'awayGoalsAverage' => 1.8,
        ]));
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        return [
            'providerMatchId' => 'fixture-123',
            'kickoffAt' => '2026-08-13T18:00:00.000Z',
            'country' => 'Brasil',
            'competition' => 'Serie A',
            'homeTeam' => 'Casa',
            'awayTeam' => 'Fora',
            'probability' => 78,
            'over25Probability' => 61,
            'bttsProbability' => 65,
            'combinedGoalsAverage' => 3.1,
            'homeGoalsAverage' => 1.8,
            'awayGoalsAverage' => 0.9,
            'homeOdd' => 1.8,
            'drawOdd' => 3.5,
            'awayOdd' => 4.2,
            'homeScore' => 3,
            'awayScore' => 0,
        ];
    }
}
