<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Shipping;
use App\Models\User;
use App\Models\Vehicle;
use Database\Factories\ShippingFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ShippingApiTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usuario = User::factory()->create();
    }

    private function criarMotoristaComVeiculo(): Driver
    {
        $motorista = Driver::factory()->create();
        Vehicle::factory()->doMotorista($motorista)->create();

        return $motorista;
    }

    private function criarShipping(string $valor = '150.00'): TestResponse
    {
        return $this->actingAs($this->usuario, 'sanctum')
            ->postJson('/api/shippings', [
                'origin' => 'Porto Alegre',
                'destination' => 'Canoas',
                'about_load' => 'Paletes de papel',
                'value' => $valor,
            ]);
    }

    private function criarShippingAtribuido(Driver $motorista): Shipping
    {
        return ShippingFactory::new()->doUsuario($this->usuario)->create([
            'status' => 'driver_assigned',
            'driver_id' => $motorista->driver_id,
        ]);
    }

    public function test_usuario_cria_shipping_com_sucesso(): void
    {
        $resposta = $this->criarShipping();

        $resposta->assertStatus(201)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('origin', 'Porto Alegre');

        $this->assertDatabaseHas('shippings', ['origin' => 'Porto Alegre', 'value' => '150.00']);
    }

    public function test_valor_negativo_e_rejeitado(): void
    {
        $this->criarShipping('-10.00')->assertStatus(422);
    }

    public function test_procurar_motoristas_cria_ofertas_e_muda_status(): void
    {
        $this->criarMotoristaComVeiculo();
        $this->criarMotoristaComVeiculo();

        $shipping = ShippingFactory::new()->doUsuario($this->usuario)->create([
            'status' => 'pending',
        ]);

        $this->actingAs($this->usuario, 'sanctum')
            ->postJson("/api/shippings/{$shipping->shipping_id}/procurar-motoristas")
            ->assertOk()
            ->assertJsonPath('shipping.status', 'searching_driver');

        $this->assertDatabaseCount('shipping_drivers', 2);
        $this->assertDatabaseHas('shipping_drivers', ['status' => 'pending']);
    }

    public function test_aceite_registra_driver_e_preserva_historico(): void
    {
        $motoristaA = $this->criarMotoristaComVeiculo();
        $motoristaB = $this->criarMotoristaComVeiculo();

        $shipping = ShippingFactory::new()->doUsuario($this->usuario)->create([
            'status' => 'pending',
        ]);

        $this->actingAs($this->usuario, 'sanctum')
            ->postJson("/api/shippings/{$shipping->shipping_id}/procurar-motoristas");

        // Motorista A aceita.
        $this->actingAs($motoristaA, 'sanctum')
            ->postJson("/api/shippings/{$shipping->shipping_id}/responder", ['status' => 'accepted'])
            ->assertOk()
            ->assertJsonPath('shipping.status', 'driver_assigned');

        // driver_id atualizado + historico com os 2 motoristas preservado.
        $this->assertDatabaseHas('shippings', [
            'shipping_id' => $shipping->shipping_id,
            'driver_id' => $motoristaA->driver_id,
        ]);

        $this->assertDatabaseCount('shipping_drivers', 2);
        $this->assertDatabaseHas('shipping_drivers', [
            'shipping_id' => $shipping->shipping_id,
            'driver_id' => $motoristaA->driver_id,
            'status' => 'accepted',
        ]);
    }

    public function test_motorista_sem_oferta_nao_responde(): void
    {
        $motorista = $this->criarMotoristaComVeiculo();
        $outro = Driver::factory()->create();

        $shipping = ShippingFactory::new()->doUsuario($this->usuario)->create();

        $this->actingAs($outro, 'sanctum')
            ->postJson("/api/shippings/{$shipping->shipping_id}/responder", ['status' => 'accepted'])
            ->assertStatus(404);
    }

    public function test_resposta_duplicada_e_rejeitada(): void
    {
        $motorista = $this->criarMotoristaComVeiculo();
        $shipping = ShippingFactory::new()->doUsuario($this->usuario)->create();

        $this->actingAs($this->usuario, 'sanctum')
            ->postJson("/api/shippings/{$shipping->shipping_id}/procurar-motoristas");

        $this->actingAs($motorista, 'sanctum')
            ->postJson("/api/shippings/{$shipping->shipping_id}/responder", ['status' => 'accepted'])
            ->assertOk();

        $this->actingAs($motorista, 'sanctum')
            ->postJson("/api/shippings/{$shipping->shipping_id}/responder", ['status' => 'rejected'])
            ->assertStatus(422);
    }

    public function test_fluxo_completo_iniciar_e_concluir(): void
    {
        $motorista = $this->criarMotoristaComVeiculo();
        $shipping = ShippingFactory::new()->doUsuario($this->usuario)->create();

        $this->actingAs($this->usuario, 'sanctum')
            ->postJson("/api/shippings/{$shipping->shipping_id}/procurar-motoristas");

        $this->actingAs($motorista, 'sanctum')
            ->postJson("/api/shippings/{$shipping->shipping_id}/responder", ['status' => 'accepted'])
            ->assertOk();

        $this->actingAs($motorista, 'sanctum')
            ->postJson("/api/shippings/{$shipping->shipping_id}/iniciar")
            ->assertOk()
            ->assertJsonPath('status', 'in_transit');

        $this->actingAs($motorista, 'sanctum')
            ->postJson("/api/shippings/{$shipping->shipping_id}/concluir")
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $this->assertNotNull($shipping->fresh()->completed_at);
    }

    public function test_shipping_concluido_nao_reabre(): void
    {
        $motorista = $this->criarMotoristaComVeiculo();
        $shipping = ShippingFactory::new()->doUsuario($this->usuario)->create([
            'status' => 'completed',
            'completed_at' => now(),
            'driver_id' => $motorista->driver_id,
        ]);

        // completed nao permite transicao p/ in_transit nem cancelamento.
        $this->actingAs($motorista, 'sanctum')
            ->postJson("/api/shippings/{$shipping->shipping_id}/iniciar")
            ->assertStatus(422);

        $this->actingAs($this->usuario, 'sanctum')
            ->postJson("/api/shippings/{$shipping->shipping_id}/cancelar")
            ->assertStatus(422);
    }

    public function test_motorista_nao_atribuido_nao_inicia(): void
    {
        $motorista = $this->criarMotoristaComVeiculo();
        $intruso = $this->criarMotoristaComVeiculo();

        $shipping = ShippingFactory::new()->doUsuario($this->usuario)->create();

        $this->actingAs($this->usuario, 'sanctum')
            ->postJson("/api/shippings/{$shipping->shipping_id}/procurar-motoristas");

        $this->actingAs($motorista, 'sanctum')
            ->postJson("/api/shippings/{$shipping->shipping_id}/responder", ['status' => 'accepted']);

        $this->actingAs($intruso, 'sanctum')
            ->postJson("/api/shippings/{$shipping->shipping_id}/iniciar")
            ->assertStatus(403);
    }

    public function test_dono_pode_cancelar_shipping(): void
    {
        $shipping = ShippingFactory::new()->doUsuario($this->usuario)->create([
            'status' => 'pending',
        ]);

        $this->actingAs($this->usuario, 'sanctum')
            ->postJson("/api/shippings/{$shipping->shipping_id}/cancelar")
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');
    }

    public function test_outro_usuario_nao_cancela_shipping_alheio(): void
    {
        $outro = User::factory()->create();
        $shipping = ShippingFactory::new()->doUsuario($outro)->create();

        $this->actingAs($this->usuario, 'sanctum')
            ->postJson("/api/shippings/{$shipping->shipping_id}/cancelar")
            ->assertStatus(403);
    }

    public function test_motorista_inicia_e_para_a_simulacao(): void
    {
        $motorista = $this->criarMotoristaComVeiculo();
        $shipping = $this->criarShippingAtribuido($motorista);

        $this->actingAs($motorista, 'sanctum')
            ->postJson("/api/shippings/{$shipping->shipping_id}/simulacao/iniciar")
            ->assertOk()
            ->assertJsonPath('simulacao_iniciada_em', fn ($valor) => $valor !== null)
            ->assertJsonPath('simulacao_progresso', fn ($valor) => $valor !== null);

        $this->assertNotNull($shipping->fresh()->simulacao_iniciada_em);

        $this->actingAs($motorista, 'sanctum')
            ->postJson("/api/shippings/{$shipping->shipping_id}/simulacao/parar")
            ->assertOk()
            ->assertJsonPath('simulacao_iniciada_em', null)
            ->assertJsonPath('simulacao_progresso', null);

        $this->assertNull($shipping->fresh()->simulacao_iniciada_em);
    }

    public function test_iniciar_simulacao_duplicada_e_rejeitada(): void
    {
        $motorista = $this->criarMotoristaComVeiculo();
        $shipping = $this->criarShippingAtribuido($motorista);

        $this->actingAs($motorista, 'sanctum')
            ->postJson("/api/shippings/{$shipping->shipping_id}/simulacao/iniciar")
            ->assertOk();

        $this->actingAs($motorista, 'sanctum')
            ->postJson("/api/shippings/{$shipping->shipping_id}/simulacao/iniciar")
            ->assertStatus(422);
    }

    public function test_cliente_nao_inicia_simulacao(): void
    {
        $motorista = $this->criarMotoristaComVeiculo();
        $shipping = $this->criarShippingAtribuido($motorista);

        $this->actingAs($this->usuario, 'sanctum')
            ->postJson("/api/shippings/{$shipping->shipping_id}/simulacao/iniciar")
            ->assertStatus(403);
    }

    public function test_motorista_nao_atribuido_nao_inicia_simulacao(): void
    {
        $motorista = $this->criarMotoristaComVeiculo();
        $intruso = $this->criarMotoristaComVeiculo();
        $shipping = $this->criarShippingAtribuido($motorista);

        $this->actingAs($intruso, 'sanctum')
            ->postJson("/api/shippings/{$shipping->shipping_id}/simulacao/iniciar")
            ->assertStatus(403);
    }
}