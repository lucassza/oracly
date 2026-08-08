<?php

namespace App\Oracly\Support;

final class LeagueFilter
{
    /** @var list<string> */
    private const EXPLICIT_EXCLUDED = ['damallsvenskan', 'nwsl'];

    /** @var list<string> */
    private const EXCLUDE_PATTERNS = [
        '/\bu-?1[6-9]\b/i',
        '/\bu-?2[0-3]\b/i',
        '/\byouth\b/i',
        '/\bjunior\b/i',
        '/\bcotif\b/i',
        '/\bwomen\'?s?\b/i',
        '/\b(feminin|femenin)[oa]\b/i',
        '/\bladies\b/i',
        '/\bfriendl(y|ies)\b/i',
        '/\bamateur\b/i',
        '/\breserves?\b/i',
        '/\bregionalliga\b/i',
        '/\boberliga\b/i',
        '/\bnpl\b/i',
        '/\bregional leagues?\b/i',
        '/\bcounties leagues?\b/i',
        '/\bstate league\b/i',
        '/\b(third|fourth|fifth)\s+division\b/i',
        '/\b[3-9]\.\s?(division|liga|liiga|lyga|deild)\b/i',
        '/\bkolmonen\b/i',
    ];

    public static function isMainLeague(?string $competition): bool
    {
        if ($competition === null || $competition === '') {
            return true;
        }

        $normalized = mb_strtolower(trim($competition));
        if (in_array($normalized, self::EXPLICIT_EXCLUDED, true)) {
            return false;
        }

        foreach (self::EXCLUDE_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return false;
            }
        }

        return true;
    }
}
