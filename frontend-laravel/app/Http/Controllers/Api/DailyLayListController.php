<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Oracly\Services\DailyLayListService;
use App\Oracly\Support\BrasiliaDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DailyLayListController extends Controller
{
    public function __invoke(Request $request, DailyLayListService $layList): JsonResponse
    {
        $data = Validator::make($request->query(), ['date' => ['nullable', 'date_format:Y-m-d']])->validate();
        $date = $data['date'] ?? BrasiliaDate::today();

        return response()->json(['data' => $layList->forDate($date)]);
    }
}
