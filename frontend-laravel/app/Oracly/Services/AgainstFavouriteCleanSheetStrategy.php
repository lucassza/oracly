<?php

namespace App\Oracly\Services;

/**
 * LAY do placar em que o FAVORITO vence sem sofrer gol (1x0 e 2x0), filtrado por BTTS.
 *
 * É o oposto deliberado do que as quatro estratégias AgainstNGoals fazem:
 * AgainstOneGoalStrategy::choice() compara os dois placares simétricos e escolhe o MENOS
 * provável, que é sempre o azarão vencendo a zero. Aquele é o lay de maior assertividade e
 * também o de maior responsabilidade, porque placar improvável tem odd de lay alta. Aqui a
 * perna é a barata: o favorito vencendo a zero, odd de lay baixa, responsabilidade menor.
 *
 * O que torna a perna barata viável é o BTTS. Medido em 44.214 partidas do Punter com odd
 * 1X2 e odd BTTS válidas (2023-01 a 2026-09), laydando o 1x0 do favorito:
 *
 *   BTTS < 50%   n=17.014   85,79%
 *   BTTS 50-55%  n=13.620   89,07%
 *   BTTS 55-60%  n= 8.687   90,63%
 *   BTTS >= 60%  n= 4.893   93,38%
 *
 * Monotônico, 7,6pp de ponta a ponta, e estável no split temporal 70/30 (dentro do corte de
 * 60% a diferença dev/val fica abaixo de 1pp em todas as faixas de odd do favorito). Isso
 * revisa o banimento atacadista do sub-caso 1-0 em DailyDecision.php:134 (90,7% agregado):
 * o problema não era o placar, era a falta de filtro.
 *
 * A força do favorito quase não discrimina e não é monotônica, por isso ela entra só no perfil
 * strong e como corte largo. O backtest confirmou que ela NÃO paga o próprio custo: strong
 * entrega 93,5% em 4.138 entradas contra 93,4% em 4.825 do balanced, ou seja, perde 14% do
 * volume para ganhar 0,1pp. Mantida por enquanto como corte de risco, não de assertividade;
 * se a tela vier a expor um perfil só, use balanced.
 *
 * VALIDAÇÃO TEMPORAL (punter:backtest-lay-favorito, split 70/30, 43.824 partidas):
 *
 *   perfil     perna       n       total   dev     val
 *   balanced   LAY 1x0     4.825   93,4%   93,4%   93,4%
 *   balanced   LAY 2x0     4.825   93,7%   93,8%   93,7%
 *   balanced   carteira    4.825   87,2%   87,1%   87,2%
 *
 * Desenvolvimento e validação idênticos até a primeira casa. Sem concentração por liga: a
 * maior responde por 15,2% das entradas.
 *
 * A CARTEIRA CONCENTRA RISCO. As duas pernas caem na mesma partida, então 87,2% de acerto
 * conjunto contra 93,4% de uma perna só não é diversificação, é dobro de exposição no mesmo
 * jogo. Reportar sempre as duas leituras lado a lado.
 *
 * ANCORAGEM NA ODD, NÃO EM POISSON. Além de ser o pedido, há motivo técnico: a média de gols
 * que alimenta o Poisson no lado Punter está com semântica trocada (media_gols_total_casa vale
 * 2,665 contra 1,508 de gols do mandante — é o total dos dois times), o que infla lambda e
 * torna a escolha entre os dois placares simétricos praticamente sorteio naquelas telas.
 * A odd 1X2 é limpa, tem cobertura total e identifica o favorito sem ambiguidade.
 *
 * ODD DE ABERTURA CORRE MAIS CURTA QUE A DE FECHAMENTO. A mediana de opening_odd_btts nos
 * jogos futuros é 1,70 contra 1,80 de odds_btts_yes no histórico apurado, ou seja, a lista do
 * dia enxerga a probabilidade ~3pp mais alta que o backtest para o mesmo jogo. Os cortes crus
 * foram calibrados no histórico, então a lista diária seleciona um pouco mais do que o backtest
 * selecionaria. Só dá pra corrigir isso medindo, e panel_fixtures é tabela de refresh com 7
 * dias — não acumula histórico. A tela precisa avisar.
 *
 * NÃO REPORTA RETORNO. Nenhuma fonte do projeto tem odd de mercado de placar exato. O
 * substituto é fairLayOdd(): o inverso da frequência observada do placar. Entre no lay só
 * quando a odd oferecida estiver ABAIXO dela. No perfil balanced a odd justa medida é 15,17
 * para a perna de 1x0 e 15,98 para a de 2x0.
 *
 * O LADO DO AZARÃO tem as mesmas duas pernas, medidas nas mesmas partidas do corte de 60%:
 * 1x0 acerta 94,63% (justa 18,63) e 2x0 acerta 96,60% (justa 29,42), ambas estáveis na
 * validação temporal. Acertam mais e pagam pior — ao preço justo as quatro empatam, e quem
 * decide é o desconto que a exchange oferece. A carteira das quatro pernas juntas cai para
 * 78,38%, um erro a cada cinco jogos: é quadruplicar a exposição na mesma partida, não
 * diversificar.
 *
 * É AQUI QUE A PERNA BARATA GANHA. Nas mesmas partidas do balanced, a perna espelhada (azarão
 * a zero, que é para onde a regra atual converge) acerta mais — 94,6% contra 93,4% — mas o
 * placar dela ocorre menos, então a odd justa sobe para 18,63. Laydar 15,17 em vez de 18,63
 * é a economia de responsabilidade que justifica trocar 1,2pp de assertividade.
 */
