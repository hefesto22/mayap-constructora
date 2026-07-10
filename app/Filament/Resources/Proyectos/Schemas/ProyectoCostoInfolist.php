<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\Schemas;

use App\Enums\NivelPresupuesto;
use App\Filament\Support\CostoObra;
use App\Models\Proyecto;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * Infolist del costo real de una obra. Junta las tres fuentes de costo
 * (materiales, maquinaria, mano de obra) frente al presupuesto de venta y
 * muestra el margen con barras de composición y semáforo de consumo.
 * Lee CostoProyectoService (memoizado vía CostoObra).
 *
 * NOTA DE ESTILOS: el panel de costos se dibuja con HTML inline — el CSS
 * compilado de Filament no incluye clases Tailwind arbitrarias.
 */
class ProyectoCostoInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identificación')
                ->icon('heroicon-o-clipboard-document-list')
                ->columnSpanFull()
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
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('panel_costos')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->state(fn (Proyecto $record): HtmlString => self::renderPanelCostos($record)),
                ]),
        ]);
    }

    private static function renderPanelCostos(Proyecto $record): HtmlString
    {
        $costo = CostoObra::para($record);

        $presupuesto = (float) $costo->presupuesto;
        $total = (float) $costo->costoTotal;
        $margen = (float) $costo->margen;
        $margenPositivo = bccomp($costo->margen, '0', 2) >= 0;
        $colorMargen = $margenPositivo ? '#10b981' : '#ef4444';

        // ── Fila de 3 grandes: Presupuesto / Costo real / Margen ────────
        $grandes = '<div style="display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; margin-bottom:16px;">'
            .self::tarjeta('Presupuesto (venta)', 'L '.number_format($presupuesto, 2))
            .self::tarjeta('Costo real total', 'L '.number_format($total, 2))
            .self::tarjeta('Margen', 'L '.number_format($margen, 2), $colorMargen)
            .'</div>';

        // ── Desglose con proporción de cada fuente sobre el costo total ─
        $fuentes = [
            ['Materiales', (float) $costo->costoMateriales, '#0ea5e9'],
            ['Maquinaria', (float) $costo->costoMaquinaria, '#f59e0b'],
            ['Mano de obra', (float) $costo->costoManoObra, '#a855f7'],
        ];

        $desglose = '<div style="display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; margin-bottom:18px;">';

        foreach ($fuentes as [$nombre, $monto, $color]) {
            $proporcion = $total > 0 ? round($monto / $total * 100, 1) : 0.0;

            $desglose .= '<div style="border:1px solid rgba(128,128,128,0.25); border-radius:10px; padding:12px 16px;">'
                .'<div style="font-size:.7rem; text-transform:uppercase; letter-spacing:.08em; opacity:.55;">'.$nombre.'</div>'
                .'<div style="font-size:1.1rem; font-weight:700; margin:2px 0 8px;">L '.number_format($monto, 2).'</div>'
                .'<div style="display:flex; align-items:center; gap:8px;">'
                .'<div style="flex:1; height:6px; border-radius:9999px; background:rgba(128,128,128,0.2); overflow:hidden;">'
                .'<div style="width:'.min(100, $proporcion).'%; height:100%; border-radius:9999px; background:'.$color.';"></div>'
                .'</div>'
                .'<span style="font-size:.7rem; opacity:.6; min-width:42px; text-align:right;">'.$proporcion.'%</span>'
                .'</div></div>';
        }

        $desglose .= '</div>';

        // ── Barra de consumo del presupuesto con semáforo ────────────────
        $pctConsumido = (float) $costo->porcentajeConsumido;
        $nivel = $costo->nivel();
        $colorNivel = match ($nivel) {
            NivelPresupuesto::Sano        => '#10b981',
            NivelPresupuesto::EnRiesgo    => '#f59e0b',
            NivelPresupuesto::Sobregirado => '#ef4444',
        };

        $consumo = '<div style="border:1px solid rgba(128,128,128,0.25); border-radius:12px; padding:16px 20px;">'
            .'<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">'
            .'<span style="font-size:.8rem; text-transform:uppercase; letter-spacing:.08em; opacity:.55;">Presupuesto consumido</span>'
            .'<span style="font-weight:800; font-size:1.1rem; color:'.$colorNivel.';">'
            .number_format($pctConsumido, 2).'% · '.$nivel->getLabel().'</span>'
            .'</div>'
            .'<div style="width:100%; height:12px; border-radius:9999px; background:rgba(128,128,128,0.2); overflow:hidden;">'
            .'<div style="width:'.min(100.0, $pctConsumido).'%; height:100%; border-radius:9999px; background:'.$colorNivel.';"></div>'
            .'</div>'
            .'<div style="margin-top:10px; font-size:.8rem; opacity:.6;">Margen sobre presupuesto: '
            .'<span style="font-weight:700; color:'.$colorMargen.';">'.$costo->margenPorcentaje.' %</span>'
            .' · La mano de obra suma solo planillas cerradas.</div>'
            .'</div>';

        return new HtmlString($grandes.$desglose.$consumo);
    }

    /**
     * Tarjeta de estadística grande (label pequeño arriba, monto destacado).
     */
    private static function tarjeta(string $label, string $valor, ?string $colorValor = null): string
    {
        $estiloValor = 'font-size:1.5rem; font-weight:800; margin-top:2px;'
            .($colorValor !== null ? ' color:'.$colorValor.';' : '');

        return '<div style="border:1px solid rgba(128,128,128,0.25); border-radius:10px; padding:14px 18px;">'
            .'<div style="font-size:.7rem; text-transform:uppercase; letter-spacing:.08em; opacity:.55;">'.htmlspecialchars($label).'</div>'
            .'<div style="'.$estiloValor.'">'.htmlspecialchars($valor).'</div>'
            .'</div>';
    }
}
