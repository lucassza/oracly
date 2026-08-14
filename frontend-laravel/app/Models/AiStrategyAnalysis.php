<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiStrategyAnalysis extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'strategy',
        'provider_match_id',
        'model',
        'input_hash',
        'decision',
        'methodology',
        'confidence',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'integer',
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
