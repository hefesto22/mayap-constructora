@php
    /** @var \Carbon\CarbonInterface $periodo */
    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Compra> $compras */
    /** @var string $totalMes */
    /** @var string $isvMes */
    /** @var array<int, array<int, string>> $fotosDataUris */
    $fmt = static fn ($v): string => number_format((float) $v, 2);
    $anulada = static fn ($c): bool => $c->estado === \App\Enums\EstadoCompra::Anulada;
    $conFotos = $compras->filter(fn ($c) => ($fotosDataUris[$c->id] ?? []) !== []);
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

        table.compras { width: 100%; border-collapse: collapse; margin-top: 18px; }
        table.compras th { text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: 1px; color: #6b7280; border-bottom: 2px solid #e5e7eb; padding: 7px 8px; }
        table.compras td { padding: 6px 8px; border-bottom: 1px solid #f3f4f6; font-size: 10px; vertical-align: top; }
        table.compras td.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        tr.anulada td { background: #fef2f2; color: #991b1b; }
        .tag-anulada { display: inline-block; background: #dc2626; color: #fff; font-size: 8px; font-weight: 800; letter-spacing: 1px; padding: 1px 6px; border-radius: 3px; }
        .meta { color: #6b7280; font-size: 9px; }

        .seccion-fotos { margin-top: 28px; }
        .seccion-fotos h2 { font-size: 13px; text-transform: uppercase; letter-spacing: 1px; color: #374151; border-bottom: 2px solid #e5e7eb; padding-bottom: 6px; }
        .factura { page-break-inside: avoid; margin-top: 18px; }
        .factura.salto { page-break-before: always; }
        .factura h3 { font-size: 12px; color: #111827; background: #f3f4f6; border-radius: 4px; padding: 6px 10px; }
        .factura h3 small { color: #6b7280; font-weight: 500; }
        .factura img { display: block; max-width: 100%; max-height: 880px; margin: 10px auto 0; border: 1px solid #e5e7eb; border-radius: 4px; }

        .foot { margin-top: 36px; padding-top: 12px; border-top: 1px solid #e5e7eb; font-size: 9px; color: #9ca3af; display: flex; justify-content: space-between; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="head">
        <div class="empresa">CONSTRUCTORA MAYAP</div>
        <div class="doc">
            <h1>Reporte fiscal de compras</h1>
            <div class="codigo">{{ ucfirst($periodo->translatedFormat('F Y')) }}</div>
            <div class="fecha">Generado: {{ now()->format('d/m/Y H:i') }}</div>
        </div>
    </div>

    <div class="resumen">
        <div class="kpi"><b>{{ $compras->count() }}</b><span>Compras del mes</span></div>
        <div class="kpi"><b>{{ $compras->filter($anulada)->count() }}</b><span>Anuladas</span></div>
        <div class="kpi"><b>L {{ $fmt($isvMes) }}</b><span>ISV del mes</span></div>
        <div class="kpi"><b>L {{ $fmt($totalMes) }}</b><span>Total comprado (sin anuladas)</span></div>
        <div class="kpi"><b>{{ collect($fotosDataUris)->flatten()->count() }}</b><span>Fotos de facturas archivadas</span></div>
    </div>

    <table class="compras">
        <thead>
        <tr>
            <th>Código</th>
            <th>Fecha</th>
            <th>Proveedor</th>
            <th>Documento fiscal</th>
            <th style="text-align:right">Subtotal</th>
            <th style="text-align:right">ISV</th>
            <th style="text-align:right">Total</th>
            <th style="text-align:right">Fotos</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($compras as $compra)
            <tr @class(['anulada' => $anulada($compra)])>
                <td>
                    <b>{{ $compra->codigo }}</b>
                    @if ($anulada($compra))
                        <br><span class="tag-anulada">ANULADA</span>
                    @endif
                </td>
                <td>{{ $compra->fecha->format('d/m/Y') }}</td>
                <td>{{ $compra->proveedor->nombre }}</td>
                <td>
                    {{ $compra->tipo_documento_fiscal?->getLabel() ?? '—' }}
                    @if ($compra->numero_factura !== null)
                        <br><span class="meta">N.º {{ $compra->numero_factura }}</span>
                    @endif
                </td>
                <td class="num">{{ $fmt($compra->subtotal_cache) }}</td>
                <td class="num">{{ $fmt($compra->isv_cache) }}</td>
                <td class="num"><b>{{ $fmt($compra->total_cache) }}</b></td>
                <td class="num">{{ count($fotosDataUris[$compra->id] ?? []) }}</td>
            </tr>
        @empty
            <tr><td colspan="8" style="text-align:center; color:#9ca3af; padding:20px;">Sin compras registradas en el período.</td></tr>
        @endforelse
        </tbody>
    </table>

    @if ($conFotos->isNotEmpty())
        <div class="seccion-fotos">
            <h2 style="page-break-before: always; padding-top: 10px;">Facturas escaneadas</h2>

            @foreach ($conFotos as $compra)
                <div class="factura">
                    <h3>
                        {{ $compra->codigo }} · {{ $compra->proveedor->nombre }}
                        <small>
                            — {{ $compra->tipo_documento_fiscal?->getLabel() ?? 'Sin documento' }}
                            @if ($compra->numero_factura !== null)
                                N.º {{ $compra->numero_factura }}
                            @endif
                            · L {{ $fmt($compra->total_cache) }}
                            @if ($anulada($compra))
                                · <span class="tag-anulada">ANULADA</span>
                            @endif
                        </small>
                    </h3>
                    @foreach ($fotosDataUris[$compra->id] ?? [] as $dataUri)
                        <img src="{{ $dataUri }}" alt="Factura {{ $compra->codigo }}">
                    @endforeach
                </div>
            @endforeach
        </div>
    @endif

    <div class="foot">
        <span>Constructora MAYAP — Reporte fiscal mensual de compras</span>
        <span>Las fotos originales se liberan del servidor tras archivarse en este PDF.</span>
    </div>
</div>
</body>
</html>
