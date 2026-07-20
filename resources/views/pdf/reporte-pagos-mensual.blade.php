@php
    /** @var \Carbon\CarbonInterface $periodo */
    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Abono> $abonos */
    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\CuentaPorPagar> $saldadas */
    /** @var string $totalAbonado */
    /** @var array<int, string> $fotosDataUris */
    /** @var array<int, list<string>> $historial */
    $fmt = static fn ($v): string => number_format((float) $v, 2);
    $conFoto = $abonos->filter(fn ($a) => isset($fotosDataUris[$a->id]));
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
        .doc { text-align: right; }
        .doc h1 { font-size: 15px; color: #374151; text-transform: uppercase; letter-spacing: 1px; }
        .doc .codigo { font-size: 13px; font-weight: 700; color: #111827; margin-top: 4px; }
        .doc .fecha { font-size: 10px; color: #6b7280; margin-top: 2px; }

        .resumen { margin-top: 20px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 14px 18px; display: flex; gap: 40px; }
        .resumen .kpi b { display: block; font-size: 16px; color: #111827; }
        .resumen .kpi span { font-size: 9px; text-transform: uppercase; letter-spacing: 1px; color: #6b7280; }

        h2.seccion { margin-top: 26px; font-size: 13px; text-transform: uppercase; letter-spacing: 1px; color: #374151; border-bottom: 2px solid #e5e7eb; padding-bottom: 6px; }

        table.datos { width: 100%; border-collapse: collapse; margin-top: 12px; }
        table.datos th { text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: 1px; color: #6b7280; border-bottom: 2px solid #e5e7eb; padding: 7px 8px; }
        table.datos td { padding: 6px 8px; border-bottom: 1px solid #f3f4f6; font-size: 10px; vertical-align: top; }
        table.datos td.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .meta { color: #6b7280; font-size: 9px; }

        .tag-pagada { display: inline-block; background: #059669; color: #fff; font-size: 8px; font-weight: 800; letter-spacing: 1px; padding: 1px 6px; border-radius: 3px; }
        ul.historial { margin: 4px 0 0 14px; }
        ul.historial li { font-size: 9px; color: #374151; padding: 1px 0; }

        .comprobante { page-break-inside: avoid; margin-top: 18px; }
        .comprobante h3 { font-size: 12px; color: #111827; background: #f3f4f6; border-radius: 4px; padding: 6px 10px; }
        .comprobante h3 small { color: #6b7280; font-weight: 500; }
        .comprobante img { display: block; max-width: 100%; max-height: 880px; margin: 10px auto 0; border: 1px solid #e5e7eb; border-radius: 4px; }

        .foot { margin-top: 36px; padding-top: 12px; border-top: 1px solid #e5e7eb; font-size: 9px; color: #9ca3af; display: flex; justify-content: space-between; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="head">
        <div class="empresa">CONSTRUCTORA MAYAP</div>
        <div class="doc">
            <h1>Reporte de pagos a proveedores</h1>
            <div class="codigo">{{ ucfirst($periodo->translatedFormat('F Y')) }}</div>
            <div class="fecha">Generado: {{ now()->format('d/m/Y H:i') }}</div>
        </div>
    </div>

    <div class="resumen">
        <div class="kpi"><b>{{ $abonos->count() }}</b><span>Depósitos del mes</span></div>
        <div class="kpi"><b>L {{ $fmt($totalAbonado) }}</b><span>Total pagado</span></div>
        <div class="kpi"><b>{{ $saldadas->count() }}</b><span>Compras saldadas este mes</span></div>
        <div class="kpi"><b>{{ count($fotosDataUris) }}</b><span>Comprobantes archivados</span></div>
    </div>

    <h2 class="seccion">Depósitos del mes</h2>

    <table class="datos">
        <thead>
        <tr>
            <th>Fecha</th>
            <th>Proveedor</th>
            <th>Compra</th>
            <th>Método</th>
            <th>Referencia</th>
            <th style="text-align:right">Monto</th>
            <th style="text-align:right">Comprobante</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($abonos as $abono)
            <tr>
                <td>{{ $abono->fecha->format('d/m/Y') }}</td>
                <td>{{ $abono->cuentaPorPagar->proveedor->nombre }}</td>
                <td><b>{{ $abono->cuentaPorPagar->compra->codigo }}</b></td>
                <td>{{ $abono->metodo ?? '—' }}</td>
                <td>{{ $abono->referencia ?? '—' }}</td>
                <td class="num"><b>{{ $fmt($abono->monto) }}</b></td>
                <td class="num">{{ isset($fotosDataUris[$abono->id]) ? 'Sí' : '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="7" style="text-align:center; color:#9ca3af; padding:20px;">Sin depósitos registrados en el período.</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2 class="seccion">Compras saldadas este mes</h2>

    <table class="datos">
        <thead>
        <tr>
            <th>Compra</th>
            <th>Proveedor</th>
            <th style="text-align:right">Monto original</th>
            <th>Estado</th>
            <th>Meses en que se abonó</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($saldadas as $cuenta)
            <tr>
                <td><b>{{ $cuenta->compra->codigo }}</b></td>
                <td>{{ $cuenta->proveedor->nombre }}</td>
                <td class="num">{{ $fmt($cuenta->monto_original) }}</td>
                <td><span class="tag-pagada">PAGADA</span></td>
                <td>
                    <ul class="historial">
                        @foreach ($historial[$cuenta->id] ?? [] as $linea)
                            <li>{{ $linea }}</li>
                        @endforeach
                    </ul>
                </td>
            </tr>
        @empty
            <tr><td colspan="5" style="text-align:center; color:#9ca3af; padding:20px;">Ninguna compra se terminó de pagar en el período.</td></tr>
        @endforelse
        </tbody>
    </table>

    @if ($conFoto->isNotEmpty())
        <h2 class="seccion" style="page-break-before: always; padding-top: 10px;">Comprobantes de transferencia</h2>

        @foreach ($conFoto as $abono)
            <div class="comprobante">
                <h3>
                    {{ $abono->fecha->format('d/m/Y') }} · {{ $abono->cuentaPorPagar->proveedor->nombre }}
                    <small>
                        — {{ $abono->cuentaPorPagar->compra->codigo }}
                        · L {{ $fmt($abono->monto) }}
                        @if ($abono->referencia !== null)
                            · Ref. {{ $abono->referencia }}
                        @endif
                    </small>
                </h3>
                <img src="{{ $fotosDataUris[$abono->id] }}" alt="Comprobante del abono">
            </div>
        @endforeach
    @endif

    <div class="foot">
        <span>Constructora MAYAP — reporte mensual de pagos a proveedores</span>
        <span>{{ ucfirst($periodo->translatedFormat('F Y')) }}</span>
    </div>
</div>
</body>
</html>
