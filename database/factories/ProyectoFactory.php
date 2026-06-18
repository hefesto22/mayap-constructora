<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EstadoProyecto;
use App\Models\Cliente;
use App\Models\Proyecto;
use App\Models\Zona;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Proyecto>
 */
class ProyectoFactory extends Factory
{
    protected $model = Proyecto::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Default: emisión hoy, validez en 30 días. Esto garantiza que
        // los proyectos por defecto NO estén vencidos. Tests que necesiten
        // fechas pasadas usan ->conFechasVencidas() explícitamente.
        $emision = now()->startOfDay();
        $validez = $emision->copy()->addDays(30);

        return [
            'zona_id'             => Zona::factory(),
            'cliente_id'          => Cliente::factory(),
            'nombre'              => 'CASA HABITACION DE 2 NIVELES',
            'descripcion'         => null,
            'direccion_obra'      => $this->faker->address(),
            'fecha_emision'       => $emision,
            'fecha_validez'       => $validez,
            'estado'              => EstadoProyecto::Borrador->value,
            'moneda'              => 'HNL',
            'aplica_isv'          => true,
            'isv_porcentaje'      => 15.00,
            'notas'               => null,
            'subtotal_cache'      => 0,
            'isv_cache'           => 0,
            'total_cache'         => 0,
            'precio_calculado_at' => null,
        ];
    }

    public function enZona(Zona $zona): self
    {
        return $this->state(fn (): array => ['zona_id' => $zona->id]);
    }

    public function paraCliente(Cliente $cliente): self
    {
        return $this->state(fn (): array => ['cliente_id' => $cliente->id]);
    }

    public function conEstado(EstadoProyecto $estado): self
    {
        return $this->state(fn (): array => ['estado' => $estado->value]);
    }

    public function enviada(): self
    {
        return $this->conEstado(EstadoProyecto::Enviada);
    }

    public function aprobada(): self
    {
        return $this->conEstado(EstadoProyecto::Aprobada);
    }

    public function rechazada(): self
    {
        return $this->conEstado(EstadoProyecto::Rechazada);
    }

    public function vencida(): self
    {
        return $this->conEstado(EstadoProyecto::Vencida);
    }

    public function exento(): self
    {
        return $this->state(fn (): array => [
            'aplica_isv'     => false,
            'isv_porcentaje' => 0.00,
        ]);
    }

    /**
     * Fechas pasadas: emitida hace 60 días, validez de hace 30 días.
     * Usado para simular cotizaciones que ya deberían estar vencidas.
     */
    public function conFechasVencidas(): self
    {
        return $this->state(fn (): array => [
            'fecha_emision' => now()->subDays(60),
            'fecha_validez' => now()->subDays(30),
        ]);
    }
}
