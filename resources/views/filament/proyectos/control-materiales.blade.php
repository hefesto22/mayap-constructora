{{-- Control presupuestario de materiales de la obra.
     $filas: Collection<PresupuestoMaterial> del PresupuestoMaterialesProyectoService.

     NOTA DE ESTILOS: este Blade vive dentro del panel Filament, cuyo CSS
     compilado NO incluye clases Tailwind arbitrarias de vistas custom.
     Por eso el layout usa estilos inline (funcionan siempre, claro/oscuro
     via colores translúcidos) y componentes nativos <x-filament::badge>
     cuyo CSS sí viene en el core. --}}
@php
    use App\Services\Requisiciones\PresupuestoMaterial;

    $num = static function (string $d): string {
        $l = rtrim(rtrim($d, '0'), '.');

        return $l === '' || $l === '-' ? '0' : $l;
    };

    $borde = '1px solid rgba(128,128,128,0.25)';
    $celda = 'padding:10px 14px; white-space:nowrap;';
    $numero = $celda.' text-align:right; font-variant-numeric:tabular-nums;';
@endphp

@if ($filas->isEmpty())
    <div style="border:{{ $borde }}; border-radius:12px; padding:32px; text-align:center; opacity:.6; font-size:.875rem;">
        Las fichas de esta obra no tienen materiales físicos asociados y aún no hay requisiciones ni compras directas.
    </div>
@else
    <div style="border:{{ $borde }}; border-radius:12px; overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse; font-size:.875rem;">
            <thead>
                <tr style="border-bottom:{{ $borde }};">
                    <th style="{{ $celda }} text-align:left; font-weight:600;">Material</th>
                    <th style="{{ $numero }} font-weight:600;">Presupuestado</th>
                    <th style="{{ $numero }} font-weight:600;">Pedido</th>
                    <th style="{{ $numero }} font-weight:600;">Entregado</th>
                    <th style="{{ $numero }} font-weight:600;">Disponible</th>
                    <th style="{{ $celda }} text-align:left; font-weight:600; width:180px;">Consumo del presupuesto</th>
                    <th style="{{ $celda }} text-align:center; font-weight:600;">Estado</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($filas->sortBy('materialNombre') as $fila)
                    @php
                        /** @var PresupuestoMaterial $fila */
                        $sinPresupuesto = bccomp($fila->presupuestado, '0', 4) <= 0;
                        $excedido = $fila->excedido();
                        $disponible = $fila->disponible();
                        $negativo = bccomp($disponible, '0', 4) < 0;

                        $pct = $sinPresupuesto ? 100.0 : min(100.0, (float) $fila->porcentajeComprometido());
                        $colorBarra = $sinPresupuesto || $excedido
                            ? '#ef4444'
                            : ((float) $fila->porcentajeComprometido() >= 80.0 ? '#f59e0b' : '#22c55e');
                    @endphp
                    <tr style="border-bottom:{{ $borde }}; {{ $excedido || $sinPresupuesto ? 'background:rgba(239,68,68,0.06);' : '' }}">
                        <td style="{{ $celda }}">
                            <span style="font-family:monospace; font-size:.75rem; opacity:.55;">{{ $fila->materialCodigo }}</span><br>
                            <span style="font-weight:500;">{{ $fila->materialNombre }}</span>
                            <span style="opacity:.5; font-size:.75rem;">· {{ $fila->unidad }}</span>
                        </td>
                        <td style="{{ $numero }}">{{ $sinPresupuesto ? '—' : $num($fila->presupuestado) }}</td>
                        <td style="{{ $numero }}">{{ $num($fila->solicitado) }}</td>
                        <td style="{{ $numero }} opacity:.7;">{{ $num($fila->despachado) }}</td>
                        <td style="{{ $numero }} font-weight:700; {{ $negativo ? 'color:#ef4444;' : '' }}">
                            {{ $sinPresupuesto ? '—' : $num($disponible) }}
                        </td>
                        <td style="{{ $celda }}">
                            <div style="display:flex; align-items:center; gap:8px;">
                                <div style="flex:1; height:6px; border-radius:9999px; background:rgba(128,128,128,0.2); overflow:hidden; min-width:80px;">
                                    <div style="width:{{ $pct }}%; height:100%; border-radius:9999px; background:{{ $colorBarra }};"></div>
                                </div>
                                <span style="font-size:.75rem; opacity:.7; min-width:52px; text-align:right;">
                                    {{ $sinPresupuesto ? '—' : $fila->porcentajeComprometido().'%' }}
                                </span>
                            </div>
                        </td>
                        <td style="{{ $celda }} text-align:center;">
                            @if ($sinPresupuesto)
                                <x-filament::badge color="warning">Fuera de presupuesto</x-filament::badge>
                            @elseif ($excedido)
                                <x-filament::badge color="danger">Excedido +{{ $num($fila->exceso()) }}</x-filament::badge>
                            @elseif ((float) $fila->porcentajeComprometido() >= 80.0)
                                <x-filament::badge color="warning">Por agotarse</x-filament::badge>
                            @else
                                <x-filament::badge color="success">En presupuesto</x-filament::badge>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p style="margin-top:10px; font-size:.75rem; opacity:.55;">
        Presupuestado = cantidades según las fichas de la obra · Pedido = comprometido en requisiciones (excluye
        rechazadas) más compras directas a la obra · Entregado = despachado de bodega o comprado directo ·
        Disponible = presupuestado − pedido.
    </p>
@endif
