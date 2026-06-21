<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\Schemas;

use App\Filament\Support\CostoObra;
use App\Models\Proyecto;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Infolist del costo real de una obra. Junta las tres fuentes de costo
 * (materiales, maquinaria, mano de obra) frente al presupuesto de venta y
 * muestra el margen, coloreado según rentabilidad. Lee CostoProyectoService
 * (memoizado vía CostoObra).
 */
class ProyectoCostoInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identificación')
                ->icon('heroicon-o-clipboard-document-list')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('codigo')->label('Código')->weight('bold')->copyable(),
                        TextEntry::make('cliente.nombre')->label('Cliente'),
                        TextEntry::make('nombre')->label('Proyecto'),
                    ]),
                ]),

            Section::make('Costo real vs. presupuesto')
                ->icon('heroicon-o-calculator')
                ->description('Costo acumulado de la obra frente a lo presupuestado (venta sin ISV).')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('presupuesto')
                            ->label('Presupuesto (venta)')
                            ->money('HNL')
                            ->weight('bold')
                            ->state(fn (Proyecto $record): string => CostoObra::para($record)->presupuesto),

                        TextEntry::make('costo_total')
                            ->label('Costo real total')
                            ->money('HNL')
                            ->weight('bold')
                            ->state(fn (Proyecto $record): string => CostoObra::para($record)->costoTotal),

                        TextEntry::make('margen')
                            ->label('Margen')
                            ->money('HNL')
                            ->weight('bold')
                            ->color(fn (Proyecto $record): string => self::colorMargen(CostoObra::para($record)->margen))
                            ->state(fn (Proyecto $record): string => CostoObra::para($record)->margen),
                    ]),

                    Grid::make(3)->schema([
                        TextEntry::make('costo_materiales')
                            ->label('Materiales')
                            ->money('HNL')
                            ->state(fn (Proyecto $record): string => CostoObra::para($record)->costoMateriales),

                        TextEntry::make('costo_maquinaria')
                            ->label('Maquinaria')
                            ->money('HNL')
                            ->state(fn (Proyecto $record): string => CostoObra::para($record)->costoMaquinaria),

                        TextEntry::make('costo_mano_obra')
                            ->label('Mano de obra')
                            ->money('HNL')
                            ->helperText('Pendiente del módulo de planilla.')
                            ->state(fn (Proyecto $record): string => CostoObra::para($record)->costoManoObra),
                    ]),

                    TextEntry::make('margen_porcentaje')
                        ->label('Margen sobre presupuesto')
                        ->badge()
                        ->color(fn (Proyecto $record): string => self::colorMargen(CostoObra::para($record)->margen))
                        ->state(fn (Proyecto $record): string => CostoObra::para($record)->margenPorcentaje.' %'),
                ]),
        ]);
    }

    private static function colorMargen(string $margen): string
    {
        return bccomp($margen, '0', 2) >= 0 ? 'success' : 'danger';
    }
}
