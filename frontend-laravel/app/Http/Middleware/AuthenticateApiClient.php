<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $clientId = $request->header('X-Client-Id');
        $token = $request->bearerToken();
        $client = is_string($clientId) ? ApiClient::query()->where('client_id', $clientId)->whereNull('revoked_at')->first() : null;

        if (! $client || ! is_string($token) || ! $client->tokenMatches($token)) {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }

        $client->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set('apiClient', $client);

        return $next($request);
    }
}
