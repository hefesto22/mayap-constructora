@php
    /** @var \App\Models\Planilla $planilla */
    /** @var \Illuminate\Support\Collection<int, \App\Models\PlanillaLinea> $lineas */
    use App\Enums\TipoPago;

    $fmt = static fn ($v): string => number_format((float) $v, 2);
    $conceptoDe = static function ($linea): string {
        return match ($linea->tipo_pago) {
            TipoPago::Jornal     => rtrim(rtrim(number_format((float) ($linea->dias_trabajados ?? 0), 2), '0'), '.').' día(s) × L '.number_format((float) $linea->tarifa_aplicada, 2),
            TipoPago::Salario    => 'Salario del período',
            TipoPago::Honorarios => 'Honorarios profesionales del período',
            TipoPago::Destajo    => $linea->descripcion !== null && $linea->descripcion !== '' ? 'Tarea: '.$linea->descripcion : 'Trabajo por tarea (destajo)',
        };
    };
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica Neue', Arial, sans-serif; color: #1f2937; font-size: 12px; }
        .recibo { padding: 36px 44px; page-break-after: always; }
        .recibo:last-child { page-break-after: auto; }
        .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #111827; padding-bottom: 14px; }
        .empresa { font-size: 20px; font-weight: 800; letter-spacing: 1px; color: #111827; }
        .empresa small { display: block; font-size: 9px; font-weight: 500; letter-spacing: 3px; color: #6b7280; }
        .doc { text-align: right; }
        .doc h1 { font-size: 14px; color: #374151; text-transform: uppercase; letter-spacing: 1px; }
        .doc .codigo { font-size: 13px; font-weight: 700; color: #111827; margin-top: 4px; }
        .doc .fecha { font-size: 10px; color: #6b7280; margin-top: 2px; }

        .empleado { margin-top: 18px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 16px; display: flex; justify-content: space-between; }
        .empleado b { font-size: 14px; color: #111827; }
        .empleado .dato { color: #4b5563; font-size: 11px; margin-top: 2px; }
        .empleado .periodo { text-align: right; font-size: 11px; color: #4b5563; }
        .empleado .periodo b { font-size: 12px; }

        table.detalle { width: 100%; border-collapse: collapse; margin-top: 16px; }
        table.detalle th { text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: 1px; color: #6b7280; border-bottom: 2px solid #e5e7eb; padding: 7px 8px; }
        table.detalle td { padding: 8px; border-bottom: 1px solid #f3f4f6; font-size: 12px; }
        table.detalle td.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .resta { color: #b91c1c; }

        .neto { margin-top: 12px; margin-left: auto; width: 300px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; }
        .neto span { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #14532d; font-weight: 700; }
        .neto b { font-size: 18px; color: #14532d; }

        .firmas { display: flex; gap: 60px; margin-top: 70px; }
        .firma { flex: 1; text-align: center; font-size: 10px; color: #4b5563; }
        .firma .linea { border-top: 1px solid #111827; padding-top: 6px; }

        .foot { margin-top: 28px; padding-top: 10px; border-top: 1px solid #e5e7eb; font-size: 9px; color: #9ca3af; display: flex; justify-content: space-between; }
    </style>
</head>
<body>
@forelse ($lineas as $linea)
    <div class="recibo">
        <div class="head">
            <div class="empresa">
                CONSTRUCTORA MAYAP
                <small>RECIBO DE PAGO</small>
            </div>
            <div class="doc">
                <h1>{{ $planilla->periodicidad->getLabel() }}</h1>
                <div class="codigo">{{ $planilla->codigo }}</div>
                <div class="fecha">Emitido: {{ now()->format('d/m/Y') }}</div>
            </div>
        </div>

        <div class="empleado">
            <div>
                <b>{{ $linea->empleado->nombre }}</b>
                @if ($linea->empleado->identidad !== null)
                    <div class="dato">Identidad: {{ $linea->empleado->identidad }}</div>
                @endif
                @if ($linea->empleado->cargo !== null)
                    <div class="dato">Cargo: {{ $linea->empleado->cargo }}</div>
                @endif
            </div>
            <div class="periodo">
                Período<br>
                <b>{{ $planilla->fecha_inicio->format('d/m/Y') }} — {{ $planilla->fecha_fin->format('d/m/Y') }}</b>
                @if ($linea->proyecto !== null)
                    <div>Obra: {{ $linea->proyecto->nombre }}</div>
                @endif
            </div>
        </div>

        <table class="detalle">
            <thead>
            <tr>
                <th>Concepto</th>
                <th style="text-align:right">Monto</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td>{{ $conceptoDe($linea) }}</td>
                <td class="num">L {{ $fmt($linea->monto_bruto) }}</td>
            </tr>
            @if ((float) $linea->retencion_monto > 0)
                <tr class="resta">
                    <td>Retención ISR ({{ rtrim(rtrim(number_format((float) $linea->retencion_porcentaje, 2), '0'), '.') }}%)</td>
                    <td class="num">− L {{ $fmt($linea->retencion_monto) }}</td>
                </tr>
            @endif
            @if ((float) $linea->deducciones > 0)
                <tr class="resta">
                    <td>Deducciones</td>
                    <td class="num">− L {{ $fmt($linea->deducciones) }}</td>
                </tr>
            @endif
            </tbody>
        </table>

        <div class="neto">
            <span>Neto a pagar</span>
            <b>L {{ $fmt($linea->monto_neto) }}</b>
        </div>

        <div class="firmas">
            <div class="firma">
                <div class="linea">Recibí conforme<br>{{ $linea->empleado->nombre }}</div>
            </div>
            <div class="firma">
                <div class="linea">Entregó<br>Constructora MAYAP</div>
            </div>
        </div>

        <div class="foot">
            <span>Planilla {{ $planilla->codigo }} · {{ $planilla->periodicidad->getLabel() }}</span>
            <span>Montos en Lempiras (HNL)</span>
        </div>
    </div>
@empty
    <div class="recibo">Sin líneas de pago en esta planilla.</div>
@endforelse
</body>
</html>
