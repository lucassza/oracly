<?php

namespace Tests\Feature\Punter;

use App\Oracly\Punter\LaySignalsImporter;
use App\Oracly\Support\PunterDb;
use Tests\TestCase;

/**
 * Integração real com punter.lay_signals (schema punter, mesmo Postgres do SokkerPRO —
 * RefreshDatabase só cobre o sqlite padrão). Usa uma match_key exclusiva de teste e
 * limpa a linha no tearDown para não poluir os dados importados de verdade.
 */
class LaySignalsImporterTest extends TestCase
{
    private const TEST_MATCH_KEY = '01.01.2000 00:00:00 __TEST_HOME__ x __TEST_AWAY__';

    protected function tearDown(): void
    {
        PunterDb::connection()->table('lay_signals')->where('match_key', self::TEST_MATCH_KEY)->delete();
        parent::tearDown();
    }

    public function test_atuais_creates_a_pending_row_and_historico_settles_it_without_losing_the_link(): void
    {
        $importer = app(LaySignalsImporter::class);
        $atuaisSource = config('punter.sources.lay_signals_atuais');
        $historicoSource = config('punter.sources.lay_signals_historico');

        $atuaisCsv = $this->writeCsv($atuaisSource['expected_header'], [
            '01.01.2000 00:00:00', 'TESTLAND', 'Test League', '__TEST_HOME__', '__TEST_AWAY__',
            '1,50', '4,00', '5,50', 'https://example.test/match', 'Tendência de Lay 2x2', self::TEST_MATCH_KEY,
        ]);

        $importer->import('lay_signals_atuais', $atuaisSource, $atuaisCsv, dryRun: false);
        unlink($atuaisCsv);

        $pending = PunterDb::connection()->table('lay_signals')->where('match_key', self::TEST_MATCH_KEY)->first();
        $this->assertNotNull($pending);
        $this->assertSame('lay_2x2', $pending->radar);
        $this->assertSame('https://example.test/match', $pending->flashscore_url);
        $this->assertFalse((bool) $pending->settled);
        $this->assertNull($pending->check_result);

        $historicoCsv = $this->writeCsv($historicoSource['expected_header'], [
            '01.01.2000 00:00:00', 'TESTLAND', 'Test League', '__TEST_HOME__', '__TEST_AWAY__',
            '1,50', '4,00', '5,50', 'Tendência de Lay 2x2', '2 x 0', '✔', '1 x 0',
            self::TEST_MATCH_KEY, '0', '0', '0', '0', '0', '1', '0', 'Leitura',
        ]);

        $importer->import('lay_signals_historico', $historicoSource, $historicoCsv, dryRun: false);
        unlink($historicoCsv);

        $settled = PunterDb::connection()->table('lay_signals')->where('match_key', self::TEST_MATCH_KEY)->first();
        $this->assertSame('green', $settled->check_result);
        $this->assertTrue((bool) $settled->settled);
        $this->assertSame(2, $settled->ft_home);
        $this->assertSame(0, $settled->ft_away);
        $this->assertSame('Leitura', $settled->diagnosis);
        // O merge não pode apagar o que só a lista "atuais" trouxe.
        $this->assertSame('https://example.test/match', $settled->flashscore_url);
    }

    /**
     * @param  list<string>  $header
     * @param  list<string>  $row
     */
    private function writeCsv(array $header, array $row): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lay_signals_test_').'.csv';
        $handle = fopen($path, 'w');
        fputcsv($handle, $header);
        fputcsv($handle, $row);
        fclose($handle);

        return $path;
    }
}
