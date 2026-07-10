@php
    /** @var \App\Models\Proyecto $proyecto */
    /** @var \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, \App\Models\ProyectoRenglon>> $capitulos */
    $fmt = static fn ($v): string => 'L. '.number_format((float) $v, 2);
    // Cantidades de insumos: hasta 4 decimales, sin ceros de relleno.
    $cant = static function ($v): string {
        $texto = number_format((float) $v, 4);

        return str_contains($texto, '.') ? rtrim(rtrim($texto, '0'), '.') : $texto;
    };
    $numero = 0;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica Neue', Arial, sans-serif; color: #1f2937; font-size: 11px; }
        .wrap { padding: 36px 44px; }
        .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #111827; padding-bottom: 16px; }
        .empresa { font-size: 22px; font-weight: 800; letter-spacing: 1px; color: #111827; }
        .empresa small { display: block; font-size: 10px; font-weight: 500; letter-spacing: 3px; color: #6b7280; }
        .doc { text-align: right; }
        .doc h1 { font-size: 15px; color: #374151; text-transform: uppercase; letter-spacing: 1px; }
        .doc .codigo { font-size: 13px; font-weight: 700; color: #111827; margin-top: 4px; }
        .doc .fecha { font-size: 10px; color: #6b7280; margin-top: 2px; }

        .info { margin-top: 20px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 14px 18px; }
        .info .nombre { font-size: 14px; font-weight: 700; color: #111827; }
        .info table { margin-top: 8px; font-size: 11px; color: #4b5563; border-collapse: collapse; }
        .info td { padding: 2px 24px 2px 0; vertical-align: top; }
        .info b { color: #111827; }

        table.renglones { width: 100%; border-collapse: collapse; margin-top: 22px; }
        table.renglones th { text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: 1px; color: #6b7280; border-bottom: 2px solid #e5e7eb; padding: 7px 8px; }
        table.renglones td { padding: 7px 8px; border-bottom: 1px solid #f3f4f6; font-size: 11px; vertical-align: top; }
        table.renglones td.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        table.renglones td.centrado { text-align: center; }
        tr.capitulo td { background: #f3f4f6; font-weight: 800; font-size: 10px; letter-spacing: 1px; color: #111827; padding: 6px 8px; }
        .ficha-codigo { color: #6b7280; font-size: 9px; }

        /* Desglose de insumos por renglón (sin precios internos) */
        tr.insumos-row > td { padding: 0 8px 10px 8px; border-bottom: 1px solid #f3f4f6; }
        table.insumos { width: 100%; border-collapse: collapse; background: #fafafa; border: 1px solid #f3f4f6; border-radius: 4px; }
        table.insumos th { text-align: left; font-size: 8px; text-transform: uppercase; letter-spacing: 0.5px; color: #9ca3af; padding: 4px 8px; border-bottom: 1px solid #f3f4f6; }
        table.insumos td { padding: 3px 8px; font-size: 9.5px; color: #4b5563; border-bottom: none; }
        table.insumos td.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        table.insumos td.centrado { text-align: center; }

        table.totales { border-collapse: collapse; margin-top: 16px; margin-left: auto; min-width: 300px; }
        table.totales td { padding: 5px 10px; font-size: 12px; }
        table.totales td.monto { text-align: right; font-variant-numeric: tabular-nums; }
        table.totales tr.total td { border-top: 2px solid #111827; font-weight: 800; font-size: 14px; padding-top: 9px; }

        .condiciones { margin-top: 22px; border: 1px solid #e5e7eb; border-radius: 6px; padding: 12px 16px; font-size: 10.5px; color: #4b5563; }
        .condiciones h2 { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: #6b7280; margin-bottom: 6px; }
        .condiciones b { color: #111827; }

        .firmas { display: flex; gap: 60px; margin-top: 64px; }
        .firma { flex: 1; text-align: center; font-size: 10px; color: #4b5563; }
        .firma .linea { border-top: 1px solid #111827; padding-top: 6px; }

        .foot { margin-top: 36px; padding-top: 12px; border-top: 1px solid #e5e7eb; font-size: 9px; color: #9ca3af; display: flex; justify-content: space-between; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="head">
        <div class="empresa">
            CONSTRUCTORA MAYAP
        </div>
        <div class="doc">
            <h1>Composición del proyecto</h1>
            <div class="codigo">{{ $proyecto->codigo }}</div>
            <div class="fecha">Generado: {{ now()->format('d/m/Y H:i') }}</div>
        </div>
    </div>

    <div class="info">
        <div class="nombre">{{ $proyecto->nombre }}</div>
        <table>
            <tr>
                <td><b>Cliente:</b> {{ $proyecto->cliente?->nombre ?? '—' }}</td>
                <td><b>RTN:</b> {{ $proyecto->cliente?->rtn ?? '—' }}</td>
                <td><b>Zona:</b> {{ $proyecto->zona?->nombre ?? '—' }}</td>
            </tr>
            <tr>
                <td><b>Dirección de obra:</b> {{ $proyecto->direccion_obra }}</td>
                <td><b>Emitido:</b> {{ $proyecto->fecha_emision->format('d/m/Y') }}</td>
                <td><b>Válido hasta:</b> {{ $proyecto->fecha_validez->format('d/m/Y') }}</td>
            </tr>
            <tr>
                <td><b>Estado:</b> {{ $proyecto->estado->getLabel() }}</td>
                <td><b>Moneda:</b> {{ $proyecto->moneda }}</td>
                <td><b>Renglones:</b> {{ $proyecto->renglones->count() }}</td>
            </tr>
        </table>
        @if (filled($proyecto->descripcion))
            <div style="margin-top:8px;font-size:10.5px;color:#4b5563;">{{ $proyecto->descripcion }}</div>
        @endif
    </div>

    <table class="renglones">
        <thead>
            <tr>
                <th style="width:26px;">No.</th>
                <th>Descripción (ficha APU)</th>
                <th style="width:52px;text-align:center;">Unidad</th>
                <th style="width:70px;text-align:right;">Cantidad</th>
                <th style="width:88px;text-align:right;">P. Unitario</th>
                <th style="width:96px;text-align:right;">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($capitulos as $capitulo => $renglones)
                @if ($capitulo !== '')
                    <tr class="capitulo">
                        <td colspan="5">{{ $capitulo }}</td>
                        <td class="num">{{ $fmt($renglones->sum(fn ($r) => (float) $r->subtotal_cache)) }}</td>
                    </tr>
                @endif
                @foreach ($renglones as $renglon)
                    @php
                        $unidadFicha = $renglon->ficha->unidadMedida?->codigo ?? 'UND';
                        $lineas = $renglon->ficha->lineas;
                    @endphp
                    <tr>
                        <td class="num">{{ ++$numero }}</td>
                        <td>
                            {{ $renglon->ficha->nombre }}
                            <div class="ficha-codigo">{{ $renglon->ficha->codigo }}@if (filled($renglon->notas)) · {{ $renglon->notas }}@endif</div>
                        </td>
                        <td class="centrado">{{ $unidadFicha }}</td>
                        <td class="num">{{ number_format((float) $renglon->cantidad, 2) }}</td>
                        <td class="num">{{ $fmt($renglon->precio_unitario_snapshot) }}</td>
                        <td class="num">{{ $fmt($renglon->subtotal_cache) }}</td>
                    </tr>
                    @if ($lineas->isNotEmpty())
                        <tr class="insumos-row">
                            <td></td>
                            <td colspan="5">
                                <table class="insumos">
                                    <thead>
                                        <tr>
                                            <th>Insumo</th>
                                            <th style="width:110px;">Categoría</th>
                                            <th style="width:50px;text-align:center;">Unidad</th>
                                            <th style="width:90px;text-align:right;">Cant. / {{ $unidadFicha }}</th>
                                            <th style="width:100px;text-align:right;">Cant. total est.</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($lineas as $linea)
                                            @if ($linea->tipo === \App\Enums\TipoLineaFicha::Item && $linea->item !== null)
                                                <tr>
                                                    <td>{{ $linea->item->nombre }}</td>
                                                    <td>{{ $linea->item->categoria?->getLabel() ?? '—' }}</td>
                                                    <td class="centrado">{{ $linea->item->unidadMedida?->codigo ?? '—' }}</td>
                                                    <td class="num">{{ $cant($linea->rendimiento) }}</td>
                                                    <td class="num">{{ $cant(bcmul((string) $linea->rendimiento, (string) $renglon->cantidad, 6)) }}</td>
                                                </tr>
                                            @else
                                                <tr>
                                                    <td colspan="5">
                                                        {{ $linea->descripcion ?? 'CARGO DERIVADO' }}
                                                        — {{ number_format((float) $linea->porcentaje, 2) }}% sobre {{ $linea->categoria_base?->getLabel() ?? 'costo directo' }}
                                                    </td>
                                                </tr>
                                            @endif
                                        @endforeach
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    @endif
                @endforeach
            @endforeach
        </tbody>
    </table>

    <table class="totales">
        <tr>
            <td>Subtotal</td>
            <td class="monto">{{ $fmt($proyecto->subtotal_cache) }}</td>
        </tr>
        <tr>
            <td>ISV ({{ $proyecto->aplica_isv ? number_format((float) $proyecto->isv_porcentaje, 0).'%' : 'exento' }})</td>
            <td class="monto">{{ $fmt($proyecto->isv_cache) }}</td>
        </tr>
        <tr class="total">
            <td>Total</td>
            <td class="monto">{{ $fmt($proyecto->total_cache) }} {{ $proyecto->moneda }}</td>
        </tr>
    </table>

    <div class="condiciones">
        <h2>Condiciones</h2>
        <b>Validez de la oferta:</b> hasta el {{ $proyecto->fecha_validez->format('d/m/Y') }}.
        @if ($proyecto->plazo_dias !== null)
            &nbsp;·&nbsp;<b>Plazo de ejecución:</b> {{ $proyecto->plazo_dias }} días {{ $proyecto->modo_plazo?->getLabel() }}.
        @endif
        @if ($proyecto->anticipo_monto !== null)
            &nbsp;·&nbsp;<b>Anticipo:</b> {{ $fmt($proyecto->anticipo_monto) }}.
        @endif
        @if (filled($proyecto->notas))
            <div style="margin-top:6px;">{{ $proyecto->notas }}</div>
        @endif
    </div>

    <div class="firmas">
        <div class="firma"><div class="linea">CONSTRUCTORA MAYAP</div></div>
        <div class="firma"><div class="linea">{{ $proyecto->cliente?->nombre ?? 'CLIENTE' }}</div></div>
    </div>

    <div class="foot">
        <span>Constructora MAYAP</span>
        <span>Precios pactados (snapshot) · Documento generado automáticamente</span>
    </div>
</div>
</body>
</html>
