{{--
    Hoja de Costos por Actividad (APU) — render profesional para el modal "Ver".

    Recibe:
      - $ficha : App\Models\Ficha (con lineas.item.unidadMedida ya cargadas por el service)
      - $r     : App\Services\Fichas\ResultadoCalculoFicha (cálculo en vivo)

    Diseño: documento blanco compacto tipo "papel", optimizado para leerse en
    teléfono / iPad además de escritorio. La tabla scrollea horizontal en
    pantallas angostas (no rompe columnas). Todo el CSS está namespaceado bajo
    .apu-doc para no filtrar al panel, y el botón Imprimir clona documento +
    estilos a una ventana nueva (impresión fiel sin pelear con el modal).
--}}
@php
    use App\Enums\CategoriaItem;

    /** Formatea un monto a lempiras: 2604.37 -> "2,604.37". */
    $L = static fn ($v): string => number_format((float) $v, 2, '.', ',');

    /** Rendimiento a 3 decimales como el Excel del cliente (0.893, 3.444). */
    $rend = static fn ($v): string => $v === '' || $v === null ? '' : number_format((float) $v, 3, '.', ',');

    /** Desperdicio sin decimales sobrantes: 5.00 -> "5", 12.50 -> "12.5". */
    $desp = static function ($v): string {
        if ($v === null || $v === '') {
            return '';
        }
        $s = number_format((float) $v, 2, '.', '');
        return rtrim(rtrim($s, '0'), '.');
    };

    /** Porcentaje limpio: 25.00 -> "25". */
    $pct = static fn ($v): string => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');

    // Secciones en el orden canónico del APU hondureño.
    $secciones = [
        ['cat' => CategoriaItem::Materiales,        'titulo' => 'MATERIALES'],
        ['cat' => CategoriaItem::ManoObra,          'titulo' => 'MANO DE OBRA'],
        ['cat' => CategoriaItem::HerramientaEquipo, 'titulo' => 'HERRAMIENTA Y EQUIPO'],
        ['cat' => CategoriaItem::Indirectos,        'titulo' => 'INDIRECTOS'],
    ];

    // Mapa lineaId => desperdicio (metadato informativo, no vive en el DTO).
    $desperdicios = $ficha->lineas->pluck('desperdicio_porcentaje', 'id');

    $sheetId = 'apu-sheet-' . $ficha->id;
    $styleId = 'apu-style-' . $ficha->id;
@endphp

