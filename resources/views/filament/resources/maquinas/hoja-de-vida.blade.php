<x-filament-panels::page>
    @php
        $lps = static fn ($v): string => 'L. '.number_format((float) $v, 2);
    @endphp

    <div class="hv-pagina">
        {{-- ── Identificación ─────────────────────────────────────── --}}
        <div class="hv-card hv-identidad">
            <div>
                <div class="hv-codigo">{{ $maquina->codigo }}</div>
                <h2 class="hv-nombre">{{ $maquina->nombre }}</h2>
                <div class="hv-specs">
                    <span>{{ $maquina->marca }} {{ $maquina->modelo }}</span>
                    @if ($maquina->anio) <span>· {{ $maquina->anio }}</span> @endif
                    @if ($maquina->serie) <span>· Serie {{ $maquina->serie }}</span> @endif
                </div>
            </div>
            <div class="hv-identidad-datos">
                <div><label>Estado</label><strong>{{ $maquina->estado->getLabel() }}</strong></div>
                <div><label>Horómetro</label><strong>{{ \App\Support\Cantidad::corta($maquina->horometro_actual) }} h</strong></div>
                <div><label>Tarifa base</label><strong>{{ $lps($maquina->tarifa_hora) }}/h</strong></div>
            </div>
        </div>

        {{-- ── Rentabilidad ────────────────────────────────────────── --}}
        <div class="hv-stats">
            <div class="hv-card hv-stat">
                <label>Horas trabajadas</label>
                <strong>{{ \App\Support\Cantidad::corta($resumen->horas) }}</strong>
                <small>{{ $resumen->totalAsignaciones }} asignaciones</small>
            </div>
            <div class="hv-card hv-stat">
                <label>Ingresos</label>
                <strong>{{ $lps($resumen->ingresos) }}</strong>
                <small>partes de trabajo cobrados</small>
            </div>
            <div class="hv-card hv-stat">
                <label>Combustible</label>
                <strong>{{ $lps($resumen->combustible) }}</strong>
                <small>{{ \App\Support\Cantidad::corta($resumen->litros) }} litros</small>
            </div>
            <div class="hv-card hv-stat {{ $resumen->conUtilidadPositiva() ? 'hv-ok' : 'hv-mal' }}">
                <label>Utilidad</label>
                <strong>{{ $lps($resumen->utilidad) }}</strong>
                <small>margen {{ $resumen->margen }} %</small>
            </div>
            <div class="hv-card hv-stat">
                <label>Mantenimientos</label>
                <strong>{{ $resumen->totalMantenimientos }}</strong>
                <small>en el historial</small>
            </div>
        </div>

        {{-- ── Historial de obras ──────────────────────────────────── --}}
        <div class="hv-card">
            <h3 class="hv-titulo">Historial en obras</h3>
            @if ($asignaciones->isEmpty())
                <p class="hv-vacio">Esta máquina aún no ha sido asignada a ninguna obra.</p>
            @else
                <table class="hv-tabla">
                    <thead>
                        <tr>
                            <th>Código</th><th>Obra</th><th>Período</th><th>Estado</th>
                            <th class="num">Tarifa/h</th><th class="num">Horas</th>
                            <th class="num">Ingreso</th><th class="num">Combustible</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($asignaciones as $a)
                            @php
                                $horas = bcadd((string) ($a->horas_total ?? 0), (string) ($a->horas_extra_total ?? 0), 2);
                            @endphp
                            <tr>
                                <td>{{ $a->codigo }}</td>
                                <td>{{ $a->proyecto->nombre }}</td>
                                <td>{{ $a->fecha_inicio->format('d/m/Y') }} — {{ $a->fecha_fin?->format('d/m/Y') ?? 'en curso' }}</td>
                                <td>
                                    <span class="hv-badge {{ $a->estado->esActiva() ? 'hv-badge-ok' : '' }}">
                                        {{ $a->estado->getLabel() }}
                                    </span>
                                </td>
                                <td class="num">{{ $lps($a->tarifa_hora_pactada) }}</td>
                                <td class="num">{{ \App\Support\Cantidad::corta($horas) }}</td>
                                <td class="num">{{ $lps($a->ingresos_total ?? 0) }}</td>
                                <td class="num">{{ $lps($a->combustible_total ?? 0) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- ── Mantenimientos ──────────────────────────────────────── --}}
        <div class="hv-card">
            <h3 class="hv-titulo">Mantenimientos</h3>
            @if ($mantenimientos->isEmpty())
                <p class="hv-vacio">Sin mantenimientos registrados.</p>
            @else
                <table class="hv-tabla">
                    <thead>
                        <tr><th>Código</th><th>Período</th><th>Motivo</th><th>Estado</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($mantenimientos as $m)
                            <tr>
                                <td>{{ $m->codigo }}</td>
                                <td>{{ $m->fecha_inicio->format('d/m/Y') }} — {{ $m->fecha_fin?->format('d/m/Y') ?? 'en curso' }}</td>
                                <td>{{ $m->motivo }}</td>
                                <td><span class="hv-badge">{{ $m->estado->getLabel() }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    <style>
        .hv-pagina { display: flex; flex-direction: column; gap: 1rem; }

        .hv-card {
            border-radius: .75rem; padding: 1.25rem; background: #fff;
            border: 1px solid rgb(0 0 0 / .06); box-shadow: 0 1px 2px rgb(0 0 0 / .06);
        }

        .dark .hv-card { background: rgb(24 24 27); border-color: rgb(255 255 255 / .08); }

        /* Identificación */
        .hv-identidad { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 1rem; align-items: center; }
        .hv-codigo { font-size: .75rem; font-weight: 600; letter-spacing: .08em; color: rgb(245 158 11); }
        .hv-nombre { font-size: 1.35rem; font-weight: 800; margin: .1rem 0; color: rgb(17 24 39); }
        .dark .hv-nombre { color: #fff; }
        .hv-specs { font-size: .85rem; color: rgb(107 114 128); }
        .hv-identidad-datos { display: flex; gap: 2rem; }
        .hv-identidad-datos label { display: block; font-size: .7rem; text-transform: uppercase; letter-spacing: .06em; color: rgb(107 114 128); }
        .hv-identidad-datos strong { font-size: 1rem; color: rgb(17 24 39); }
        .dark .hv-identidad-datos strong { color: #fff; }

        /* Stats */
        .hv-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: 1rem; }
        .hv-stat label { display: block; font-size: .7rem; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; color: rgb(107 114 128); }
        .hv-stat strong { display: block; margin-top: .25rem; font-size: 1.35rem; font-weight: 800; color: rgb(17 24 39); }
        .dark .hv-stat strong { color: #fff; }
        .hv-stat small { color: rgb(107 114 128); font-size: .75rem; }
        .hv-stat.hv-ok strong { color: rgb(22 163 74); }
        .hv-stat.hv-mal strong { color: rgb(220 38 38); }

        /* Tablas */
        .hv-titulo { font-size: 1rem; font-weight: 700; margin-bottom: .75rem; color: rgb(17 24 39); }
        .dark .hv-titulo { color: #fff; }
        .hv-vacio { font-size: .875rem; color: rgb(107 114 128); }

        .hv-tabla { width: 100%; border-collapse: collapse; font-size: .85rem; }
        .hv-tabla th {
            text-align: left; padding: .5rem .75rem; font-size: .7rem; font-weight: 600;
            text-transform: uppercase; letter-spacing: .06em; color: rgb(107 114 128);
            border-bottom: 1px solid rgb(0 0 0 / .08);
        }
        .dark .hv-tabla th { border-color: rgb(255 255 255 / .1); }
        .hv-tabla td { padding: .55rem .75rem; border-bottom: 1px solid rgb(0 0 0 / .05); color: rgb(55 65 81); }
        .dark .hv-tabla td { border-color: rgb(255 255 255 / .06); color: rgb(209 213 219); }
        .hv-tabla .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }

        .hv-badge {
            display: inline-block; padding: .1rem .55rem; border-radius: 9999px;
            font-size: .72rem; font-weight: 600; background: rgb(0 0 0 / .06); color: rgb(75 85 99);
        }
        .dark .hv-badge { background: rgb(255 255 255 / .08); color: rgb(209 213 219); }
        .hv-badge-ok { background: rgb(22 163 74 / .12); color: rgb(22 163 74); }
    </style>
</x-filament-panels::page>
