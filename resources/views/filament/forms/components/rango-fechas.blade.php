{{--
    Rango de fechas en UN calendario, estilo Filament, sin librerías.

    Comportamiento (patrón aerolínea):
      1er click  → fecha de inicio
      2º click   → fecha de fin (si es anterior al inicio, pasa a ser inicio)
      3er click  → reinicia: nueva fecha de inicio

    Estado Livewire: ['Y-m-d'] o ['Y-m-d', 'Y-m-d'].
    El CSS vive aquí (clases rf-*): el panel es precompilado y no trae
    utilidades Tailwind para vistas custom.
--}}
<style>
    .rf-envoltura { position: relative; }

    /* Disparador con look de input de Filament */
    .rf-trigger {
        display: flex; align-items: center; gap: .5rem; width: 100%;
        padding: .5rem .75rem; font-size: .875rem; border-radius: .5rem;
        border: 1px solid rgb(0 0 0 / .2); background: transparent;
        color: rgb(17 24 39); cursor: pointer; text-align: left;
    }
    .dark .rf-trigger { border-color: rgb(255 255 255 / .2); color: #fff; }
    .rf-trigger:hover { border-color: rgb(245 158 11 / .6); }
    .rf-trigger .rf-placeholder { color: rgb(156 163 175); }
    .rf-trigger b { font-weight: 700; }
    .rf-trigger svg { width: 1.1rem; height: 1.1rem; color: rgb(156 163 175); flex-shrink: 0; }

    /* Popover flotante */
    .rf-pop {
        position: absolute; z-index: 40; margin-top: .5rem; left: 0;
        width: 22rem; max-width: 100%;
    }

    .rf-cal {
        padding: 1.25rem; border-radius: 1rem;
        border: 1px solid rgb(0 0 0 / .08); background: #fff;
        box-shadow: 0 15px 40px rgb(0 0 0 / .2);
    }
    .dark .rf-cal { border-color: rgb(255 255 255 / .12); background: rgb(24 24 27); box-shadow: 0 15px 40px rgb(0 0 0 / .5); }

    .rf-head {
        display: flex; align-items: center; justify-content: space-between;
        margin-bottom: .75rem; font-weight: 700; font-size: .9375rem;
    }

    .rf-nav {
        width: 2.25rem; height: 2.25rem; border-radius: 9999px; font-size: 1.05rem;
        color: rgb(107 114 128); background: transparent;
        border: 1px solid rgb(0 0 0 / .12); cursor: pointer;
        display: flex; align-items: center; justify-content: center;
    }
    .dark .rf-nav { border-color: rgb(255 255 255 / .15); color: rgb(212 212 216); }
    .rf-nav:hover:not(:disabled) { background: rgb(0 0 0 / .05); }
    .dark .rf-nav:hover:not(:disabled) { background: rgb(255 255 255 / .08); }
    .rf-nav:disabled { opacity: .25; cursor: default; }

    /* Sin gap horizontal: la banda del rango se ve CONTINUA */
    .rf-grid { display: grid; grid-template-columns: repeat(7, 1fr); row-gap: .375rem; }

    .rf-sem { margin-bottom: .25rem; }
    .rf-sem span {
        text-align: center; font-size: .6875rem; font-weight: 600;
        color: rgb(156 163 175); padding: .25rem 0;
    }

    .rf-celda { display: flex; }

    .rf-dia {
        width: 100%; height: 2.5rem; border: none; background: transparent;
        border-radius: 9999px; font-size: .875rem; cursor: pointer;
        color: rgb(55 65 81); display: flex; align-items: center; justify-content: center;
        position: relative;
    }
    .dark .rf-dia { color: rgb(212 212 216); }
    .rf-dia:hover:not(:disabled):not(.rf-sel):not(.rf-rango) { background: rgb(0 0 0 / .06); }
    .dark .rf-dia:hover:not(:disabled):not(.rf-sel):not(.rf-rango) { background: rgb(255 255 255 / .1); }
    .rf-dia:disabled { opacity: .3; cursor: default; }

    /* Hoy: puntito ámbar bajo el número */
    .rf-dia.rf-hoy::after {
        content: ''; position: absolute; bottom: .3rem; width: .25rem; height: .25rem;
        border-radius: 9999px; background: rgb(245 158 11);
    }
    .rf-dia.rf-sel.rf-hoy::after { background: #fff; }

    /* Banda continua del rango (patrón aerolínea) */
    .rf-dia.rf-rango {
        background: rgb(245 158 11 / .16); border-radius: 0;
    }
    .dark .rf-dia.rf-rango { background: rgb(245 158 11 / .22); }
    .rf-dia.rf-rango:hover:not(:disabled) { background: rgb(245 158 11 / .3); }

    .rf-dia.rf-sel {
        background: rgb(245 158 11); color: #fff; font-weight: 700;
    }
    .rf-dia.rf-sel:hover:not(:disabled) { background: rgb(217 119 6); }
    .rf-dia.rf-ini { border-radius: 9999px 0 0 9999px; }
    .rf-dia.rf-fin { border-radius: 0 9999px 9999px 0; }
    .rf-dia.rf-solo { border-radius: 9999px; }
</style>

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-data="{
            {{-- .live cuando el campo lo declara: el rango debe llegar al servidor
     al momento (visibilidad del toggle de horas por día, hora sugerida,
     depuración por mantenimiento). Sin .live queda diferido y el form
     no reacciona hasta que otro campo dispara una petición. --}}
            state: $wire.$entangle('{{ $getStatePath() }}')@if ($field->isLive()).live @endif,
            vista: null,
            hoy: null,
            abierto: false,

            init() {
                this.hoy = new Date();
                this.hoy.setHours(0, 0, 0, 0);

                const base = (Array.isArray(this.state) && this.state[0])
                    ? new Date(this.state[0] + 'T00:00:00')
                    : this.hoy;

                this.vista = new Date(base.getFullYear(), base.getMonth(), 1);
            },

            get desde() { return (Array.isArray(this.state) && this.state[0]) ? this.state[0] : null; },
            get hasta() { return (Array.isArray(this.state) && this.state[1]) ? this.state[1] : null; },

            fmt(d) {
                return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
            },

            bonita(s) { const [y, m, d] = s.split('-'); return d + '/' + m + '/' + y; },

            get titulo() {
                const mes = this.vista.toLocaleDateString('es', { month: 'long' });

                return mes.charAt(0).toUpperCase() + mes.slice(1) + ' ' + this.vista.getFullYear();
            },

            get resumen() {
                if (! this.desde) return 'Elige el <b>primer día</b>';
                if (! this.hasta) return 'Del <b>' + this.bonita(this.desde) + '</b> — ahora elige el último día';

                return this.desde === this.hasta
                    ? 'Solo el <b>' + this.bonita(this.desde) + '</b>'
                    : 'Del <b>' + this.bonita(this.desde) + '</b> al <b>' + this.bonita(this.hasta) + '</b>';
            },

            get celdas() {
                const y = this.vista.getFullYear(), m = this.vista.getMonth();
                const offset = (new Date(y, m, 1).getDay() + 6) % 7; // lunes = 0
                const dias = new Date(y, m + 1, 0).getDate();
                const c = [];

                for (let i = 0; i < offset; i++) c.push(null);
                for (let d = 1; d <= dias; d++) c.push(new Date(y, m, d));

                return c;
            },

            deshabilitado(d) { return d < this.hoy; },

            clase(d) {
                const f = this.fmt(d);
                let clases = 'rf-dia';
                const rangoCompleto = this.desde && this.hasta && this.desde !== this.hasta;

                if (f === this.desde && f === this.hasta) clases += ' rf-sel rf-solo';
                else if (f === this.desde) clases += rangoCompleto ? ' rf-sel rf-ini' : ' rf-sel rf-solo';
                else if (f === this.hasta) clases += ' rf-sel rf-fin';
                else if (rangoCompleto && f > this.desde && f < this.hasta) clases += ' rf-rango';

                if (this.fmt(this.hoy) === f) clases += ' rf-hoy';

                return clases;
            },

            elegir(d) {
                if (this.deshabilitado(d)) return;

                const f = this.fmt(d);

                // Sin inicio, o rango ya completo → este click ARRANCA de nuevo.
                if (! this.desde || this.hasta) { this.state = [f]; return; }

                // Antes del inicio → pasa a ser el nuevo inicio.
                if (f < this.desde) { this.state = [f]; return; }

                this.state = [this.desde, f];

                // Rango completo: se cierra solito (con un respiro para
                // ver la banda seleccionada).
                setTimeout(() => { this.abierto = false; }, 350);
            },

            mesAnterior() { this.vista = new Date(this.vista.getFullYear(), this.vista.getMonth() - 1, 1); },
            mesSiguiente() { this.vista = new Date(this.vista.getFullYear(), this.vista.getMonth() + 1, 1); },
            get puedeRetroceder() { return this.vista > new Date(this.hoy.getFullYear(), this.hoy.getMonth(), 1); },
        }"
        wire:ignore
    >
        <div class="rf-envoltura" x-on:click.outside="abierto = false">
            {{-- Disparador: look de input; abre/cierra el calendario --}}
            <button type="button" class="rf-trigger" x-on:click="abierto = ! abierto">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
                </svg>
                <span x-show="! desde" class="rf-placeholder">Del … al …</span>
                <span x-show="desde" x-html="resumen"></span>
            </button>

            <div class="rf-pop" x-show="abierto" x-transition.opacity.duration.150ms x-cloak>
        <div class="rf-cal">
            <div class="rf-head">
                <button type="button" class="rf-nav" x-on:click="mesAnterior()" :disabled="! puedeRetroceder">‹</button>
                <span x-text="titulo"></span>
                <button type="button" class="rf-nav" x-on:click="mesSiguiente()">›</button>
            </div>

            <div class="rf-grid rf-sem">
                <span>Lun</span><span>Mar</span><span>Mié</span><span>Jue</span><span>Vie</span><span>Sáb</span><span>Dom</span>
            </div>

            <div class="rf-grid">
                <template x-for="(d, i) in celdas" :key="vista.getMonth() + '-' + i">
                    <div class="rf-celda">
                        <template x-if="d">
                            <button
                                type="button"
                                x-text="d.getDate()"
                                :class="clase(d)"
                                :disabled="deshabilitado(d)"
                                x-on:click="elegir(d)"
                            ></button>
                        </template>
                    </div>
                </template>
            </div>
        </div>
            </div>
        </div>
    </div>
</x-dynamic-component>