<style id="{{ $styleId }}">
    .apu-doc {
        --apu-ink: #0f172a;
        --apu-band: #1e293b;
        --apu-line: #cbd5e1;
        --apu-soft: #f1f5f9;
        --apu-accent: #f59e0b;
        background: #ffffff;
        color: var(--apu-ink);
        font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif;
        font-size: 11px;
        line-height: 1.3;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 8px 30px rgba(2, 6, 23, .22);
        max-width: 600px;
        margin: 0 auto;
        border: 1px solid var(--apu-line);
    }
    .apu-doc * { box-sizing: border-box; }

    /* Encabezado */
    .apu-head {
        background: var(--apu-band);
        color: #fff;
        padding: 10px 14px 9px;
        border-bottom: 3px solid var(--apu-accent);
    }
    .apu-head .apu-kicker {
        font-size: 8.5px; letter-spacing: .16em; text-transform: uppercase;
        color: var(--apu-accent); font-weight: 700; margin: 0 0 3px;
    }
    .apu-head h1 {
        font-size: 13px; font-weight: 800; margin: 0; line-height: 1.25;
        text-transform: uppercase;
    }
    .apu-head .apu-code {
        display: inline-block; margin-top: 6px; font-size: 10px; font-weight: 700;
        background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.22);
        padding: 1px 7px; border-radius: 5px; letter-spacing: .04em;
    }
    .apu-head .apu-zone {
        display: inline-block; margin-top: 6px; margin-left: 5px; font-size: 10px;
        color: #cbd5e1;
    }

    /* Meta (unidad + parámetros técnicos) */
    .apu-meta {
        display: flex; flex-wrap: wrap;
        border-bottom: 1px solid var(--apu-line);
        background: var(--apu-soft);
    }
    .apu-meta .cell {
        flex: 1 1 33%; min-width: 120px; padding: 5px 12px;
        border-right: 1px solid var(--apu-line);
    }
    .apu-meta .cell:last-child { border-right: 0; }
    .apu-meta .k { font-size: 8px; text-transform: uppercase; letter-spacing: .07em; color: #64748b; font-weight: 700; }
    .apu-meta .v { font-size: 11.5px; font-weight: 700; color: var(--apu-ink); }

    /* Scroll horizontal en pantallas angostas (teléfono) */
    .apu-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }

    /* Tabla */
    .apu-table { width: 100%; min-width: 480px; border-collapse: collapse; }
    .apu-table thead th {
        background: #334155; color: #fff; font-size: 8.5px; font-weight: 700;
        text-transform: uppercase; letter-spacing: .04em; padding: 5px 8px;
        text-align: right; border-right: 1px solid #475569;
    }
    .apu-table thead th:first-child { text-align: left; }
    .apu-table thead th:last-child { border-right: 0; }

    .apu-band td {
        background: var(--apu-band); color: #fff; font-weight: 700; font-size: 9.5px;
        letter-spacing: .07em; text-transform: uppercase; padding: 4px 8px; text-align: center;
    }

    .apu-table tbody td {
        padding: 3.5px 8px; border-bottom: 1px solid #e2e8f0; border-right: 1px solid #e8edf2;
        text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap;
    }
    .apu-table tbody td.desc {
        text-align: left; white-space: normal; font-weight: 600; color: #1e293b;
    }
    .apu-table tbody td:last-child { border-right: 0; }
    .apu-table tbody tr.row:nth-child(even of .row) td { background: #fafbfc; }

    .apu-sub td {
        background: #eef2f7; font-weight: 800; font-size: 10.5px; padding: 5px 8px;
        border-top: 1px solid var(--apu-line); border-bottom: 1px solid var(--apu-line);
    }
    .apu-sub td.lbl { text-align: right; letter-spacing: .03em; }

    /* Totales */
    .apu-totals { border-top: 2px solid var(--apu-band); }
    .apu-totals .row-t { display: flex; justify-content: space-between; align-items: center; padding: 6px 14px; }
    .apu-totals .row-t .lbl { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #334155; }
    .apu-totals .row-t .val { font-size: 12px; font-weight: 800; font-variant-numeric: tabular-nums; }
    .apu-totals .row-t + .row-t { border-top: 1px solid #e2e8f0; }
    .apu-totals .grand {
        background: var(--apu-band); padding: 10px 14px;
        display: flex; justify-content: space-between; align-items: center;
        border-top: 3px solid var(--apu-accent);
    }
    .apu-totals .grand .lbl { color: #fff; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; }
    .apu-totals .grand .val { color: var(--apu-accent); font-size: 18px; font-weight: 900; font-variant-numeric: tabular-nums; }

    /* Pie */
    .apu-foot { padding: 7px 14px; font-size: 8.5px; color: #64748b; display: flex; justify-content: space-between; align-items: center; gap: 8px; background: var(--apu-soft); }

    /* Barra de acciones (no se imprime) */
    .apu-actions { display: flex; justify-content: flex-end; margin-bottom: 9px; }
    .apu-print-btn {
        display: inline-flex; align-items: center; gap: 6px; cursor: pointer;
        background: var(--apu-accent); color: #1f2937; font-weight: 700; font-size: 11.5px;
        border: 0; border-radius: 7px; padding: 6px 13px; box-shadow: 0 2px 7px rgba(245,158,11,.32);
    }
    .apu-print-btn:hover { filter: brightness(1.05); }
    .apu-print-btn svg { width: 14px; height: 14px; }

    /* Teléfonos angostos */
    @media (max-width: 480px) {
        .apu-doc { font-size: 10.5px; }
        .apu-head h1 { font-size: 12px; }
        .apu-meta .cell { flex-basis: 50%; }
        .apu-totals .grand .val { font-size: 16px; }
    }

    /* Reglas de impresión: el documento ocupa la hoja, sin sombras ni scroll. */
    .apu-print-body { margin: 0; padding: 16px; background: #fff; }
    .apu-print-body .apu-doc { box-shadow: none; max-width: 100%; border-radius: 0; }
    .apu-print-body .apu-scroll { overflow: visible; }
    .apu-print-body .apu-table { min-width: 0; }
    @media print {
        .apu-actions { display: none !important; }
        .apu-doc { box-shadow: none; }
        .apu-scroll { overflow: visible; }
    }
</style>

<div class="apu-actions">
    <button type="button" class="apu-print-btn"
        onclick="(function(){
            var doc = document.getElementById('{{ $sheetId }}');
            var st  = document.getElementById('{{ $styleId }}');
            if(!doc||!st){return;}
            var w = window.open('', '_blank', 'width=820,height=1100');
            w.document.write('<!doctype html><html><head><meta charset=\'utf-8\'><title>{{ $ficha->codigo }}</title>'+st.outerHTML+'</head><body class=\'apu-print-body\'>'+doc.outerHTML+'</body></html>');
            w.document.close(); w.focus();
            setTimeout(function(){ w.print(); }, 300);
        })();">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.4 42.4 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5Zm-3 0h.008v.008H15V10.5Z" />
        </svg>
        Imprimir
    </button>
</div>

<div id="{{ $sheetId }}" class="apu-doc">
    {{-- Encabezado --}}
    <div class="apu-head">
        <p class="apu-kicker">Ficha de costos por actividad</p>
        <h1>{{ $ficha->nombre }}</h1>
        <span class="apu-code">{{ $ficha->codigo }}</span>
        <span class="apu-zone">{{ $ficha->zona->nombre }}</span>
    </div>

    {{-- Meta: unidad + parámetros técnicos --}}
    <div class="apu-meta">
        <div class="cell">
            <div class="k">Unidad</div>
            <div class="v">{{ $ficha->unidadMedida->codigo }} — {{ $ficha->unidadMedida->nombre }}</div>
        </div>
        @foreach (($ficha->parametros_tecnicos ?? []) as $clave => $valor)
            <div class="cell">
                <div class="k">{{ $clave }}</div>
                <div class="v">{{ $valor }}</div>
            </div>
        @endforeach
    </div>

    {{-- Tabla de composición (scroll horizontal en pantallas angostas) --}}
    <div class="apu-scroll">
        <table class="apu-table">
            <thead>
                <tr>
                    <th>Descripción</th>
                    <th>Unidad</th>
                    <th>Rendim.</th>
                    <th>Desperd. %</th>
                    <th>P.U. (L)</th>
                    <th>Subtotal (L)</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($secciones as $sec)
                    @php
                        $lineas = collect($r->detallesPorLinea)->where('seccion', $sec['cat']);
                    @endphp
                    @continue($lineas->isEmpty())

                    <tr class="apu-band"><td colspan="6">{{ $sec['titulo'] }}</td></tr>

                    @foreach ($lineas as $d)
                        <tr class="row">
                            <td class="desc">{{ $d->descripcion }}</td>
                            @if ($d->esPorcentaje)
                                <td>%</td>
                                <td>{{ $rend($d->porcentajeAplicado) }}</td>
                                <td>—</td>
                                <td>{{ $L($d->baseDelPorcentaje) }}</td>
                                <td>{{ $L($d->subtotal) }}</td>
                            @else
                                <td>{{ $d->unidad }}</td>
                                <td>{{ $rend($d->rendimientoEfectivo) }}</td>
                                <td>{{ $desp($desperdicios[$d->lineaId] ?? null) ?: '—' }}</td>
                                <td>{{ $L($d->precioUnitario) }}</td>
                                <td>{{ $L($d->subtotal) }}</td>
                            @endif
                        </tr>
                    @endforeach

                    <tr class="apu-sub">
                        <td class="lbl" colspan="5">Subtotal {{ $sec['titulo'] }}</td>
                        <td>L {{ $L($r->subtotalDe($sec['cat'])) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Totales --}}
    <div class="apu-totals">
        <div class="row-t">
            <span class="lbl">Sub Total</span>
            <span class="val">L {{ $L($r->subtotal) }}</span>
        </div>
        <div class="row-t">
            <span class="lbl">Utilidad {{ $pct($r->utilidadPorcentaje) }} %</span>
            <span class="val">L {{ $L($r->utilidadMonto) }}</span>
        </div>
        <div class="grand">
            <span class="lbl">Total Precio Unitario</span>
            <span class="val">L {{ $L($r->precioVenta) }}</span>
        </div>
    </div>

    {{-- Pie --}}
    <div class="apu-foot">
        <span>Cálculo en vivo · {{ now()->format('d/m/Y H:i') }}</span>
        <span>MAYAP</span>
    </div>
</div>
