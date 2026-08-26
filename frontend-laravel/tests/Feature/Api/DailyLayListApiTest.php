<?php

namespace Tests\Feature\Api;

use App\Models\ApiClient;
use App\Oracly\Services\DailyLayListService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyLayListApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requires_valid_client_credentials(): void
    {
        $this->getJson('/api/v1/daily-lay-list')->assertUnauthorized();
    }

    public function test_it_returns_lay_entries_with_the_sokkerpro_link(): void
    {
        [$client, $token] = ApiClient::createWithToken('Integração Lay');
        $this->mock(DailyLayListService::class, function ($mock): void {
            $mock->shouldReceive('forDate')->once()->with('2026-08-22')->andReturn([
                'date' => '2026-08-22',
                'total' => 1,
                'entries' => [[
                    'providerMatchId' => 'fixture-123',
                    'homeTeam' => 'Casa',
                    'awayTeam' => 'Fora',
                    'sourceUrl' => 'https://sokkerpro.com/partida/fixture-123',
                    'sokkerproUrl' => 'https://sokkerpro.com/partida/fixture-123',
                    'strategy' => ['bet' => 'LAY 0x1', 'rank' => 1],
                ]],
            ]);
        });

        $this->getJson('/api/v1/daily-lay-list?date=2026-08-22', [
            'X-Client-Id' => $client->client_id,
            'Authorization' => 'Bearer '.$token,
        ])->assertOk()
            ->assertJsonPath('data.date', '2026-08-22')
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.entries.0.sokkerproUrl', 'https://sokkerpro.com/partida/fixture-123')
            ->assertJsonPath('data.entries.0.strategy.bet', 'LAY 0x1');
    }

    public function test_it_validates_the_requested_date(): void
    {
        [$client, $token] = ApiClient::createWithToken('Integração Lay');

        $this->getJson('/api/v1/daily-lay-list?date=22-08-2026', [
            'X-Client-Id' => $client->client_id,
            'Authorization' => 'Bearer '.$token,
        ])->assertUnprocessable()->assertJsonValidationErrors('date');
    }
}
