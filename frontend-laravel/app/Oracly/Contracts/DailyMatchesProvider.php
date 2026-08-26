<?php

namespace App\Oracly\Contracts;

interface DailyMatchesProvider
{
    /** @return list<array<string, mixed>> */
    public function forDate(string $dateBrasilia): array;
}
