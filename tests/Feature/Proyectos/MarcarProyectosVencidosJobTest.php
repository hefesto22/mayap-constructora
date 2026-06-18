<?php

declare(strict_types=1);

use App\Enums\EstadoProyecto;
use App\Jobs\MarcarProyectosVencidosJob;
use App\Models\Cliente;
use App\Models\Proyecto;
use App\Models\Zona;

beforeEach(function (): void {
    $this->job = new MarcarProyectosVencidosJob;
    $this->zona = Zona::factory()->create(['codigo' => 'SRC']);
    $this->cliente = Cliente::factory()->create();
});

test('marca como vencidos los proyectos enviados con fecha pasada', function (): void {
    Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->enviada()
        ->conFechasVencidas()
        ->count(3)
        ->create();

    $marcados = $this->job->handle();

    expect($marcados)->toBe(3);

    expect(Proyecto::conEstado(EstadoProyecto::Vencida)->count())->toBe(3);
    expect(Proyecto::conEstado(EstadoProyecto::Enviada)->count())->toBe(0);
});

test('NO toca proyectos en otros estados aunque tengan fecha vencida', function (): void {
    Proyecto::factory()->enZona($this->zona)->paraCliente($this->cliente)
        ->conFechasVencidas()->create();  // Borrador
    Proyecto::factory()->enZona($this->zona)->paraCliente($this->cliente)
        ->aprobada()->conFechasVencidas()->create();
    Proyecto::factory()->enZona($this->zona)->paraCliente($this->cliente)
        ->rechazada()->conFechasVencidas()->create();
    Proyecto::factory()->enZona($this->zona)->paraCliente($this->cliente)
        ->vencida()->conFechasVencidas()->create();

    $marcados = $this->job->handle();

    expect($marcados)->toBe(0);

    // Los estados originales se preservan
    expect(Proyecto::conEstado(EstadoProyecto::Borrador)->count())->toBe(1);
    expect(Proyecto::conEstado(EstadoProyecto::Aprobada)->count())->toBe(1);
    expect(Proyecto::conEstado(EstadoProyecto::Rechazada)->count())->toBe(1);
});

test('NO toca proyectos enviados con fecha vigente', function (): void {
    Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->enviada()  // fecha default (hoy + 30 días)
        ->count(2)
        ->create();

    $marcados = $this->job->handle();

    expect($marcados)->toBe(0);
    expect(Proyecto::conEstado(EstadoProyecto::Enviada)->count())->toBe(2);
});

test('es idempotente: segunda ejecución no marca nada más', function (): void {
    Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->enviada()
        ->conFechasVencidas()
        ->count(5)
        ->create();

    $primer = $this->job->handle();
    $segundo = $this->job->handle();

    expect($primer)->toBe(5);
    expect($segundo)->toBe(0);
});

test('combinación de vencidos y vigentes: solo afecta los vencidos', function (): void {
    // 3 enviados vencidos
    Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->enviada()
        ->conFechasVencidas()
        ->count(3)
        ->create();

    // 2 enviados vigentes
    Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->enviada()
        ->count(2)
        ->create();

    $marcados = $this->job->handle();

    expect($marcados)->toBe(3);
    expect(Proyecto::conEstado(EstadoProyecto::Enviada)->count())->toBe(2);
    expect(Proyecto::conEstado(EstadoProyecto::Vencida)->count())->toBe(3);
});
