@php
    /** @var \App\Models\Proyecto $obra */
    /** @var \App\Services\Reportes\CostoProyecto $costo */
    $fmt = static fn ($v): string => 'L. '.number_format((float) $v, 2);
    $nivel = $costo->nivel();
    $colorNivel = match ($nivel->value) {
        'sano' => '#15803d',
        'en_riesgo' => '#b45309',
        default => '#b91c1c',
    };
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica Neue', Arial, sans-serif; color: #1f2937; font-size: 12px; }
        .wrap { padding: 36px 44px; }
        .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #111827; padding-bottom: 16px; }
        .empresa { font-size: 22px; font-weight: 800; letter-spacing: 1px; color: #111827; }
        .empresa small { display: block; font-size: 10px; font-weight: 500; letter-spacing: 3px; color: #6b7280; }
        .doc { text-align: right; }
        .doc h1 { font-size: 15px; color: #374151; text-transform: uppercase; letter-spacing: 1px; }
        .doc .codigo { font-size: 13px; font-weight: 700; color: #111827; margin-top: 4px; }
        .doc .fecha { font-size: 10px; color: #6b7280; margin-top: 2px; }

        .obra { margin-top: 22px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 14px 18px; }
        .obra .nombre { font-size: 14px; font-weight: 700; color: #111827; }
        .obra .meta { margin-top: 6px; font-size: 11px; color: #4b5563; }
        .obra .meta b { color: #111827; }

        table.costos { width: 100%; border-collapse: collapse; margin-top: 24px; }
        table.costos th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: #6b7280; border-bottom: 2px solid #e5e7eb; padding: 8px 10px; }
        table.costos td { padding: 10px; border-bottom: 1px solid #f3f4f6; font-size: 12px; }
        table.costos td.monto { text-align: right; font-variant-numeric: tabular-nums; }
        table.costos tr.total td { border-top: 2px solid #111827; font-weight: 800; font-size: 13px; padding-top: 12px; }

        .resumen { display: flex; gap: 14px; margin-top: 26px; }
        .card { flex: 1; border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px; text-align: center; }
        .card .label { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: #6b7280; }
        .card .valor { font-size: 17px; font-weight: 800; margin-top: 6px; }
        .nivel { display: inline-block; margin-top: 8px; padding: 4px 12px; border-radius: 999px; font-size: 11px; font-weight: 700; color: #fff; }

        .foot { margin-top: 40px; padding-top: 12px; border-top: 1px solid #e5e7eb; font-size: 9px; color: #9ca3af; display: flex; justify-content: space-between; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="head">
        <div class="empresa">
            CONSTRUCTORA MAYAP
        </div>
        <div class="doc">
            <h1>Estado de costo de obra</h1>
            <div class="codigo">{{ $obra->codigo }}</div>
            <div class="fecha">Emitido: {{ now()->format('d/m/Y H:i') }}</div>
        </div>
    </div>

    <div class="obra">
        <div class="nombre">{{ $obra->nombre }}</div>
        <div class="meta">
            <b>Cliente:</b> {{ $obra->cliente?->nombre ?? '—' }} &nbsp;·&nbsp;
            <b>Zona:</b> {{ $obra->zona?->nombre ?? '—' }} &nbsp;·&nbsp;
            <b>Estado:</b> {{ $obra->estado->getLabel() }}
        </div>
    </div>

    <table class="costos">
        <thead>
            <tr>
                <th>Fuente de costo</th>
                <th style="text-align:right;">Monto</th>
            </tr>
        </thead>
        <tbody>
            <tr><td>Materiales (despachados a la obra)</td><td class="monto">{{ $fmt($costo->costoMateriales) }}</td></tr>
            <tr><td>Maquinaria (horas + combustible)</td><td class="monto">{{ $fmt($costo->costoMaquinaria) }}</td></tr>
            <tr><td>Mano de obra (planillas cerradas)</td><td class="monto">{{ $fmt($costo->costoManoObra) }}</td></tr>
            <tr class="total"><td>Costo real total</td><td class="monto">{{ $fmt($costo->costoTotal) }}</td></tr>
        </tbody>
    </table>

    <div class="resumen">
        <div class="card">
            <div class="label">Presupuesto (venta)</div>
            <div class="valor" style="color:#111827;">{{ $fmt($costo->presupuesto) }}</div>
        </div>
        <div class="card">
            <div class="label">Margen</div>
            <div class="valor" style="color:{{ bccomp($costo->margen, '0', 2) >= 0 ? '#15803d' : '#b91c1c' }};">{{ $fmt($costo->margen) }}</div>
            <div style="font-size:10px;color:#6b7280;margin-top:2px;">{{ $costo->margenPorcentaje }}% del presupuesto</div>
        </div>
        <div class="card">
            <div class="label">Presupuesto consumido</div>
            <div class="valor" style="color:{{ $colorNivel }};">{{ $costo->porcentajeConsumido }}%</div>
            <span class="nivel" style="background:{{ $colorNivel }};">{{ $nivel->getLabel() }}</span>
        </div>
    </div>

    <div class="foot">
        <span>Constructora MAYAP</span>
        <span>Documento generado automáticamente</span>
    </div>
</div>
</body>
</html>
