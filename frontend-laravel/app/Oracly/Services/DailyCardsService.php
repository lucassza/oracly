<?php

namespace App\Oracly\Services;

use App\Filament\Pages\DailyDecision;

class DailyCardsService
{
    /** @return array{date: string, groups: list<array<string, mixed>>} */
    public function forDate(string $date): array
    {
        $page = app(DailyDecision::class);
        $page->date = $date;
        $page->favoriteLeagues = app(FavoritesService::class)->get()['leagues'];
        $page->historicalHitRates = $page->historicalHitRates();
        $page->cards = $page->buildCards(app(DailyPickService::class)->forDate($date));

        $groups = array_map(function (array $group): array {
            $cards = array_values(array_filter(array_map(function (array $card): ?array {
                $actions = array_values(array_filter(
                    $card['actions'],
                    fn (array $action): bool => str_starts_with((string) ($action['label'] ?? ''), 'Contra '),
                ));

                $actions = array_map(fn (array $action): array => [
                    ...$action,
                    'label' => str_replace('Contra ', 'LAY ', (string) $action['label']),
                    'bet' => str_replace('Contra ', 'LAY ', (string) $action['bet']),
                ], $actions);

                return $actions === [] ? null : [...$card, 'actions' => $actions];
            }, $group['cards'])));

            return [...$group, 'cards' => $cards];
        }, $page->getCardGroupsProperty());

        return ['date' => $date, 'groups' => $groups];
    }
}
