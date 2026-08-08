<?php

namespace App\Oracly\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Connection;

final class OraclyDb
{
    public static function connection(): Connection
    {
        return DB::connection('oracly');
    }

    public static function table(string $name): string
    {
        $schema = config('database.connections.oracly.search_path', 'public');

        return '"'.$schema.'"."'.$name.'"';
    }
}
