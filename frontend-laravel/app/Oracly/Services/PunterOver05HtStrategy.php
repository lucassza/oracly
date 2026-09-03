<?php

namespace App\Oracly\Services;

/**
 * Over 0.5 HT a partir dos dados Punter (punter.match_history / panel_fixtures via
 * PunterMatchPickService) — aposta A FAVOR de sair gol no 1º tempo, não uma LAY.
 *
 * Testei um modelo Poisson com as médias de gols do 1º tempo (media_gols_no_1t_casa/
 * visitante) e ele não discrimina nada (63-71% em qualquer faixa de probabilidade — gols
 * de HT são voláteis demais pra uma média simples prever). O que realmente funciona é a
 * odd de mercado do próprio Over 0.5 HT (`odds_1st_half_over05`): 77,8% com odd < 1,30,
 * caindo suavemente até ~70% sem corte nenhum — muito mais forte que a recomendação do
 * Punter sozinha (72,5%).
 *
 * Só que essa odd não existe em panel_fixtures (jogos futuros) — só no histórico apurado.
 * Por isso o critério muda por modo: histórico usa a odd (`matchesOddProfile`), lista do
 * dia usa a recomendação do Punter (`punterRecommends`, mais fraca, mas é o único sinal
 * disponível pra jogo que ainda não aconteceu).
 */
final class PunterOver05HtStrategy
{
    /** @var array<string, float> */
    public const ODD_PROFILES = [
        'baseline' => 1.60,
        'balanced' => 1.45,
        'strong' => 1.30,
    ];

    public function matchesOddProfile(array $row, string $profile): bool
    {
        $odd = $row['oddOver05Ht'] ?? null;
        if ($odd === null || ! is_numeric($odd) || $odd <= 1.0 || ! array_key_exists($profile, self::ODD_PROFILES)) {
            return false;
        }

        return (float) $odd < self::ODD_PROFILES[$profile];
    }

    public function punterRecommends(array $row): bool
    {
        return (bool) ($row['punterFlagsOver05Ht'] ?? false);
    }

    /** @return 'green'|'red'|null */
    public function result(array $row): ?string
    {
        return $row['resultOver05Ht'] ?? null;
    }
}
