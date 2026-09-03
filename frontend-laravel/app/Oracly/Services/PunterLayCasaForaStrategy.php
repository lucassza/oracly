<?php

namespace App\Oracly\Services;

/**
 * LAY Casa / LAY Fora a partir dos dados Punter (punter.match_history / panel_fixtures via
 * PunterMatchPickService): laya-se o time com a odd mais alta (o azarão), medido pela odd do
 * favorito — critério próprio, não a recomendação da planilha (`punterFlagsLayFora/Casa`), que
 * historicamente acerta menos que um corte de odd direto (78,4% vs 92,5% com odd < 1,30).
 *
 * Cortes de `matchesProfile()` calibrados por `php artisan punter:backtest-lay-casa-fora`.
 */
final class PunterLayCasaForaStrategy
{
    /** @var array<string, float> */
    public const PROFILES = [
        'baseline' => 1.80,
        'balanced' => 1.50,
        'strong' => 1.30,
    ];

    /**
     * @param  array<string, mixed>  $row
     * @return array{side: string, favoriteOdd: float, underdogOdd: float, punterAgrees: bool}|null
     */
    public function choice(array $row): ?array
    {
        $home = $this->validOdd($row['oddHome'] ?? null);
        $away = $this->validOdd($row['oddAway'] ?? null);

        if ($home === null || $away === null || $home === $away) {
            return null;
        }

        $side = $home < $away ? 'fora' : 'casa';

        return [
            'side' => $side,
            'favoriteOdd' => min($home, $away),
            'underdogOdd' => max($home, $away),
            'punterAgrees' => (bool) ($side === 'fora' ? ($row['punterFlagsLayFora'] ?? false) : ($row['punterFlagsLayCasa'] ?? false)),
        ];
    }

    public function matchesProfile(array $row, string $profile): bool
    {
        $choice = $this->choice($row);
        if ($choice === null || ! array_key_exists($profile, self::PROFILES)) {
            return false;
        }

        return $choice['favoriteOdd'] < self::PROFILES[$profile];
    }

    /** @return 'green'|'red'|null */
    public function result(array $row, string $side): ?string
    {
        return $side === 'fora' ? ($row['resultLayFora'] ?? null) : ($row['resultLayCasa'] ?? null);
    }

    private function validOdd(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $odd = (float) $value;

        return $odd > 1.0 ? $odd : null;
    }
}
