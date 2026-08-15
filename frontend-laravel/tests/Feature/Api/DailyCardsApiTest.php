<?php

namespace Tests\Feature\Api;

use App\Models\ApiClient;
use App\Oracly\Services\DailyCardsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyCardsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requires_valid_client_credentials(): void
    {
        $this->getJson('/api/v1/daily-cards')->assertUnauthorized();

        $this->getJson('/api/v1/daily-cards', ['X-Client-Id' => 'invalid', 'Authorization' => 'Bearer invalid'])
            ->assertUnauthorized();
    }

    public function test_it_returns_daily_cards_for_an_authenticated_client(): void
    {
        [$client, $token] = ApiClient::createWithToken('Integração externa');
        $this->mock(DailyCardsService::class, function ($mock): void {
            $mock->shouldReceive('forDate')->once()->with('2026-08-15')->andReturn([
                'date' => '2026-08-15',
                'groups' => [['key' => 'upcoming', 'cards' => []]],
            ]);
        });

        $this->getJson('/api/v1/daily-cards?date=2026-08-15', [
            'X-Client-Id' => $client->client_id,
            'Authorization' => 'Bearer '.$token,
        ])->assertOk()
            ->assertJsonPath('data.date', '2026-08-15')
            ->assertJsonPath('data.groups.0.key', 'upcoming');
    }

    public function test_it_validates_the_requested_date(): void
    {
        [$client, $token] = ApiClient::createWithToken('Integração externa');

        $this->getJson('/api/v1/daily-cards?date=15-08-2026', [
            'X-Client-Id' => $client->client_id,
            'Authorization' => 'Bearer '.$token,
        ])->assertUnprocessable()->assertJsonValidationErrors('date');
    }

    public function test_revoked_clients_are_rejected(): void
    {
        [$client, $token] = ApiClient::createWithToken('Integração externa');
        $client->update(['revoked_at' => now()]);

        $this->getJson('/api/v1/daily-cards', [
            'X-Client-Id' => $client->client_id,
            'Authorization' => 'Bearer '.$token,
        ])->assertUnauthorized();
    }
}
