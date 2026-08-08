<?php

namespace App\Oracly\Services;

use App\Oracly\Support\OraclyDb;

final class FavoritesService
{
    /**
     * @return array{countries: list<string>, leagues: list<string>}
     */
    public function get(): array
    {
        $countries = OraclyDb::connection()->select(
            'SELECT country FROM '.OraclyDb::table('favorite_countries').' ORDER BY country',
        );
        $leagues = OraclyDb::connection()->select(
            'SELECT country, competition FROM '.OraclyDb::table('favorite_leagues').' ORDER BY country, competition',
        );

        return [
            'countries' => array_map(fn ($row) => (string) $row->country, $countries),
            'leagues' => array_map(fn ($row) => $row->country.'::'.$row->competition, $leagues),
        ];
    }

    /**
     * @return list<array{country: string, competition: string, isTopFlight: bool, division: ?string}>
     */
    public function knownLeagues(): array
    {
        $rows = OraclyDb::connection()->select(
            'SELECT country, competition, is_top_flight AS "isTopFlight", division
             FROM '.OraclyDb::table('leagues').'
             ORDER BY country, competition',
        );

        return array_map(fn ($row) => [
            'country' => (string) $row->country,
            'competition' => (string) $row->competition,
            'isTopFlight' => (bool) $row->isTopFlight,
            'division' => in_array($row->division, ['A', 'B'], true) ? $row->division : null,
        ], $rows);
    }

    public function toggleCountry(string $country): void
    {
        $favorites = $this->get();
        if (in_array($country, $favorites['countries'], true)) {
            $favorites['countries'] = array_values(array_filter(
                $favorites['countries'],
                fn ($c) => $c !== $country,
            ));
        } else {
            $favorites['countries'][] = $country;
        }
        $this->set($favorites);
    }

    public function toggleLeague(string $country, string $competition): void
    {
        $key = $country.'::'.$competition;
        $favorites = $this->get();
        if (in_array($key, $favorites['leagues'], true)) {
            $favorites['leagues'] = array_values(array_filter(
                $favorites['leagues'],
                fn ($l) => $l !== $key,
            ));
        } else {
            $favorites['leagues'][] = $key;
        }
        $this->set($favorites);
    }

    /**
     * @param  array{countries: list<string>, leagues: list<string>}  $favorites
     */
    public function set(array $favorites): void
    {
        $db = OraclyDb::connection();
        $db->transaction(function () use ($db, $favorites) {
            $db->statement('DELETE FROM '.OraclyDb::table('favorite_countries'));
            $db->statement('DELETE FROM '.OraclyDb::table('favorite_leagues'));

            foreach ($favorites['countries'] as $country) {
                $db->insert(
                    'INSERT INTO '.OraclyDb::table('favorite_countries').' (country) VALUES (?) ON CONFLICT DO NOTHING',
                    [$country],
                );
            }

            foreach ($favorites['leagues'] as $key) {
                $parts = explode('::', $key, 2);
                if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                    continue;
                }
                $db->insert(
                    'INSERT INTO '.OraclyDb::table('favorite_leagues').' (country, competition) VALUES (?, ?) ON CONFLICT DO NOTHING',
                    [$parts[0], $parts[1]],
                );
            }
        });
    }
}
