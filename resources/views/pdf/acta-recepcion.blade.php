@php
    /** @var \App\Models\Compra $compra */
    /** @var \Illuminate\Support\Collection<int, \App\Models\CompraLinea> $lineas */
    /** @var bool $parcial */
    $fmt = static fn ($v): string => number_format((float) $v, 2);
    $conDiferencias = $lineas->contains(fn ($l) => $l->tieneDiferencia());
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

        .info { margin-top: 20px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 14px 18px; font-size: 11px; color: #4b5563; }
        .info b { color: #111827; }
        .info table { border-collapse: collapse; }
        .info td { padding: 2px 24px 2px 0; }

        .banner { margin-top: 14px; padding: 10px 16px; border-radius: 6px; font-weight: 700; font-size: 12px; }
        .banner.ok { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .banner.dif { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .banner.parcial { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }

        table.lineas { width: 100%; border-collapse: collapse; margin-top: 18px; }
        table.lineas th { text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: 1px; color: #6b7280; border-bottom: 2px solid #e5e7eb; padding: 7px 8px; }
        table.lineas td { padding: 7px 8px; border-bottom: 1px solid #f3f4f6; font-size: 11px; vertical-align: top; }
        table.lineas td.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        tr.diferencia td { background: #fef2f2; }
        .dif-num { color: #b91c1c; font-weight: 800; }
        .meta { color: #6b7280; font-size: 9px; }

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
            <h1>Acta de recepción de compra</h1>
            <div class="codigo">{{ $compra->codigo }}</div>
            <div class="fecha">Generada: {{ now()->format('d/m/Y H:i') }}</div>
        </div>
    </div>

    <div class="info">
        <table>
            <tr>
                <td><b>Proveedor:</b> {{ $compra->proveedor->nombre }}</td>
                <td><b>Factura N.º:</b> {{ $compra->numero_factura ?? '—' }}</td>
                <td><b>Fecha de compra:</b> {{ $compra->fecha->format('d/m/Y') }}</td>
            </tr>
            <tr>
                <td><b>Destino principal:</b>
                    {{ $compra->esDirectaAObra() ? 'Obra: '.$compra->proyecto?->nombre : 'Bodega: '.$compra->bodega?->nombre }}
                </td>
                <td><b>Estado:</b> {{ $compra->estado->getLabel() }}</td>
                @if (! $parcial)
                    <td><b>Total facturado:</b> L. {{ $fmt($compra->total_cache) }}</td>
                @endif
            </tr>
        </table>
    </div>

    @if ($parcial)
        <div class="banner parcial">◐ ACTA PARCIAL — detalla únicamente los destinos a cargo de quien la generó. El acta completa la emite recepción o gerencia.</div>
    @endif

    @if ($conDiferencias)
        <div class="banner dif">⚠ RECEPCIÓN CON DIFERENCIAS — lo detallado en rojo NO llegó completo. Documento soporte del reclamo al proveedor.</div>
    @else
        <div class="banner ok">✓ RECEPCIÓN COMPLETA — todo lo facturado fue recibido y verificado.</div>
    @endif

    <table class="lineas">
        <thead>
            <tr>
                <th>Material</th>
                <th>Destino</th>
                <th style="text-align:right;">Facturado</th>
                <th style="text-align:right;">Recibido</th>
                <th style="text-align:right;">Diferencia</th>
                <th>Verificado por</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lineas as $linea)
                @php
                    $diferencia = $linea->verificada()
                        ? bcsub((string) $linea->cantidad_recibida, (string) $linea->cantidad, 4)
                        : null;
                @endphp
                <tr @class(['diferencia' => $linea->tieneDiferencia()])>
                    <td>
                        {{ $linea->material->nombre }}
                        <div class="meta">{{ $linea->material->codigo }}</div>
                    </td>
                    <td>
                        {{ $linea->proyecto_id !== null
                            ? 'Obra: '.$linea->proyecto?->nombre
                            : ($linea->bodega_id !== null
                                ? 'Bodega: '.$linea->bodega?->nombre
                                : ($compra->esDirectaAObra() ? 'Obra: '.$compra->proyecto?->nombre : 'Bodega: '.$compra->bodega?->nombre)) }}
                    </td>
                    <td class="num">{{ $fmt($linea->cantidad) }}</td>
                    <td class="num">{{ $linea->verificada() ? $fmt($linea->cantidad_recibida) : 'Pendiente' }}</td>
                    <td class="num {{ $linea->tieneDiferencia() ? 'dif-num' : '' }}">
                        {{ $diferencia !== null ? $fmt($diferencia) : '—' }}
                    </td>
                    <td>
                        {{ $linea->verificadaPor?->name ?? '—' }}
                        @if ($linea->verificada_at !== null)
                            <div class="meta">{{ $linea->verificada_at->format('d/m/Y H:i') }}</div>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="firmas">
        <div class="firma"><div class="linea">RECIBIDO / VERIFICADO</div></div>
        <div class="firma"><div class="linea">PROVEEDOR (RECLAMO)</div></div>
    </div>

    <div class="foot">
        <span>Constructora MAYAP</span>
        <span>El inventario ingresó por las cantidades RECIBIDAS; la deuda corresponde a lo FACTURADO.</span>
    </div>
</div>
</body>
</html>
