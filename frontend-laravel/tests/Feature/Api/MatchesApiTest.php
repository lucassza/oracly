<?php

namespace Tests\Feature\Api;

use App\Models\ApiClient;
use App\Oracly\Contracts\DailyMatchesProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MatchesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requires_valid_client_credentials(): void
    {
        $this->getJson('/api/v1/matches')->assertUnauthorized();
    }

    public function test_it_returns_all_matches_for_an_authenticated_client(): void
    {
        [$client, $token] = ApiClient::createWithToken('Integração de partidas');
        $this->mock(DailyMatchesProvider::class, function ($mock): void {
            $mock->shouldReceive('forDate')->once()->with('2026-08-22')->andReturn([
                [
                    'providerMatchId' => 'fixture-123',
                    'kickoffAt' => '2026-08-22T18:00:00.000Z',
                    'competition' => 'Campeonato',
                    'homeTeam' => 'Casa',
                    'awayTeam' => 'Fora',
                    'status' => 'not_started',
                    'homeScore' => null,
                    'awayScore' => null,
                    'predictions' => [],
                    'sokkerproUrl' => 'https://sokkerpro.com/partida/fixture-123',
                ],
            ]);
        });

        $this->getJson('/api/v1/matches?date=2026-08-22', [
            'X-Client-Id' => $client->client_id,
            'Authorization' => 'Bearer '.$token,
        ])->assertOk()
            ->assertJsonPath('data.date', '2026-08-22')
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.matches.0.homeTeam', 'Casa')
            ->assertJsonPath('data.matches.0.sokkerproUrl', 'https://sokkerpro.com/partida/fixture-123');
    }

    public function test_it_returns_a_null_link_when_sokkerpro_does_not_supply_one(): void
    {
        [$client, $token] = ApiClient::createWithToken('Integração de partidas');
        $this->mock(DailyMatchesProvider::class, function ($mock): void {
            $mock->shouldReceive('forDate')->once()->andReturn([['sokkerproUrl' => null]]);
        });

        $this->getJson('/api/v1/matches', [
            'X-Client-Id' => $client->client_id,
            'Authorization' => 'Bearer '.$token,
        ])->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.matches.0.sokkerproUrl', null);
    }

    public function test_it_validates_the_requested_date(): void
    {
        [$client, $token] = ApiClient::createWithToken('Integração de partidas');

        $this->getJson('/api/v1/matches?date=22-08-2026', [
            'X-Client-Id' => $client->client_id,
            'Authorization' => 'Bearer '.$token,
        ])->assertUnprocessable()->assertJsonValidationErrors('date');
    }

    public function test_it_filters_matches_by_kickoff_hour_in_brasilia(): void
    {
        [$client, $token] = ApiClient::createWithToken('Integração de partidas');
        $this->mock(DailyMatchesProvider::class, function ($mock): void {
            $mock->shouldReceive('forDate')->once()->with('2026-08-22')->andReturn([
                ['providerMatchId' => 'fixture-18', 'kickoffAt' => '2026-08-22T21:00:00.000Z'],
                ['providerMatchId' => 'fixture-19', 'kickoffAt' => '2026-08-22T22:00:00.000Z'],
                ['providerMatchId' => 'fixture-without-time', 'kickoffAt' => null],
            ]);
        });

        $this->getJson('/api/v1/matches?date=2026-08-22&hour=18', [
            'X-Client-Id' => $client->client_id,
            'Authorization' => 'Bearer '.$token,
        ])->assertOk()
            ->assertJsonPath('data.hour', 18)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.matches.0.providerMatchId', 'fixture-18');
    }

    public function test_it_validates_the_requested_hour(): void
    {
        [$client, $token] = ApiClient::createWithToken('Integração de partidas');

        $this->getJson('/api/v1/matches?hour=24', [
            'X-Client-Id' => $client->client_id,
            'Authorization' => 'Bearer '.$token,
        ])->assertUnprocessable()->assertJsonValidationErrors('hour');
    }
}
