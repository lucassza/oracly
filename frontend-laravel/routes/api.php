<?php

use App\Http\Controllers\Api\DailyCardsController;
use App\Http\Controllers\Api\DailyLayListController;
use App\Http\Controllers\Api\MatchesController;
use App\Http\Middleware\AuthenticateApiClient;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticateApiClient::class)->get('/v1/daily-cards', DailyCardsController::class);
Route::middleware(AuthenticateApiClient::class)->get('/v1/daily-lay-list', DailyLayListController::class);
Route::middleware(AuthenticateApiClient::class)->get('/v1/matches', MatchesController::class);
