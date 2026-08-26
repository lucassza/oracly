<?php

namespace App\Oracly\Services;

class DailyLayListService
{
    public function __construct(private readonly DailyCardsService $cards) {}

    /**
     * @return array{date: string, total: int, entries: list<array<string, mixed>>}
     */
    public function forDate(string $date): array
    {
        $entries = [];

        foreach ($this->cards->forDate($date)['groups'] as $group) {
            foreach ($group['cards'] as $card) {
                $fixture = $card;
                unset($fixture['actions']);

                foreach ($card['actions'] as $action) {
                    $entries[] = [
                        ...$fixture,
                        // sourceUrl is supplied by SokkerPro in the collected fixture data.
                        'sokkerproUrl' => $fixture['sourceUrl'] ?? null,
                        'group' => $group['key'],
                        'strategy' => $action,
                    ];
                }
            }
        }

        usort($entries, fn (array $a, array $b): int => strcmp((string) ($a['kickoffAt'] ?? ''), (string) ($b['kickoffAt'] ?? ''))
            ?: ((int) ($a['strategy']['rank'] ?? 99) <=> (int) ($b['strategy']['rank'] ?? 99)));

        return ['date' => $date, 'total' => count($entries), 'entries' => $entries];
    }
}
