<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Oracly\Contracts\DailyMatchesProvider;
use App\Oracly\Support\BrasiliaDate;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MatchesController extends Controller
{
    public function __invoke(Request $request, DailyMatchesProvider $matches): JsonResponse
    {
        $data = Validator::make($request->query(), [
            'date' => ['nullable', 'date_format:Y-m-d'],
            'hour' => ['nullable', 'integer', 'between:0,23'],
        ])->validate();
        $date = $data['date'] ?? BrasiliaDate::today();
        $rows = $matches->forDate($date);
        $hour = array_key_exists('hour', $data) ? (int) $data['hour'] : null;

        if ($hour !== null) {
            $rows = array_values(array_filter($rows, function (array $row) use ($hour): bool {
                $kickoffAt = $row['kickoffAt'] ?? null;

                return is_string($kickoffAt)
                    && Carbon::parse($kickoffAt)->timezone('America/Sao_Paulo')->hour === $hour;
            }));
        }

        return response()->json([
            'data' => [
                'date' => $date,
                'hour' => $hour,
                'total' => count($rows),
                'matches' => $rows,
            ],
        ]);
    }
}
