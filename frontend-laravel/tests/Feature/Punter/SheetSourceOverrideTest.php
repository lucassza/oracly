<?php

namespace Tests\Feature\Punter;

use App\Oracly\Punter\SheetFetcher;
use App\Oracly\Punter\SheetImporter;
use App\Oracly\Support\PunterDb;
use Database\Seeders\PunterSheetSourcesSeeder;
use RuntimeException;
use Tests\TestCase;

/**
 * O link da planilha é editável no admin (punter.sheet_sources): o importador precisa
 * baixar dali, não do config, e re-semear não pode desfazer a troca. Roda contra o
 * schema punter real dentro de uma transação desfeita no tearDown.
 */
class SheetSourceOverrideTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        PunterDb::connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        PunterDb::connection()->rollBack();
        parent::tearDown();
    }

    public function test_importer_downloads_from_the_spreadsheet_saved_in_sheet_sources(): void
    {
        PunterDb::connection()->table('sheet_sources')->where('source_key', 'league_summary')->update([
            'spreadsheet_id' => '__TEST_SPREADSHEET__',
            'sheet_name' => '__TEST_SHEET__',
            'range' => 'A1:B',
        ]);

        $calls = [];
        $this->app->instance(SheetFetcher::class, new class($calls) extends SheetFetcher
        {
            public function __construct(private array &$calls)
            {
            }

            public function fetch(string $spreadsheetId, string $sheetName, ?string $range = null): array
            {
                $this->calls[] = [$spreadsheetId, $sheetName, $range];
                throw new RuntimeException('stop');
            }
        });

        try {
            app(SheetImporter::class)->run('league_summary', force: true, dryRun: true);
        } catch (RuntimeException) {
        }

        $this->assertSame([['__TEST_SPREADSHEET__', '__TEST_SHEET__', 'A1:B']], $calls);
    }

    public function test_reseeding_keeps_a_spreadsheet_link_changed_in_the_admin(): void
    {
        PunterDb::connection()->table('sheet_sources')->where('source_key', 'league_summary')->update([
            'spreadsheet_id' => '__TEST_SPREADSHEET__',
            'spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/__TEST_SPREADSHEET__/edit',
        ]);

        (new PunterSheetSourcesSeeder)->run();

        $row = PunterDb::connection()->table('sheet_sources')->where('source_key', 'league_summary')->first();
        $this->assertSame('__TEST_SPREADSHEET__', $row->spreadsheet_id);
        $this->assertSame(config('punter.sources.league_summary.target'), $row->target);
    }
}
