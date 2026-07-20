<x-filament-panels::page>
    {{--
        El CSS del panel es precompilado: las utilidades Tailwind de vistas
        custom NO existen ahí. Todo el estilo de esta página vive en el
        bloque <style> de abajo (clases propias cal-*) + las variables
        nativas de FullCalendar para tematizarlo como Filament.
    --}}
    <div class="cal-pagina">
        <div class="cal-toolbar">
            <div class="cal-filtro">
                <label for="filtro-maquina">Máquina</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select id="filtro-maquina" wire:model.live="maquinaId">
                        <option value="">Todas las máquinas</option>
                        @foreach ($maquinas as $id => $nombre)
                            <option value="{{ $id }}">{{ $nombre }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>

            <div class="cal-filtro">
                <label for="filtro-obra">Obra</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select id="filtro-obra" wire:model.live="proyectoId">
                        <option value="">Todas las obras</option>
                        @foreach ($obras as $id => $nombre)
                            <option value="{{ $id }}">{{ $nombre }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>

            {{-- Leyenda: el hueco sin eventos = máquina LIBRE para alquilar.
                 Violeta = llegada confirmada y sigue en la obra (trabajando).
                 Lo YA TRABAJADO no se pinta (decisión 2026-07-20): al
                 registrarse la jornada el evento se retira — la historia
                 vive en Partes de Trabajo. --}}
            <div class="cal-leyenda">
                <span><i style="background:#2563eb"></i> Programada</span>
                <span><i style="background:#dc2626"></i> Sin confirmar</span>
                <span><i style="background:#7c3aed"></i> Trabajando</span>
                <span><i style="background:#0d9488"></i> Asignación</span>
                <span><i style="background:#d97706"></i> Mantenimiento</span>
                <span><i style="background:#9ca3af"></i> Finalizada</span>
            </div>
        </div>

        <div class="cal-lienzo">
            @livewire(\App\Filament\Widgets\CalendarioMaquinariaWidget::class)
        </div>
    </div>

    <style>
        /* ── Layout de la página ─────────────────────────────────── */
        .cal-pagina { display: flex; flex-direction: column; gap: 1rem; }

        .cal-toolbar {
            display: flex; flex-wrap: wrap; align-items: end; gap: 1rem;
        }

        .cal-filtro { width: 16rem; }

        .cal-filtro label {
            display: block; margin-bottom: .375rem;
            font-size: .75rem; font-weight: 600; letter-spacing: .05em;
            text-transform: uppercase; color: rgb(107 114 128);
        }

        .cal-leyenda {
            margin-inline-start: auto; display: flex; gap: 1.25rem;
            font-size: .875rem; color: rgb(107 114 128);
        }

        .cal-leyenda span { display: inline-flex; align-items: center; gap: .4rem; }

        .cal-leyenda i {
            width: .65rem; height: .65rem; border-radius: 9999px; display: inline-block;
        }

        /* Tarjeta contenedora del calendario, como una Section de Filament */
        .cal-lienzo {
            border-radius: .75rem; padding: 1.25rem;
            background: #fff; box-shadow: 0 1px 2px rgb(0 0 0 / .06);
            border: 1px solid rgb(0 0 0 / .06);
        }

        .dark .cal-lienzo {
            background: rgb(24 24 27); border-color: rgb(255 255 255 / .08);
        }

        /* ── FullCalendar: tema Filament (claro + oscuro) ─────────── */
        .cal-lienzo .fc {
            --fc-border-color: rgb(0 0 0 / .07);
            --fc-page-bg-color: transparent;
            --fc-neutral-bg-color: transparent;
            --fc-today-bg-color: rgb(245 158 11 / .08);
            --fc-button-bg-color: transparent;
            --fc-button-border-color: rgb(0 0 0 / .12);
            --fc-button-text-color: rgb(55 65 81);
            --fc-button-hover-bg-color: rgb(0 0 0 / .05);
            --fc-button-hover-border-color: rgb(0 0 0 / .18);
            --fc-button-active-bg-color: rgb(245 158 11);
            --fc-button-active-border-color: rgb(245 158 11);
            --fc-event-border-color: transparent;
            font-size: .875rem;
        }

        .dark .cal-lienzo .fc {
            --fc-border-color: rgb(255 255 255 / .08);
            --fc-today-bg-color: rgb(245 158 11 / .12);
            --fc-button-border-color: rgb(255 255 255 / .14);
            --fc-button-text-color: rgb(209 213 219);
            --fc-button-hover-bg-color: rgb(255 255 255 / .06);
            --fc-button-hover-border-color: rgb(255 255 255 / .22);
        }

        /* Título del mes y botones al estilo del panel */
        .cal-lienzo .fc .fc-toolbar-title {
            font-size: 1.125rem; font-weight: 700; text-transform: capitalize;
            color: rgb(17 24 39);
        }

        .dark .cal-lienzo .fc .fc-toolbar-title { color: #fff; }

        .cal-lienzo .fc .fc-button {
            border-radius: .5rem; padding: .375rem .75rem;
            font-size: .8125rem; font-weight: 600; box-shadow: none;
            text-transform: capitalize;
        }

        .cal-lienzo .fc .fc-button:focus { box-shadow: none; }

        .cal-lienzo .fc .fc-button-active { color: #fff !important; }

        /* Encabezado de días de la semana */
        .cal-lienzo .fc .fc-col-header-cell {
            padding: .5rem 0; background: transparent;
        }

        .cal-lienzo .fc .fc-col-header-cell-cushion {
            font-size: .7rem; font-weight: 600; letter-spacing: .08em;
            text-transform: uppercase; color: rgb(107 114 128);
        }

        /* Números de día */
        .cal-lienzo .fc .fc-daygrid-day-number {
            font-size: .8125rem; padding: .35rem .5rem; color: rgb(75 85 99);
        }

        .dark .cal-lienzo .fc .fc-daygrid-day-number { color: rgb(156 163 175); }

        .cal-lienzo .fc .fc-day-other .fc-daygrid-day-number { opacity: .35; }

        .cal-lienzo .fc .fc-day-today .fc-daygrid-day-number {
            font-weight: 700; color: rgb(245 158 11);
        }

        /* Eventos: barras redondeadas y legibles */
        .cal-lienzo .fc .fc-event {
            border-radius: .375rem; padding: .1rem .45rem;
            font-size: .78rem; font-weight: 600; border: none;
            cursor: pointer; /* azul/teal abren "Registrar jornada" en modal, sin navegar */
        }

        .cal-lienzo .fc .fc-event:hover { filter: brightness(1.08); }

        .cal-lienzo .fc .fc-daygrid-day-frame { min-height: 5.5rem; }

        /* Vista de lista (Semana) */
        .cal-lienzo .fc .fc-list {
            border-radius: .5rem; overflow: hidden;
        }

        .dark .cal-lienzo .fc .fc-list-day-cushion { background: rgb(255 255 255 / .04); }

        @media (max-width: 640px) {
            .cal-filtro { width: 100%; }
            .cal-leyenda { margin-inline-start: 0; }
        }
    </style>
</x-filament-panels::page>
