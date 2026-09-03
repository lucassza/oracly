<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SheetSourceResourcePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_lista_fontes_punter_carrega_para_usuario_autenticado(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/sheet-sources')
            ->assertOk()
            ->assertSee('Fontes Punter');
    }
}
