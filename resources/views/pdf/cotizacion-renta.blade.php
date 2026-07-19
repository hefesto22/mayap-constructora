@php
    /** @var \App\Models\Proyecto $proyecto */
    use App\Services\Reportes\CotizacionRentaService;

    $fmt = static fn ($v): string => number_format((float) $v, 2);
    $cliente = $proyecto->cliente;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica Neue', Arial, sans-serif; color: #1f2937; font-size: 13px; background: #fff; }
        .wrap { padding: 32px 36px; max-width: 820px; }
        .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #111827; padding-bottom: 14px; }
        .empresa { font-size: 24px; font-weight: 800; letter-spacing: 1px; color: #111827; }
        .empresa small { display: block; font-size: 10px; font-weight: 500; letter-spacing: 3px; color: #6b7280; }
        .doc { text-align: right; }
        .doc h1 { font-size: 15px; color: #374151; text-transform: uppercase; letter-spacing: 1px; }
        .doc .codigo { font-size: 15px; font-weight: 800; color: #111827; margin-top: 4px; }
        .doc .fecha { font-size: 11px; color: #6b7280; margin-top: 2px; }

        .cliente { margin-top: 18px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px 18px; }
        .cliente b { color: #111827; font-size: 14px; }
        .cliente .dato { color: #4b5563; font-size: 12px; margin-top: 2px; }

        table.lineas { width: 100%; border-collapse: collapse; margin-top: 18px; }
        table.lineas th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: #6b7280; border-bottom: 2px solid #e5e7eb; padding: 8px; }
        table.lineas td { padding: 9px 8px; border-bottom: 1px solid #f3f4f6; font-size: 13px; vertical-align: top; }
        table.lineas td.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .maquina b { color: #111827; }
        .meta { color: #6b7280; font-size: 11px; }
        .tag-ext { display: inline-block; background: #eff6ff; color: #1d4ed8; font-size: 9px; font-weight: 800; letter-spacing: 1px; padding: 1px 6px; border-radius: 3px; border: 1px solid #bfdbfe; }

        .totales { margin-top: 14px; margin-left: auto; width: 300px; }
        .totales table { width: 100%; border-collapse: collapse; }
        .totales td { padding: 5px 8px; font-size: 13px; }
        .totales td.num { text-align: right; font-variant-numeric: tabular-nums; }
        .totales tr.total td { border-top: 2px solid #111827; font-size: 17px; font-weight: 800; color: #111827; padding-top: 8px; }

        .condiciones { margin-top: 22px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 14px 18px; font-size: 12px; color: #14532d; }
        .condiciones b { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; }
        .condiciones .fila { margin-top: 3px; }

        .foot { margin-top: 28px; padding-top: 12px; border-top: 1px solid #e5e7eb; font-size: 10px; color: #9ca3af; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="head">
        <div class="empresa">
            CONSTRUCTORA MAYAP
            <small>RENTA DE MAQUINARIA</small>
        </div>
        <div class="doc">
            <h1>Cotización</h1>
            <div class="codigo">{{ $proyecto->codigo }}</div>
            <div class="fecha">Emitida: {{ now()->format('d/m/Y') }}</div>
        </div>
    </div>

    <div class="cliente">
        <b>{{ $cliente?->nombre ?? 'CLIENTE POR DEFINIR' }}</b>
        @if ($cliente?->rtn !== null)
            <div class="dato">RTN: {{ $cliente->rtn }}</div>
        @endif
        @if ($cliente?->telefono !== null)
            <div class="dato">Tel: {{ $cliente->telefono }}</div>
        @endif
    </div>

    <table class="lineas">
        <thead>
        <tr>
            <th>Máquina</th>
            <th style="text-align:right">Cantidad</th>
            <th style="text-align:right">Tarifa</th>
            <th style="text-align:right">Subtotal</th>
            <th>Llegada</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($proyecto->lineasRenta as $linea)
            <tr>
                <td class="maquina">
                    <b>{{ $linea->maquina->nombre }}</b>
                    @if ($linea->es_extension)
                        <span class="tag-ext">EXTENSIÓN</span>
                    @endif
                    @if ($linea->maquina->marca !== null || $linea->maquina->modelo !== null)
                        <div class="meta">{{ trim(($linea->maquina->marca ?? '').' '.($linea->maquina->modelo ?? '')) }}</div>
                    @endif
                </td>
                <td class="num">{{ rtrim(rtrim(number_format((float) $linea->cantidad, 2), '0'), '.') }} {{ mb_strtolower($linea->unidad->getLabel()) }}(s)</td>
                <td class="num">L {{ $fmt($linea->tarifa_snapshot) }}</td>
                <td class="num"><b>L {{ $fmt($linea->subtotal_cache) }}</b></td>
                <td>
                    {{ $linea->fecha_llegada->format('d/m/Y') }}
                    @if ($linea->horaLlegadaCorta() !== null)
                        <div class="meta">{{ $linea->horaLlegadaCorta() }}</div>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="totales">
        <table>
            <tr>
                <td>Subtotal</td>
                <td class="num">L {{ $fmt($proyecto->subtotal_cache) }}</td>
            </tr>
            <tr>
                <td>ISV ({{ rtrim(rtrim(number_format((float) $proyecto->isv_porcentaje, 2), '0'), '.') }}%)</td>
                <td class="num">L {{ $fmt($proyecto->isv_cache) }}</td>
            </tr>
            <tr class="total">
                <td>TOTAL</td>
                <td class="num">L {{ $fmt($proyecto->total_cache) }}</td>
            </tr>
        </table>
    </div>

    <div class="condiciones">
        <b>Condiciones</b>
        <div class="fila">Forma de pago: <strong>{{ CotizacionRentaService::condicionPagoLabel($proyecto) }}</strong></div>
        @if ($proyecto->fecha_validez !== null)
            <div class="fila">Cotización válida hasta el <strong>{{ $proyecto->fecha_validez->format('d/m/Y') }}</strong>.</div>
        @endif
        <div class="fila">Se cobra lo cotizado como mínimo; horas adicionales trabajadas se facturan aparte a la tarifa pactada.</div>
    </div>

    <div class="foot">
        Precios en Lempiras (HNL), ISV incluido en el total. Constructora MAYAP — cotización {{ $proyecto->codigo }}.
    </div>
</div>
</body>
</html>