final class AgainstFavouriteCleanSheetStrategy
{
    /** Os dois lados laydáveis, pela odd 1X2. */
    public const SIDES = [
        'favourite' => 'favorito',
        'underdog' => 'azarão',
    ];

    /** As duas margens de vitória cobertas. */
    public const TARGETS = [1, 2];

    /**
     * As quatro pernas, na ordem em que a tela lista.
     *
     * @var array<string, array{side: string, goals: int, label: string}>
     */
    public const LEGS = [
        'fav1' => ['side' => 'favourite', 'goals' => 1, 'label' => 'LAY favorito vence 1 a 0'],
        'fav2' => ['side' => 'favourite', 'goals' => 2, 'label' => 'LAY favorito vence 2 a 0'],
        'dog1' => ['side' => 'underdog', 'goals' => 1, 'label' => 'LAY azarão vence 1 a 0'],
        'dog2' => ['side' => 'underdog', 'goals' => 2, 'label' => 'LAY azarão vence 2 a 0'],
    ];

    /**
     * O rótulo NÃO carrega placar de propósito.
     *
     * "LAY 1x0 do favorito" parecia um placar e contradizia a coluna ao lado: quando o
     * favorito é o visitante, ele vencer a zero por 1 é 0-1 na ordem casa-fora, não 1-0.
     * O número no nome era a margem vista do lado do favorito, o da coluna é a orientação
     * real da partida — dois significados para a mesma cara.
     *
     * A forma "LAY favorito vence 1 a 0" resolve porque nomeia o sujeito: quem vence por 1 a
     * 0 é o favorito, seja ele mandante ou visitante. O placar em ordem casa-fora fica onde
     * sempre esteve, em layScore(). Nunca usar a forma NxN no rótulo — é a que colide.
     */

    /**
     * Pernas em que o filtro de BTTS realmente separa.
     *
     * Medido nas mesmas 43.824 partidas, variação entre a faixa mais baixa e a mais alta:
     *   fav1  85,79 → 93,41%  (+7,6pp)   fav2  90,68 → 93,74%  (+3,1pp)
     *   dog1  92,65 → 94,63%  (+2,0pp)   dog2  97,09 → 96,60%  (−0,5pp, plano e invertido)
     *
     * O azarão vencer a zero já é raro por definição, então a perna nasce segura e não há o
     * que filtrar — na de 2x0 o filtro não diz nada. As pernas do azarão continuam gateadas
     * pelo perfil para a lista não virar ruído, mas a tela precisa marcar que ali o critério
     * não é o que decide.
     */
    public const BTTS_DISCRIMINATES = ['fav1' => true, 'fav2' => true, 'dog1' => false, 'dog2' => false];

