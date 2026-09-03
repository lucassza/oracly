<?php

namespace App\Oracly\Punter;

use RuntimeException;

/**
 * Baixa uma aba do Google Sheets como CSV via endpoint gviz (público, sem OAuth),
 * endereçado por nome de aba — dispensa descobrir o gid. Nunca guarda o conteúdo
 * inteiro em memória: grava direto em arquivo temporário.
 */
final class SheetFetcher
{
    private const MAX_ATTEMPTS = 3;

    private const TIMEOUT_SECONDS = 120;

    /**
     * @return array{path: string, bytes: int, sha256: string}
     */
    public function fetch(string $spreadsheetId, string $sheetName, ?string $range = null): array
    {
        $url = $this->buildUrl($spreadsheetId, $sheetName, $range);
        $directory = storage_path('app/punter');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $path = $directory.'/'.uniqid('sheet_', true).'.csv';

        $lastError = null;
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $handle = fopen($path, 'wb');
            if ($handle === false) {
                throw new RuntimeException("Não foi possível criar arquivo temporário em {$path}");
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE => $handle,
                CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_FAILONERROR => false,
            ]);
            $ok = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            fclose($handle);

            if ($ok !== false && $status === 200 && filesize($path) > 0) {
                return [
                    'path' => $path,
                    'bytes' => filesize($path),
                    'sha256' => hash_file('sha256', $path),
                ];
            }

            $lastError = "HTTP {$status}".($curlError !== '' ? " ({$curlError})" : '');
            if ($attempt < self::MAX_ATTEMPTS) {
                sleep($attempt * 2);
            }
        }

        @unlink($path);
        throw new RuntimeException("Falha ao baixar a aba \"{$sheetName}\" (planilha {$spreadsheetId}): {$lastError}");
    }

    private function buildUrl(string $spreadsheetId, string $sheetName, ?string $range): string
    {
        $params = [
            'tqx' => 'out:csv',
            'sheet' => $sheetName,
        ];
        if ($range !== null) {
            $params['range'] = $range;
        }

        return 'https://docs.google.com/spreadsheets/d/'.$spreadsheetId.'/gviz/tq?'.http_build_query($params);
    }
}
