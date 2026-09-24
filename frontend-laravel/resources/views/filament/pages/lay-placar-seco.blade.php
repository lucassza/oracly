<x-filament-panels::page>
    <style>
        .oracly-scroll-x { overflow-x: auto; -webkit-overflow-scrolling: touch; }

        .oracly-hour-filter { display: flex; flex-wrap: wrap; gap: .375rem; margin: .5rem 0; }
        .oracly-hour-filter__button {
            background: #f3f4f6; color: #374151;
            border-radius: 9999px; padding: .25rem .75rem; font-size: .8125rem; font-weight: 600;
        }
        .oracly-hour-filter__button.is-active { background: #fbbf24; color: #111827; }
        .dark .oracly-hour-filter__button { background: #334155 !important; color: #f8fafc !important; }
        .dark .oracly-hour-filter__button.is-active { background: #fbbf24 !important; color: #111827 !important; }

        /* ---------- Veredito de preço: o centro da página ---------- */
        .oracly-verdict {
            border: 1px solid #e5e7eb; border-radius: .75rem; padding: 1rem; margin: .75rem 0;
            background: #fffbeb;
        }
        .dark .oracly-verdict { border-color: #475569; background: #1e293b; }
        .oracly-verdict__row { display: flex; flex-wrap: wrap; align-items: center; gap: 1rem; margin-top: .75rem; }
        .oracly-verdict__field { display: flex; flex-direction: column; gap: .25rem; font-size: .8125rem; font-weight: 600; }
        .oracly-verdict__field input { min-width: 10rem; }
        .oracly-verdict__answer {
            display: flex; flex-direction: column; gap: .125rem;
            border-radius: .5rem; padding: .5rem .875rem; font-size: .8125rem;
        }
        .oracly-verdict__answer strong { font-size: 1rem; }
        .oracly-verdict__answer--enter { background: #d1fae5; color: #065f46; }
        .oracly-verdict__answer--skip { background: #fee2e2; color: #991b1b; }
        .dark .oracly-verdict__answer--enter { background: rgba(16,185,129,.2); color: #a7f3d0; }
        .dark .oracly-verdict__answer--skip { background: rgba(239,68,68,.2); color: #fecaca; }
        .oracly-verdict__hint, .oracly-verdict__note, .oracly-note {
            font-size: .8125rem; color: #6b7280; margin-top: .5rem;
        }
        .dark .oracly-verdict__hint, .dark .oracly-verdict__note, .dark .oracly-note { color: #94a3b8; }

        /* ---------- Selo de estratégia ---------- */
        .oracly-strategy {
            display: inline-flex; align-items: center; white-space: nowrap;
            border-radius: 9999px; padding: .125rem .5rem; font-size: .6875rem; font-weight: 700;
        }
        .oracly-strategy--favourite { background: #fef3c7; color: #92400e; }
        .oracly-strategy--underdog { background: #e0e7ff; color: #3730a3; }
        .dark .oracly-strategy--favourite { background: rgba(251,191,36,.2); color: #fde68a; }
        .dark .oracly-strategy--underdog { background: rgba(99,102,241,.25); color: #c7d2fe; }

        .oracly-recommended {
            display: inline-flex; align-items: center; white-space: nowrap;
            border-radius: 9999px; padding: .0625rem .4375rem; margin-bottom: .1875rem;
            font-size: .625rem; font-weight: 800; letter-spacing: .02em;
            background: #065f46; color: #ecfdf5; cursor: help;
        }
        .dark .oracly-recommended { background: #34d399; color: #052e2b; }
        .oracly-recommended--mobile { display: none; }
        .oracly-picks__leg.is-recommended,
        .oracly-picks .c-pick.is-recommended { background: rgba(16,185,129,.07); }
        .dark .oracly-picks__leg.is-recommended,
        .dark .oracly-picks .c-pick.is-recommended { background: rgba(52,211,153,.1); }

        /* ---------- Copiar nome do time ---------- */
        .oracly-team-pair { display: inline-flex; flex-wrap: wrap; align-items: center; gap: .25rem; font-weight: 600; }
        .oracly-team-pair__team { display: inline-flex; align-items: center; gap: .1875rem; }
        .oracly-team-pair__vs { opacity: .45; }
        .oracly-copy {
            display: inline-flex; align-items: center; justify-content: center;
            width: 1.25rem; height: 1.25rem; border-radius: .25rem; color: #9ca3af; transition: .15s;
        }
        .oracly-copy:hover { background: #e5e7eb; color: #374151; }
        .dark .oracly-copy:hover { background: rgba(255,255,255,.1); color: #e2e8f0; }
        .oracly-star { opacity: .7; }

        /* ---------- Tabela de picks: uma linha por jogo ---------- */
        .oracly-picks { width: 100%; }
        .oracly-picks td { vertical-align: top; }
        .oracly-picks small { display: block; font-size: .6875rem; color: #6b7280; font-weight: 400; }
        .dark .oracly-picks small { color: #94a3b8; }
        .oracly-picks .c-when strong { font-size: 1rem; }
        .oracly-picks .c-fav, .oracly-picks .c-btts, .oracly-picks .c-final { white-space: nowrap; }
        .oracly-picks__leg { text-align: center; min-width: 9rem; }
        .oracly-picks__leg small { margin-top: .1875rem; }
        .oracly-picks .c-pick { text-align: center; white-space: nowrap; }
        .oracly-picks .c-pick strong { font-size: 1rem; letter-spacing: .02em; }

        /* ---------- Celular: cada jogo vira um cartão ---------- */
        @media (max-width: 820px) {
            .oracly-picks, .oracly-picks tbody, .oracly-picks tr, .oracly-picks td { display: block; width: auto; }
            .oracly-picks thead { display: none; }

            .oracly-picks tr {
                border: 1px solid #e5e7eb; border-radius: .75rem;
                margin-top: .75rem; padding: .75rem;
            }
            .dark .oracly-picks tr { border-color: #475569; }
            .oracly-picks td { border: 0; padding: .125rem 0; text-align: left; }

            .oracly-picks .c-fav, .oracly-picks .c-btts, .oracly-picks .c-final,
            .oracly-picks .c-pick {
                display: inline-block; margin-right: 1rem; white-space: normal;
            }
            .oracly-picks .c-fav small, .oracly-picks .c-btts small,
            .oracly-picks .c-pick small { display: inline; }

            .oracly-picks .c-fav::before, .oracly-picks .c-btts::before,
            .oracly-picks .c-final::before, .oracly-picks .c-pick::before {
                content: attr(data-label); display: block;
                font-size: .625rem; text-transform: uppercase; letter-spacing: .04em;
                opacity: .6; font-weight: 700;
            }
            .oracly-picks .c-pick { display: block; margin: .375rem 0 0; }
            .oracly-recommended--mobile { display: inline-flex; }
            .oracly-picks .c-pick.is-recommended {
                border-radius: .5rem; padding: .375rem .5rem; margin-left: -.5rem; margin-right: -.5rem;
            }
            .oracly-picks .c-pick::before { margin-bottom: .0625rem; }

            .oracly-verdict__row { flex-direction: column; align-items: stretch; gap: .625rem; }
            .oracly-verdict__field input { min-width: 0; width: 100%; }
            .oracly-hour-filter { overflow-x: auto; flex-wrap: nowrap; padding-bottom: .25rem; }
            .oracly-hour-filter__button { flex: 0 0 auto; }
        }
    </style>
    <x-oracly.page-header eyebrow="Punter · 43.824 partidas apuradas">
        {{ static::$title }}
        <x-slot name="description">{{ $this->sideDescription }}</x-slot>
    </x-oracly.page-header>

    <x-oracly.chip-group :options="$this::MODE_OPTIONS" :active="$mode" method="setMode" />
    <x-oracly.chip-group :options="$this::SIGNAL_PROFILES" :active="$signalProfile" method="setSignalProfile" />
    <x-oracly.chip-group :options="$this->legFilterOptions" :active="$legFilter" method="setLegFilter" />
    @if ($this->usesFavouriteOddFilter())
        <x-oracly.chip-group :options="$this::FAVOURITE_ODD_OPTIONS" :active="$favouriteOddFilter" method="setFavouriteOddFilter" />
        <p class="oracly-note">
            Favorito forte significa zebra vencendo menos, então o placar laydo fica mais raro.
            Medido: com favorito abaixo de 1,90 a perna de 2 a 0 vai de 95,91% para 97,97%, e
            segura na validação. Este corte não existe na tela do favorito porque lá ele é ruído.
        </p>
    @endif
    <p class="oracly-note">
        <strong>⭐ {{ $this->legOptions[\App\Oracly\Services\AgainstFavouriteCleanSheetStrategy::recommendedLegForSide($this->usesFavouriteOddFilter() ? 'underdog' : 'favourite')] }}</strong>
        {{ $this->recommendationNote }}
    </p>

    @php($legs = $legFilter === 'all' ? array_keys($this->legOptions) : [$legFilter])

    <div class="oracly-scroll-x">
    <table class="oracly-table oracly-picks">
        <thead>
            <tr>
                <th>{{ $mode === 'history' ? 'Data' : 'Horário' }}</th>
                <th>Jogo</th>
                <th>Favorito</th>
                <th>Ambas<br>marcam</th>
                @if ($mode === 'history')
                    <th>Placar<br>final</th>
                @endif
                @foreach ($legs as $leg)
                    @php($recommended = $leg === \App\Oracly\Services\AgainstFavouriteCleanSheetStrategy::recommendedLegForSide($this->usesFavouriteOddFilter() ? 'underdog' : 'favourite'))
                    <th @class(['oracly-picks__leg', 'is-recommended' => $recommended])>
                        @if ($recommended)
                            <span class="oracly-recommended" title="{{ \App\Oracly\Services\AgainstFavouriteCleanSheetStrategy::RECOMMENDED_REASON }}">⭐ Recomendada</span>
                        @endif
                        <span class="oracly-strategy oracly-strategy--{{ \App\Oracly\Services\AgainstFavouriteCleanSheetStrategy::LEGS[$leg]['side'] }}">{{ $this->legOptions[$leg] }}</span>
                        <small>odd justa {{ ($this->fairOdds[$leg] ?? null) === null ? '—' : number_format($this->fairOdds[$leg], 2) }}</small>
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($this->groupedRows as $group)
                @php($match = $group['match'])
                @php($byLeg = collect($group['legs'])->keyBy('leg'))
                <tr>
                    <td class="c-when">
                        @if ($mode === 'history')
                            {{ $match['matchDate'] ?? '—' }}
                        @else
                            <strong>{{ \App\Oracly\Support\BrasiliaDate::timeFromKickoff($match['kickoffAt'] ?? null) ?? '—' }}</strong>
                            <x-oracly.opportunity-rank-badge :rank="$match['opportunityRank'] ?? null" />
                        @endif
                    </td>
                    <td class="c-match">
                        <x-oracly.team-pair :home="$match['homeTeam'] ?? ''" :away="$match['awayTeam'] ?? ''" />
                        <small class="c-league">
                            <button type="button" wire:click="toggleLeague('{{ $match['country'] ?? '' }}', '{{ $match['competition'] ?? '' }}')" class="oracly-star">
                                {{ in_array(($match['country'] ?? '').'::'.($match['competition'] ?? ''), $favoriteLeagues, true) ? '★' : '☆' }}
                            </button>
                            {{ $match['competition'] ?? '—' }}
                        </small>
                    </td>
                    <td class="c-fav" data-label="Favorito">
                        {{ ($match['favouriteSide'] ?? null) === 'home' ? ($match['homeTeam'] ?? '') : ($match['awayTeam'] ?? '') }}
                        <small>{{ ($match['favouriteSide'] ?? null) === 'home' ? 'em casa' : 'fora' }}@if ($match['favouriteOdd'] ?? null) · odd {{ number_format($match['favouriteOdd'], 2) }}@endif</small>
                    </td>
                    <td class="c-btts" data-label="Ambas marcam">
                        {{ $match['bttsProbability'] === null ? '—' : number_format($match['bttsProbability'], 1).'%' }}
                        @if ($match['bttsIsRaw'] ?? false)
                            <small title="Odd de abertura, com a margem da casa dentro">cru</small>
                        @endif
                    </td>
                    @if ($mode === 'history')
                        <td class="c-final" data-label="Placar final">{{ $match['homeGoals'] ?? '—' }}-{{ $match['awayGoals'] ?? '—' }}</td>
                    @endif
                    @foreach ($legs as $leg)
                        @php($row = $byLeg[$leg] ?? null)
                        @php($recommended = $leg === \App\Oracly\Services\AgainstFavouriteCleanSheetStrategy::recommendedLegForSide($this->usesFavouriteOddFilter() ? 'underdog' : 'favourite'))
                        <td @class(['c-pick', 'is-recommended' => $recommended]) data-label="{{ $this->legOptions[$leg] }}">
                            @if ($recommended)
                                <span class="oracly-recommended oracly-recommended--mobile">⭐ Recomendada</span>
                            @endif
                            @if ($row)
                                <strong>{{ $row['legScore'] }}</strong>
                                <small>{{ $row['legWinner'] }} a zero</small>
                                @if ($mode === 'history')
                                    <x-oracly.result-badge :hit="($row['legResult'] ?? null) === null ? null : $row['legResult'] === 'green'" />
                                @endif
                            @else
                                —
                            @endif
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ ($mode === 'history' ? 5 : 4) + count($legs) }}">
                        Nenhum jogo neste perfil.
                        @if ($mode === 'upcoming')
                            A lista do dia vem de panel_fixtures (Punter) e cobre cerca de uma semana à frente.
                        @endif
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
    </div>

    <p class="oracly-note">
        Cada célula de estratégia traz o placar na ordem casa x fora e, abaixo, quem vence a
        zero. Quando o favorito joga fora, ele vencer por 1 a 0 é 0-1 na tabela. A odd justa
        fica no cabeçalho porque é da estratégia, não da partida: vale para o conjunto de jogos
        que passam no perfil.
    </p>
    <p class="oracly-note">
        Os dois placares são mutuamente exclusivos, então laydando as duas pernas do mesmo jogo
        você só pode perder uma: a responsabilidade é a maior das duas, não a soma. O par acerta
        {{ $this->usesFavouriteOddFilter() ? '91,2%' : '87,2%' }} com odd justa de
        {{ $this->usesFavouriteOddFilter() ? '11,41' : '7,78' }}, contra
        {{ $this->usesFavouriteOddFilter() ? '29,42' : '15,17' }} da perna recomendada sozinha.
        Preço mais acessível e metade da banca imobilizada por unidade ganha, em troca de errar
        com mais frequência.
    </p>
    <p class="oracly-note">
        As pernas do azarão acertam mais e pagam pior: 94,6% com justa 18,63 no 1x0 e 96,6% com
        justa 29,42 no 2x0, contra 93,4% e justa 15,17 no 1x0 do favorito. Ao preço justo as
        quatro empatam. Quem decide é o desconto que a exchange oferece.
    </p>
</x-filament-panels::page>
