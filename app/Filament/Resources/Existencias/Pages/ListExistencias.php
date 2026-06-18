<?php

declare(strict_types=1);

namespace App\Filament\Resources\Existencias\Pages;

use App\Filament\Resources\Existencias\ExistenciaResource;
use App\Models\Bodega;
use App\Models\Item;
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
                ->modalDescription('Suma stock a una bodega. El costo alimenta el promedio ponderado del item.')
                ->modalSubmitActionLabel('Registrar entrada')
                ->schema([
                    Select::make('item_id')
                        ->label('Item')
                        ->options(fn (): array => Item::query()
                            ->where('activo', true)
                            ->orderBy('nombre')
                            ->get()
                            ->mapWithKeys(fn (Item $item): array => [
                                $item->id => "{$item->codigo} — {$item->nombre}",
                            ])
                            ->all())
                        ->searchable()
                        ->required()
                        ->native(false),

                    Select::make('bodega_id')
                        ->label('Bodega')
                        ->options(fn (): array => Bodega::query()
                            ->where('activo', true)
                            ->orderBy('nombre')
                            ->pluck('nombre', 'id')
                            ->all())
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
                    app(RegistrarMovimientoService::class)->entradaCompra(
                        itemId: (int) $data['item_id'],
                        destino: Ubicacion::bodega((int) $data['bodega_id']),
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
