<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Odd de lay que a exchange ofereceu num jogo, digitada pelo operador nas telas LAY 2x2 e 0x0.
 *
 * Existe para responder a única pergunta que o histórico não responde: a exchange paga abaixo
 * da odd justa? A odd justa e a chance ficam gravadas junto, do jeito que estavam na hora do
 * registro, porque a calibração pode mudar depois.
 */
class LayOddQuote extends Model
{
    protected $fillable = [
        'user_id',
        'strategy',
        'match_key',
        'match_date',
        'kickoff_at',
        'home_team',
        'away_team',
        'competition',
        'profile',
        'probability',
        'fair_odd',
        'offered_odd',
    ];

    protected function casts(): array
    {
        return [
            'match_date' => 'date:Y-m-d',
            'kickoff_at' => 'datetime',
            'probability' => 'float',
            'fair_odd' => 'float',
            'offered_odd' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Odd oferecida sobre a justa. Abaixo de LayPricing::ENTRY_RATIO a entrada compensa. */
    public function ratio(): ?float
    {
        return $this->fair_odd ? $this->offered_odd / $this->fair_odd : null;
    }
}
