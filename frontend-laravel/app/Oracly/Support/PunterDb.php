<?php

namespace App\Oracly\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Connection;

final class PunterDb
{
    public static function connection(): Connection
    {
        return DB::connection('punter');
    }

    public static function table(string $name): string
    {
        $schema = config('database.connections.punter.search_path', 'punter');

        return '"'.$schema.'"."'.$name.'"';
    }
}
