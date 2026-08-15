<?php

use App\Http\Controllers\Api\DailyCardsController;
use App\Http\Middleware\AuthenticateApiClient;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticateApiClient::class)->get('/v1/daily-cards', DailyCardsController::class);
