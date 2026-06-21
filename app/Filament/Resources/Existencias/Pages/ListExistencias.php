<?php

declare(strict_types=1);

namespace App\Filament\Resources\Existencias\Pages;

use App\Filament\Resources\Existencias\ExistenciaResource;
use App\Models\Bodega;
use App\Models\Material;
use App\Models\User;
use App\Services\Inventario\RegistrarMovimientoService;
use App\Services\Inventario\Ubicacion;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListExistencias extends ListRecords
{
    protected static string $resource = ExistenciaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('registrar_entrada')
                ->label('Registrar entrada')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->modalHeading('Registrar entrada de inventario')
                ->modalDescription('Suma stock a una bodega. El costo alimenta el promedio ponderado del material.')
                ->modalSubmitActionLabel('Registrar entrada')
                ->schema([
                    Select::make('material_id')
                        ->label('Material')
                        ->options(fn (): array => Material::query()
                            ->where('activo', true)
                            ->orderBy('nombre')
                            ->get()
                            ->mapWithKeys(fn (Material $material): array => [
                                $material->id => "{$material->codigo} — {$material->nombre}",
                            ])
                            ->all())
                        ->searchable()
                        ->required()
                        ->native(false),

                    Select::make('bodega_id')
                        ->label('Bodega')
                        ->options(function (): array {
                            $query = Bodega::query()->where('activo', true)->orderBy('nombre');

                            // El usuario solo puede registrar entrada en SUS bodegas.
                            $user = auth()->user();

                            if ($user instanceof User) {
                                $query->visibleParaUsuario($user);
                            }

                            return $query->pluck('nombre', 'id')->all();
                        })
                        ->required()
                        ->native(false),

                    TextInput::make('cantidad')
                        ->label('Cantidad')
                        ->numeric()
                        ->required()
                        ->minValue(0.0001)
                        ->step('any'),

                    TextInput::make('costo_unitario')
                        ->label('Costo unitario')
                        ->numeric()
                        ->required()
                        ->minValue(0)
                        ->prefix('L.')
                        ->step('any')
                        ->helperText('Precio de compra por unidad. Define el promedio ponderado.'),
                ])
                ->action(function (array $data): void {
                    $bodegaId = (int) $data['bodega_id'];

                    // Defense in depth (Fase 2): el selector ya limita, pero
                    // revalidamos que el usuario pueda escribir en esa bodega.
                    $user = auth()->user();

                    if ($user instanceof User
                        && ! $user->puedeVerTodasLasBodegas()
                        && ! in_array($bodegaId, $user->bodegasAsignadasIds(), true)
                    ) {
                        Notification::make()
                            ->title('Bodega no autorizada')
                            ->body('No tenés acceso a la bodega seleccionada.')
                            ->danger()
                            ->send();

                        return;
                    }

                    app(RegistrarMovimientoService::class)->entradaCompra(
                        materialId: (int) $data['material_id'],
                        destino: Ubicacion::bodega($bodegaId),
                        cantidad: (string) $data['cantidad'],
                        costoUnitario: (string) $data['costo_unitario'],
                        userId: auth()->id(),
                    );

                    Notification::make()
                        ->title('Entrada registrada')
                        ->body('El stock y el costo promedio se actualizaron.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
