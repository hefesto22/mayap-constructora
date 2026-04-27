<?php

declare(strict_types=1);

namespace App\Filament\Resources\Zonas\Pages;

use App\Filament\Concerns\NotificaResultadoClonado;
use App\Filament\Resources\Zonas\ZonaResource;
use App\Models\Zona;
use App\Services\Catalogos\ClonarItemsEntreZonas;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\EditRecord;

class EditZona extends EditRecord
{
    use NotificaResultadoClonado;

    protected static string $resource = ZonaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('clonar_items')
                ->label('Clonar items desde otra zona')
                ->icon('heroicon-o-document-duplicate')
                ->color('info')
                ->modalHeading('Clonar items desde otra zona')
                ->modalDescription(
                    'Copia los items activos de otra zona como punto de partida para esta. '
                    .'Los items clonados son independientes — editarlos aquí no afecta la zona origen.'
                )
                ->modalSubmitActionLabel('Clonar items')
                ->form([
                    Select::make('zona_origen_id')
                        ->label('Zona origen')
                        ->options(fn (): array => Zona::activas()
                            ->where('id', '!=', $this->record->getKey())
                            ->withCount(['items' => fn ($q) => $q->where('activo', true)])
                            ->orderBy('nombre')
                            ->get()
                            ->mapWithKeys(fn (Zona $z): array => [
                                $z->id => "{$z->nombre} ({$z->items_count} items)",
                            ])
                            ->all())
                        ->required()
                        ->searchable()
                        ->preload()
                        ->prefixIcon('heroicon-o-map-pin'),

                    Toggle::make('saltar_duplicados')
                        ->label('Saltar items que ya existan')
                        ->default(true)
                        ->onColor('success')
                        ->offColor('warning')
                        ->helperText(
                            'Si está activo (recomendado): los items que ya existan en esta zona '
                            .'(mismo nombre + categoría) NO se duplicarán. '
                            .'Si lo desactivas, podrías terminar con items duplicados.'
                        ),
                ])
                ->action(function (array $data): void {
                    /** @var Zona $origen */
                    $origen = Zona::findOrFail((int) $data['zona_origen_id']);
                    /** @var Zona $destino */
                    $destino = $this->record;

                    $resultado = app(ClonarItemsEntreZonas::class)->ejecutar(
                        origen: $origen,
                        destino: $destino,
                        saltarDuplicados: (bool) ($data['saltar_duplicados'] ?? true),
                    );

                    $this->notificarResultadoClonado($origen, $destino, $resultado);
                }),

            DeleteAction::make(),
        ];
    }
}