    /** Ganho do filtro de BTTS em pontos percentuais, por perna. Ver BTTS_DISCRIMINATES. */
    public const BTTS_LIFT = ['fav1' => 7.6, 'fav2' => 3.1, 'dog1' => 2.0, 'dog2' => -0.5];

    /**
     * A perna recomendada — e o motivo NÃO é assertividade.
     *
     * Ao mesmo desconto relativo sobre a odd justa, as quatro rendem exatamente o mesmo:
     * laydando a k vezes a justa, o retorno esperado é 1 − k, qualquer que seja a
     * probabilidade. Assertividade maior é paga integralmente por odd maior. Por isso
     * escolher pela taxa de acerto (dog2, 96,60%) não melhora nada — só troca arriscar 14,2
     * para ganhar 1 por arriscar 28,4.
     *
     * Também não há escolha por partida: medido em todas as faixas de odd do favorito, a
     * ordem entre as quatro nunca se inverte (dog2 vence de 95,63% a 98,58%). Um seletor por
     * jogo degenera em "sempre dog2".
     *
     * O que distingue fav1 é ser a única perna em que o modelo trabalha: o filtro de BTTS
     * vale +7,6pp nela, contra +3,1, +2,0 e −0,5 nas outras. Os 96,60% de dog2 já existem
     * sem nós, é só um placar raro. Dos 93,41% de fav1, sete pontos e meio vieram da
     * seleção — é a única em que temos informação que o preço talvez ainda não tenha.
     */
    public const RECOMMENDED_LEG = 'fav1';

    public const RECOMMENDED_REASON = 'É a única perna em que o filtro de ambas marcam trabalha: +7,6 pontos, contra +3,1, +2,0 e −0,5 nas outras. Não é a de maior assertividade, e não precisa ser: ao mesmo desconto sobre a odd justa, as quatro rendem igual.';

    /**
     * Corte de odd do favorito que funciona SÓ no lado do azarão.
     *
     * Favorito forte significa azarão vencendo menos, então o placar laydo fica mais raro.
     * Medido no corte de BTTS ≥ 60%, com validação temporal 70/30:
     *
     *   favorito < 1,90  (n=1.622)   1x0 95,50% (val 95,13%)   2x0 97,97% (val 98,09%)
     *   favorito ≥ 1,90  (n=3.203)   1x0 94,19% (val 93,92%)   2x0 95,91% (val 95,06%)
     *
     * No lado do FAVORITO o mesmo corte não diz nada: as taxas oscilam entre 4,63% e 7,51%
     * de red sem forma, e a margem de 95% de cada faixa engloba a média geral. Por isso o
     * filtro existe só na tela da zebra — replicá-lo na do favorito seria vender ruído.
     */
    public const ZEBRA_FAVOURITE_ODD_CUT = 1.90;

    /**
     * As pernas de um lado, na ordem da tela.
     *
     * @return array<string, array{side: string, goals: int, label: string}>
     */
    public static function legsForSide(string $side): array
    {
        return array_filter(self::LEGS, fn (array $leg): bool => $leg['side'] === $side);
    }

    /** O par do lado: 1x0 e 2x0 do mesmo time são mutuamente exclusivos, só um pode sair. */
    public static function recommendedLegForSide(string $side): string
    {
        return $side === 'favourite'
            ? self::RECOMMENDED_LEG
            : 'dog2';  // no azarão o filtro de BTTS não trabalha; sobra a perna mais rara.
    }

    /** @var array<string, string> */
    public const PROFILES = [
        'baseline' => 'BTTS ≥ 55%',
        'balanced' => 'BTTS ≥ 60%',
        'strong' => 'BTTS ≥ 60% e favorito claro',
    ];

    /** Cortes na escala SEM margem, usada quando as duas odds de BTTS estão disponíveis. */
    private const BTTS_BASELINE = 55.0;
    private const BTTS_BALANCED = 60.0;
    private const BTTS_STRONG = 60.0;

