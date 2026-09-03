<?php

namespace App\Models\Punter;

use Illuminate\Database\Eloquent\Model;

/**
 * Fonte de sincronização Punter (uma linha por aba do Google Sheets importada).
 * Vive no schema `punter`, semeada por PunterSheetSourcesSeeder a partir de config/punter.php.
 */
class SheetSource extends Model
{
    protected $connection = 'punter';

    protected $table = 'sheet_sources';

    protected $fillable = [
        'label', 'spreadsheet_id', 'spreadsheet_url', 'sheet_name', 'range', 'is_active',
    ];

    protected $casts = [
        'extra' => 'array',
        'is_active' => 'boolean',
        'last_sync_at' => 'datetime',
    ];
}
