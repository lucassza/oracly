<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ApiClient extends Model
{
    protected $fillable = ['name', 'client_id', 'token_hash', 'revoked_at', 'last_used_at'];

    protected function casts(): array
    {
        return ['revoked_at' => 'datetime', 'last_used_at' => 'datetime'];
    }

    /** @return array{0: self, 1: string} */
    public static function createWithToken(string $name): array
    {
        $token = Str::random(64);
        $client = static::create([
            'name' => $name,
            'client_id' => (string) Str::uuid(),
            'token_hash' => hash('sha256', $token),
        ]);

        return [$client, $token];
    }

    public function rotateToken(): string
    {
        $token = Str::random(64);
        $this->forceFill(['token_hash' => hash('sha256', $token), 'revoked_at' => null])->save();

        return $token;
    }

    public function tokenMatches(string $token): bool
    {
        return hash_equals($this->token_hash, hash('sha256', $token));
    }
}