    /**
     * Cortes na escala CRUA (1/odd, com a margem dentro), usada quando só o lado "sim" existe —
     * é o caso de panel_fixtures, que traz opening_odd_btts sozinho.
     *
     * Calibrados contra os cortes sem margem no próprio histórico, casando volume e
     * assertividade: cru ≥ 59 seleciona 13.360 partidas a 90,5-93,4% (sem margem ≥ 55 seleciona
     * 13.439 a 91,6%), e cru ≥ 65 seleciona 4.523 a 93,37% (sem margem ≥ 60 seleciona 4.825 a
     * 93,4%). As duas escalas param no mesmo lugar.
     */
    private const BTTS_RAW_BASELINE = 59.0;
    private const BTTS_RAW_BALANCED = 65.0;
    private const BTTS_RAW_STRONG = 65.0;

    /** Corte largo de propósito: a odd do favorito discrimina pouco (ver docblock da classe). */
    private const FAVOURITE_ODD_STRONG = 2.40;

    /**
     * 'home' ou 'away', pela menor odd 1X2.
     *
     * Odds empatadas devolvem null em vez de chutar o mandante: sem favorito definido não há
     * perna, e um palpite aqui contaminaria a medição com jogos equilibrados.
     *
     * @param array<string, mixed> $row
     */
    public function favourite(array $row): ?string
    {
        $home = $this->validOdd($row['oddHome'] ?? null);
        $away = $this->validOdd($row['oddAway'] ?? null);
        if ($home === null || $away === null || $home === $away) {
            return null;
        }

        return $home < $away ? 'home' : 'away';
    }

    /**
     * Placar a laydar, sempre na orientação casa-fora.
     *
     * @param array<string, mixed> $row
     * @return '1-0'|'0-1'|'2-0'|'0-2'|null
     */
    public function layScore(array $row, int $targetGoals, string $side = 'favourite'): ?string
    {
        if (! in_array($targetGoals, self::TARGETS, true) || ! array_key_exists($side, self::SIDES)) {
            return null;
        }
        $favourite = $this->favourite($row);
        if ($favourite === null) {
            return null;
        }

        // Quem vence a zero: o favorito, ou o outro lado quando se laya o azarão.
        $winner = $side === 'favourite' ? $favourite : ($favourite === 'home' ? 'away' : 'home');

        return $winner === 'home' ? $targetGoals.'-0' : '0-'.$targetGoals;
    }

    /** @return array{side: string, goals: int, label: string}|null */
    public static function leg(string $leg): ?array
    {
        return self::LEGS[$leg] ?? null;
    }

    /**
     * Resultado de uma perna pela sua chave ('fav1', 'dog2', …).
     *
     * @param array<string, mixed> $row
     * @return 'green'|'red'|null
     */
    public function resultForLeg(array $row, string $leg): ?string
    {
        $parts = self::leg($leg);

        return $parts === null ? null : $this->result($row, $parts['goals'], $parts['side']);
    }

    /**
     * Placar de uma perna pela sua chave.
     *
     * @param array<string, mixed> $row
     */
    public function layScoreForLeg(array $row, string $leg): ?string
    {
        $parts = self::leg($leg);

        return $parts === null ? null : $this->layScore($row, $parts['goals'], $parts['side']);
    }

    /**
     * Probabilidade de BTTS implícita nas duas odds, já sem a margem da casa.
     *
     * A margem medida é de 7,5% (1/sim + 1/nao = 1,0747 em média). Usar 1/sim cru infla a
     * probabilidade em ~3,7pp e desloca todos os cortes de perfil.
     *
     * @param array<string, mixed> $row
     */
    public function bttsProbability(array $row): ?float
    {
        $yes = $this->validOdd($row['oddBttsYes'] ?? null);
        $no = $this->validOdd($row['oddBttsNo'] ?? null);
        if ($yes !== null && $no !== null) {
            return (1 / $yes) / (1 / $yes + 1 / $no) * 100;
        }
        if ($yes !== null) {
            // Só o lado "sim": sobra a margem dentro. Os cortes BTTS_RAW_* compensam isso.
            return 1 / $yes * 100;
        }

        // Lado SokkerPRO: previsão do modelo X7, já em pontos percentuais e sem margem.
        return $this->number($row['bttsProbability'] ?? null);
    }

