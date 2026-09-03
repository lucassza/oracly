<?php

namespace App\Providers\Filament;

use Andreia\FilamentNordTheme\FilamentNordThemePlugin;
use App\Filament\Pages\DailyList;
use App\Oracly\Support\PunterDb;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('Oracly')
            ->homeUrl(fn (): string => DailyList::getUrl())
            ->colors([
                'primary' => Color::Amber,
            ])
            ->defaultThemeMode(ThemeMode::Dark)
            ->plugin(FilamentNordThemePlugin::make())
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->navigationGroups([
                'Operação diária',
                'Mercados',
                'Estratégias',
                'Configuração',
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->renderHook(
                PanelsRenderHook::CONTENT_START,
                fn (): string => view('filament.partials.punter-sync-alert', ['sources' => collect(self::failingPunterSources())])->render(),
            );
    }

    /**
     * Fontes Punter cuja última sincronização falhou — vira o banner vermelho no topo
     * de toda página do painel. Cacheado 60s: a query é barata (10 linhas), mas o hook
     * roda em toda navegação do admin, então evita bater no Postgres a cada clique.
     */
    /** @return list<array{label: string, last_error: ?string}> */
    private static function failingPunterSources(): array
    {
        // Cacheia arrays puros, nunca a Collection/stdClass da query — o cache de
        // arquivo persiste entre processos, e desserializar um objeto sem a classe
        // já carregada quebra ("incomplete object... class definition not loaded").
        return Cache::remember('punter_sync_failing_sources', 60, function (): array {
            try {
                return PunterDb::connection()->table('sheet_sources')
                    ->where('is_active', true)
                    ->where('last_status', 'error')
                    ->orderBy('label')
                    ->get(['label', 'last_error'])
                    ->map(fn ($row): array => ['label' => $row->label, 'last_error' => $row->last_error])
                    ->all();
            } catch (\Throwable) {
                // Postgres do Punter fora do ar não pode derrubar o painel inteiro.
                return [];
            }
        });
    }
}
