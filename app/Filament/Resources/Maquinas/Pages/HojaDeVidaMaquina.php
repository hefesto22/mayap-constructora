<?php

declare(strict_types=1);

namespace App\Filament\Resources\Maquinas\Pages;

use App\Filament\Resources\Maquinas\MaquinaResource;
use App\Models\Maquina;
use App\Services\Maquinaria\HojaDeVidaMaquinaService;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

/**
 * Hoja de vida de la máquina (G5): el expediente completo — identificación,
 * rentabilidad (ingresos por partes − combustible), historial de obras y
 * mantenimientos. Acceso: el mismo permiso de ver Máquinas.
 */
class HojaDeVidaMaquina extends Page
{
    use InteractsWithRecord;

    protected static string $resource = MaquinaResource::class;

    protected string $view = 'filament.resources.maquinas.hoja-de-vida';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string
    {
        $maquina = $this->record;

        return $maquina instanceof Maquina
            ? "Hoja de vida — {$maquina->nombre}"
            : 'Hoja de vida';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var Maquina $maquina */
        $maquina = $this->record;

        $servicio = app(HojaDeVidaMaquinaService::class);

        return [
            'maquina'        => $maquina,
            'resumen'        => $servicio->resumen($maquina),
            'asignaciones'   => $servicio->asignacionesConTotales($maquina),
            'mantenimientos' => $servicio->mantenimientos($maquina),
        ];
    }
}