    /**
     * Diz em que escala bttsProbability() devolveu, porque os cortes diferem.
     *
     * @param array<string, mixed> $row
     */
    public function bttsProbabilityIsRaw(array $row): bool
    {
        return $this->validOdd($row['oddBttsYes'] ?? null) !== null
            && $this->validOdd($row['oddBttsNo'] ?? null) === null;
    }

    /** @param array<string, mixed> $row */
    public function matchesProfile(array $row, string $profile): bool
    {
        if ($this->favourite($row) === null) {
            return false;
        }
        $btts = $this->bttsProbability($row);
        if ($btts === null) {
            return false;
        }

        $raw = $this->bttsProbabilityIsRaw($row);

        return match ($profile) {
            'baseline' => $btts >= ($raw ? self::BTTS_RAW_BASELINE : self::BTTS_BASELINE),
            'balanced' => $btts >= ($raw ? self::BTTS_RAW_BALANCED : self::BTTS_BALANCED),
            'strong' => $btts >= ($raw ? self::BTTS_RAW_STRONG : self::BTTS_STRONG)
                && $this->favouriteOdd($row) !== null
                && $this->favouriteOdd($row) < self::FAVOURITE_ODD_STRONG,
            default => false,
        };
    }

    /** Menor das duas odds 1X2. @param array<string, mixed> $row */
    public function favouriteOdd(array $row): ?float
    {
        $home = $this->validOdd($row['oddHome'] ?? null);
        $away = $this->validOdd($row['oddAway'] ?? null);
        if ($home === null || $away === null) {
            return null;
        }

        return min($home, $away);
    }

    /**
     * Verde quando o placar final difere do laydo.
     *
     * @param array<string, mixed> $row
     * @return 'green'|'red'|null
     */
    public function result(array $row, int $targetGoals, string $side = 'favourite'): ?string
    {
        $score = $this->layScore($row, $targetGoals, $side);
        $final = $this->finalScore($row);
        if ($score === null || $final === null) {
            return null;
        }

        return $final === $score ? 'red' : 'green';
    }

    /**
     * Carteira das duas pernas na mesma partida: verde só quando TODAS acertam.
     *
     * Mesma semântica de PunterLayList::getJointAccuracyStatsProperty(). Concentra risco no
     * mesmo jogo, por isso o backtest reporta perna e carteira lado a lado.
     *
     * @param array<string, mixed> $row
     * @return 'green'|'red'|null
     */
    public function portfolioResult(array $row, string $side = 'favourite'): ?string
    {
        $results = [];
        foreach (self::TARGETS as $targetGoals) {
            $result = $this->result($row, $targetGoals, $side);
            if ($result === null) {
                return null;
            }
            $results[] = $result;
        }

        return in_array('red', $results, true) ? 'red' : 'green';
    }

    /**
     * Odd de lay abaixo da qual a entrada compensa, dada a frequência observada do placar.
     *
     * Recebe a frequência em pontos percentuais (ex.: 6,62 para o 1x0 do favorito no corte de
     * BTTS >= 60%) e devolve o inverso (15,1). Laydar abaixo disso é retorno esperado positivo;
     * acima, negativo. É o único substituto possível para ROI, já que não existe odd de mercado
     * de placar exato em nenhuma das duas fontes.
     */
    public static function fairLayOdd(?float $scoreFrequency): ?float
    {
        return $scoreFrequency === null || $scoreFrequency <= 0 ? null : 100 / $scoreFrequency;
    }

    /** Placar final na orientação casa-fora, dos inteiros e não do texto. @param array<string, mixed> $row */
    private function finalScore(array $row): ?string
    {
        $home = $row['homeGoals'] ?? null;
        $away = $row['awayGoals'] ?? null;
        if (! is_numeric($home) || ! is_numeric($away)) {
            return null;
        }

        return ((int) $home).'-'.((int) $away);
    }

    private function validOdd(mixed $value): ?float
    {
        $odd = $this->number($value);

        return $odd !== null && $odd > 1.0 ? $odd : null;
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
