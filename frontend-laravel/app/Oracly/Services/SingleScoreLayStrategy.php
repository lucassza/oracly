<?php

namespace App\Oracly\Services;

/**
 * Contrato das estratégias de lay de UM evento por partida (placar 2x2, placar 0x0, goleada do
 * favorito), consumido por App\Filament\Pages\LayPlacarUnico.
 *
 * Diferente de AgainstFavouriteCleanSheetStrategy, que tem várias pernas por partida e odd justa
 * de coorte, aqui cada jogo tem a própria chance calibrada — e portanto a própria odd justa.
 */
interface SingleScoreLayStrategy
{
    /** Evento laydado como aparece na tela, depois de "Chance de" ('2x2', 'goleada'). */
    public function label(): string;

    /** @return array<string, string> perfil => rótulo */
    public function profiles(): array;

    /** @param array<string, mixed> $row */
    public function matchesProfile(array $row, string $profile): bool;

    /**
     * Chance CALIBRADA do placar sair, em pontos percentuais. É dela que sai a odd justa do jogo.
     *
     * @param array<string, mixed> $row
     */
    public function probability(array $row): ?float;

    /**
     * Chance calibrada e perfis em que o jogo passa, numa passada só pelo modelo.
     *
     * A tela avalia a base inteira (~44 mil jogos); chamar probability() e matchesProfile() por
     * perfil refaria o Poisson quatro vezes por linha.
     *
     * @param array<string, mixed> $row
     * @return array{probability: ?float, profiles: list<string>}
     */
    public function evaluate(array $row): array;

    /**
     * @param array<string, mixed> $row
     * @return 'green'|'red'|null
     */
    public function result(array $row): ?string;
}
