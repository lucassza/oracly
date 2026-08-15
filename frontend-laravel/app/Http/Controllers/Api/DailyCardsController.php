<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Oracly\Services\DailyCardsService;
use App\Oracly\Support\BrasiliaDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DailyCardsController extends Controller
{
    public function __invoke(Request $request, DailyCardsService $cards): JsonResponse
    {
        $data = Validator::make($request->query(), ['date' => ['nullable', 'date_format:Y-m-d']])->validate();
        $date = $data['date'] ?? BrasiliaDate::today();

        return response()->json(['data' => $cards->forDate($date)]);
    }
}
