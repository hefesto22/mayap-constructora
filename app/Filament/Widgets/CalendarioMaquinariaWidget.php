<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\DestinoAgendaFutura;
use App\Enums\DestinoSalidaMaquina;
use App\Enums\EstadoAsignacion;
use App\Enums\EstadoMantenimiento;
use App\Enums\EstadoMaquina;
use App\Enums\LugarReparacion;
use App\Enums\ModalidadTrabajo;
use App\Enums\OrigenGastoReparacion;
use App\Enums\PrioridadMantenimiento;
use App\Exceptions\Inventario\InventarioException;
use App\Exceptions\Maquinaria\MaquinariaException;
use App\Filament\Actions\AgendarMaquinasAction;
use App\Filament\Resources\Mantenimientos\MantenimientoMaquinaResource;
use App\Filament\Resources\Operadores\Schemas\OperadorForm;
use App\Models\AgendaMaquina;
use App\Models\AsignacionMaquina;
use App\Models\BitacoraMantenimiento;
use App\Models\Bodega;
use App\Models\Existencia;
use App\Models\MantenimientoMaquina;
use App\Models\Maquina;
use App\Models\Material;
use App\Models\Operador;
use App\Models\ParteTrabajo;
use App\Models\Proyecto;
use App\Models\User;
use App\Services\Inventario\RegistrarEntregaContenedorService;
use App\Services\Maquinaria\AsignarMaquinaService;
use App\Services\Maquinaria\CalendarioMaquinariaService;
use App\Services\Maquinaria\ConfirmarLlegadaService;
use App\Services\Maquinaria\MantenimientoService;
use App\Services\Maquinaria\MarcarNoLlegoAgendaService;
use App\Services\Maquinaria\RegistrarDiaMaquinaService;
use App\Services\Maquinaria\RegistrarGastoReparacionService;
use App\Support\Cantidad;
use App\Support\Permisos;
use App\Support\Roles;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\GridDirection;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\On;
use Override;
use Saade\FilamentFullCalendar\Widgets\FullCalendarWidget;

/**
 * Widget FullCalendar (plugin oficial de Filament) del calendario de
 * maquinaria — lo renderiza la página CalendarioMaquinaria, que también
 * le manda los filtros. Los eventos salen del CalendarioMaquinariaService
 * (única fuente: asignaciones + mantenimientos).
 */
class CalendarioMaquinariaWidget extends FullCalendarWidget
{
    public ?int $maquinaId = null;

    public ?int $proyectoId = null;

    /**
     * Solo vive dentro de la página del calendario — fuera del dashboard
     * (el descubridor de widgets lo registraría ahí si no).
     */
    #[Override]
    public static function canView(): bool
    {
        return false;
    }

    /**
     * Sin acciones de cabecera: las asignaciones y mantenimientos se crean
     * en sus propios recursos — el calendario es la vista, no el capturador.
     *
     * @return array<int, mixed>
     */
    #[Override]
    protected function headerActions(): array
    {
        return [];
    }

    /**
     * Click en un evento = trabajar CON esa máquina, sin salir del
     * calendario (decisión Mauricio 2026-07-13, ciclo 2026-07-16):
     *
     *  - Agendada (azul/violeta) → el CICLO del día, igual para
     *    todos los roles con permiso sobre esa obra: AZUL confirma la
     *    llegada → VIOLETA "¿ya terminó?" → al terminar se abre
     *    "Registrar jornada" (horas, litros) — sigue VIOLETA hasta que
     *    el parte registrado (verde) lo reemplaza.
     *  - Teal (asignación activa) → "Registrar jornada" clásico, para
     *    la fecha de HOY (solo maquinaria/gerencia).
     *  - Verde (ya trabajado) y ámbar (mantenimiento): informativos, nada.
     *
     * Nunca navega a otra pantalla.
     *
     * @param array<string, mixed> $event
     */
    #[Override]
    public function onEventClick(array $event): void
    {
        $id = (string) ($event['id'] ?? '');

        if (str_starts_with($id, 'agenda-')) {
            $agendado = AgendaMaquina::with(['maquina:id,nombre', 'proyecto:id,nombre'])
                ->find((int) substr($id, 7));

            if ($agendado === null) {
                return;
            }

            // El MISMO ciclo para todos: encargado, maquinaria y gerencia
            // (el permiso por obra lo valida el propio flujo).
            $this->montarConfirmarLlegada($agendado);

            return;
        }

        if (str_starts_with($id, 'asignacion-')) {
            $this->abrirAsignacion((int) substr($id, 11));

            return;
        }

        if (str_starts_with($id, 'mantenimiento-')) {
            $this->abrirMantenimiento((int) substr($id, 14));
        }
    }

    /**
     * Barra teal de asignación: lleva a registrar la jornada del día.
     * Si quien pulsa no captura jornadas, o la asignación ya se cerró,
     * se dice por qué en vez de tragarse el clic (decisión Mauricio
     * 2026-08-07: en el cockpit del módulo un clic mudo se lee como
     * pantalla rota).
     */
    private function abrirAsignacion(int $asignacionId): void
    {
        $asignacion = AsignacionMaquina::with(['maquina:id,nombre', 'proyecto:id,nombre'])
            ->find($asignacionId);

        if ($asignacion === null) {
            return;
        }

        $etiqueta = "{$asignacion->maquina->nombre} → {$asignacion->proyecto->nombre}";

        if (! (auth()->user()?->can(Permisos::REGISTRAR_JORNADA_MAQUINA) ?? false)) {
            $this->avisar(
                'Aquí solo se consulta',
                "{$etiqueta}: registrar las horas y el combustible del día le toca a maquinaria.",
            );

            return;
        }

        if ($asignacion->estado !== EstadoAsignacion::Activa) {
            $this->avisar(
                'Esta asignación ya se cerró',
                "{$etiqueta} terminó el "
                .($asignacion->fecha_fin?->format('d/m/Y') ?? 'día registrado')
                .'. La jornada solo se captura sobre asignaciones activas.',
            );

            return;
        }

        $this->montarRegistrarDia(
            maquinaId: $asignacion->maquina_id,
            proyectoId: $asignacion->proyecto_id,
            etiqueta: $etiqueta,
            fecha: today()->toDateString(),
        );
    }

    /**
     * Bloque ámbar de mantenimiento: no hay jornada que capturar, pero
     * sí se explica en qué va la reparación y por qué esa máquina no se
     * puede agendar — con enlace al expediente para quien pueda verlo.
     */
    private function abrirMantenimiento(int $mantenimientoId): void
    {
        $mantenimiento = MantenimientoMaquina::with('maquina:id,nombre')->find($mantenimientoId);

        if ($mantenimiento === null) {
            return;
        }

        $fin = $mantenimiento->fecha_fin;

        $notificacion = Notification::make()
            ->title("{$mantenimiento->codigo} · {$mantenimiento->maquina->nombre}")
            ->icon('heroicon-o-wrench-screwdriver');

        if ($fin === null) {
            $notificacion
                ->body('En el taller desde el '.$mantenimiento->fecha_inicio->format('d/m/Y')
                    .' · fase: '.$mantenimiento->fase->getLabel()
                    .' · prioridad: '.$mantenimiento->prioridad->getLabel()
                    .'. Mientras la reparación siga abierta, esta máquina no se puede agendar ni asignar.')
                ->warning();
        } else {
            $notificacion
                ->body('Estuvo en el taller del '.$mantenimiento->fecha_inicio->format('d/m/Y')
                    .' al '.$fin->format('d/m/Y').'. Ya volvió al parque.')
                ->info();
        }

        if (auth()->user()?->can('View:MantenimientoMaquina') ?? false) {
            $notificacion->actions([
                Action::make('ver_reparacion')
                    ->label('Ver la reparación')
                    ->url(MantenimientoMaquinaResource::getUrl('view', ['record' => $mantenimiento]))
                    ->button(),
            ]);
        }

        $notificacion->send();
    }

    /**
     * Aviso corto para los clics que no abren nada: el calendario nunca
     * se queda callado.
     */
    private function avisar(string $titulo, string $cuerpo): void
    {
        Notification::make()
            ->title($titulo)
            ->body($cuerpo)
            ->info()
            ->send();
    }

    /**
     * El CICLO del día de un agendado, para cualquier rol con permiso
     * sobre esa obra: valida ANTES de abrir el modal para hablar claro —
     * sin permiso (dice de quién es el paso), todavía no es el día
     * (aviso), la máquina sigue en OTRA obra (aviso con el dato). Según el punto del ciclo:
     * AZUL "Confirmar llegada" → VIOLETA "¿Ya terminó aquí?" → al terminar
     * se abre "Registrar jornada". Cerrado el ciclo, el click vuelve a
     * ofrecer la jornada mientras no exista el parte (después, el verde
     * reemplaza al evento y esto ya no se alcanza).
     */
    private function montarConfirmarLlegada(AgendaMaquina $agendado): void
    {
        $user = auth()->user();
        $servicio = app(ConfirmarLlegadaService::class);

        if (! $user instanceof User) {
            return;
        }

        // Recepción, bodeguero o el encargado de OTRA obra: el clic ya
        // no se pierde — se dice de quién es el paso y por qué.
        if (! $servicio->puedeConfirmar($agendado, $user)) {
            $this->avisar(
                'Esta llegada la marca quien está en el sitio',
                "{$agendado->maquina->nombre} está agendada a {$agendado->proyecto->nombre} para el "
                .$agendado->fecha->format('d/m/Y')
                .'. La llegada y la salida las confirma el encargado de esa obra, maquinaria o gerencia.',
            );

            return;
        }

        // Ciclo cerrado (llegó y terminó): lo que queda por hacer es la
        // JORNADA — si el evento sigue visible es porque aún no hay
        // parte de ese día.
        if ($agendado->llegada_confirmada_at !== null && $agendado->salida_confirmada_at !== null) {
            $this->mountAction(
                'registrarJornadaSalida',
                $this->argsRegistrarJornada($agendado, $agendado->salida_confirmada_at->toDateString()),
            );

            return;
        }

        // AVERIADA Y PARADA EN LA OBRA: no hay jornada que cerrar — está
        // detenida esperando repuesto. Lo que toca es decir si ya se
        // reparó o si al final hay que llevarla al taller (Mauricio
        // 2026-09-05: "si está en mantenimiento no debería abrir esa
        // ventana").
        if ($agendado->llegada_confirmada_at !== null && $agendado->salida_confirmada_at === null) {
            $enSitio = $this->reparacionEnSitioAbierta($agendado->maquina_id);

            if ($enSitio instanceof MantenimientoMaquina) {
                $this->mountAction('resolverReparacionEnSitio', [
                    'mantenimiento_id'  => $enSitio->id,
                    'agenda_id'         => $agendado->id,
                    'maquina_id'        => $agendado->maquina_id,
                    'maquina_nombre'    => $agendado->maquina->nombre,
                    'proyecto_nombre'   => $agendado->proyecto->nombre,
                    'desde'             => $enSitio->fecha_inicio->toDateString(),
                    'necesita'          => $enSitio->necesita,
                    'urgente'           => $enSitio->prioridad === PrioridadMantenimiento::Urgente,
                    'agendados_futuros' => AgendaMaquina::query()
                        ->where('maquina_id', $agendado->maquina_id)
                        ->whereDate('fecha', '>=', today()->toDateString())
                        ->whereNull('llegada_confirmada_at')
                        ->count(),
                ]);

                return;
            }
        }

        // Adentro de la obra (llegó, no ha salido): cierre del DÍA DE HOY
        // — la estadía puede llevar días abierta, pero lo que se cierra
        // cada tarde es la jornada de hoy.
        if ($agendado->llegada_confirmada_at !== null) {
            $this->mountAction('confirmarSalida', [
                'agenda_id'       => $agendado->id,
                'etiqueta'        => "{$agendado->maquina->nombre} → {$agendado->proyecto->nombre}",
                'maquina_nombre'  => $agendado->maquina->nombre,
                'proyecto_nombre' => $agendado->proyecto->nombre,
                'fecha'           => $agendado->fecha->toDateString(),
                'dia'             => today()->toDateString(),
                'llego'           => $agendado->llegada_confirmada_at->format('g:i A'),
            ]);

            return;
        }

        if ($agendado->fecha->isFuture()) {
            Notification::make()
                ->title('Todavía no es el día')
                ->body('Esa máquina está agendada para el '.$agendado->fecha->format('d/m/Y').' — la llegada se confirma ese día.')
                ->info()
                ->send();

            return;
        }

        // La fecha ya pasó sin confirmación: CONTINGENCIA roja — se
        // resuelve aquí mismo (decisión Mauricio 2026-07-20): llegó
        // tarde, o no llegó y queda la constancia con motivo.
        if ($agendado->fecha->isPast() && ! $agendado->fecha->isToday()) {
            $this->mountAction('resolverAgendaVencida', [
                'agenda_id' => $agendado->id,
                'etiqueta'  => "{$agendado->maquina->nombre} → {$agendado->proyecto->nombre}",
                'fecha'     => $agendado->fecha->toDateString(),
                'hora'      => $agendado->horaEntrada12(),
            ]);

            return;
        }

        // La máquina sigue "adentro" de otra obra: se dice de frente
        // (misma regla que aplica el service al guardar).
        $abierto = $servicio->compromisoAbierto($agendado);

        if ($abierto !== null) {
            Notification::make()
                ->title('La máquina sigue en otra obra')
                ->body("{$agendado->maquina->nombre} sigue trabajando en {$abierto->proyecto->nombre} (llegó ".$abierto->llegada_confirmada_at?->format('g:i A').') — esa obra debe confirmar primero que terminó ahí.')
                ->warning()
                ->send();

            return;
        }

        $this->mountAction('confirmarLlegada', [
            'agenda_id'       => $agendado->id,
            'etiqueta'        => "{$agendado->maquina->nombre} → {$agendado->proyecto->nombre}",
            'maquina_nombre'  => $agendado->maquina->nombre,
            'proyecto_nombre' => $agendado->proyecto->nombre,
            'fecha'           => $agendado->fecha->toDateString(),
            'hora'            => $agendado->horaEntrada12(),
        ]);
    }

    /**
     * Los argumentos que el modal "Registrar jornada" necesita, frescos
     * al momento de abrirlo: la asignación activa (tarifa pactada), los
     * datos de la máquina (jornada estándar y tarifa por defecto) y las
     * horas sugeridas.
     *
     * $dia es el día que se está cerrando — NO la fecha del agendado:
     * con la estadía abierta, la máquina que llegó el lunes sigue en la
     * obra el jueves, y el parte del jueves va con fecha del jueves
     * (2026-09-05). $horasSugeridas gana cuando viene del horómetro: es
     * el dato real; el reloj solo sirve de respaldo el día de llegada.
     *
     * @return array<string, mixed>
     */
    private function argsRegistrarJornada(
        AgendaMaquina $agendado,
        ?string $dia = null,
        ?string $horasSugeridas = null,
        ?string $horometroApertura = null,
    ): array {
        $agendado->loadMissing(['maquina:id,nombre', 'proyecto:id,nombre']);

        $dia ??= $agendado->fecha->toDateString();
        $origenHoras = $horasSugeridas !== null ? 'horometro' : null;

        $asignacionId = AsignacionMaquina::query()
            ->where('maquina_id', $agendado->maquina_id)
            ->where('proyecto_id', $agendado->proyecto_id)
            ->where('estado', EstadoAsignacion::Activa->value)
            ->value('id');

        // modalidad_trabajo VA en el select: sin ella el modelo la
        // rellenaría con su default en memoria ('horas') y la volqueta
        // llegaría al modal como si trabajara por horas.
        $maquinaDatos = Maquina::query()
            ->whereKey($agendado->maquina_id)
            ->first(['horas_dia_renta', 'tarifa_hora', 'modalidad_trabajo', 'litros_por_hora', 'operador_habitual_id']);

        // Sugerencia honesta: el horómetro si lo hay; si no, de la
        // llegada a la salida (o a ahora) redondeado a media hora, y solo
        // el día de llegada — en el día 3 de una estadía ese reloj daría
        // 72 horas. Quien registra lo ajusta a lo real.
        if ($horasSugeridas === null && $dia === $agendado->fecha->toDateString() && $agendado->llegada_confirmada_at !== null) {
            $hasta = $agendado->salida_confirmada_at ?? now();
            $porReloj = round($agendado->llegada_confirmada_at->diffInMinutes($hasta) / 30) / 2;

            $horasSugeridas = $porReloj > 0 ? number_format($porReloj, 1, '.', '') : null;
        }

        // Para el aviso de avería: cuántos agendados PLAN (sin llegada
        // confirmada) resolvería un mantenimiento desde ese día.
        $agendadosFuturos = AgendaMaquina::query()
            ->where('maquina_id', $agendado->maquina_id)
            ->whereDate('fecha', '>=', $dia)
            ->whereNull('llegada_confirmada_at')
            ->count();

        return [
            'agenda_id'            => $agendado->id,
            'maquina_id'           => $agendado->maquina_id,
            'agendados_futuros'    => $agendadosFuturos,
            'etiqueta'             => "{$agendado->maquina->nombre} → {$agendado->proyecto->nombre}",
            'fecha'                => $dia,
            'llego'                => $agendado->llegada_confirmada_at?->format('g:i A'),
            'salio'                => $agendado->salida_confirmada_at?->format('g:i A'),
            'asignacion_id'        => $asignacionId,
            'jornada_maquina'      => $maquinaDatos?->horas_dia_renta !== null ? (string) $maquinaDatos->horas_dia_renta : null,
            'litros_hora_maquina'  => $maquinaDatos?->litros_por_hora !== null ? (string) $maquinaDatos->litros_por_hora : null,
            'tarifa_maquina'       => $maquinaDatos?->tarifa_hora !== null ? (string) $maquinaDatos->tarifa_hora : null,
            'modalidad_maquina'    => $maquinaDatos?->modalidad_trabajo->value,
            'operador_habitual_id' => $maquinaDatos?->operador_habitual_id,
            'maquina_nombre'       => $agendado->maquina->nombre,
            'proyecto_nombre'      => $agendado->proyecto->nombre,
            // Con qué horómetro abrió y cerró el día: de su diferencia
            // salen las horas de motor y, con ellas, los litros.
            'usa_horometro'      => $maquinaDatos?->modalidad_trabajo === ModalidadTrabajo::Horas,
            'horometro_apertura' => $horometroApertura ?? ($agendado->horometro_llegada !== null
                ? (string) $agendado->horometro_llegada
                : null),
            'horometro_cierre' => $agendado->horometro_salida !== null
                ? (string) $agendado->horometro_salida
                : null,
            'horas_sugeridas' => $horasSugeridas,
            'horas_origen'    => $origenHoras ?? ($horasSugeridas !== null ? 'reloj' : null),
            // El contenedor que carga la máquina (pipa, cisterna): con
            // cuánto salió ya lo sabe el sistema —es su existencia—, así
            // que lo único que falta preguntar es con cuánto volvió.
            ...$this->datosContenedor($agendado->maquina_id),
        ];
    }

    /**
     * ¿Esta máquina carga un contenedor? (Mauricio 2026-09-10).
     *
     * Devuelve lo necesario para preguntar el regreso en el cierre del
     * día. Si no carga nada, devuelve los campos en null y el bloque del
     * modal ni aparece.
     *
     * @return array<string, mixed>
     */
    private function datosContenedor(int $maquinaId): array
    {
        $contenedor = Bodega::query()
            ->moviles()
            ->where('activo', true)
            ->where('maquina_id', $maquinaId)
            ->with('material:id,nombre,unidad_medida_id', 'material.unidadMedida:id,codigo')
            ->first();

        if (! $contenedor instanceof Bodega) {
            return [
                'contenedor_id'        => null,
                'contenedor_tipo'      => null,
                'contenedor_nombre'    => null,
                'contenedor_material'  => null,
                'contenedor_unidad'    => null,
                'contenedor_capacidad' => null,
                'contenedor_salio_con' => null,
                'contenedor_carga'     => null,
            ];
        }

        return [
            'contenedor_id' => $contenedor->id,
            // Dos contenedores, dos preguntas distintas: a la pipa se le
            // pregunta CON CUÁNTO vuelve; al camión de reparto, si trae
            // algo encima que haya que bajar a bodega.
            'contenedor_tipo'      => $contenedor->esDeGranel() ? 'granel' : 'reparto',
            'contenedor_nombre'    => $contenedor->nombre,
            'contenedor_material'  => $contenedor->material?->nombre,
            'contenedor_unidad'    => $contenedor->material?->unidadMedida->codigo,
            'contenedor_capacidad' => (string) $contenedor->capacidad,
            'contenedor_salio_con' => $contenedor->contenidoActual(),
            'contenedor_carga'     => $this->resumenDeCarga($contenedor),
        ];
    }

    /**
     * Qué trae encima un camión de reparto, en una línea legible. Null
     * cuando viene vacío: ahí no hay nada que preguntar.
     */
    private function resumenDeCarga(Bodega $contenedor): ?string
    {
        $aBordo = Existencia::query()
            ->where('bodega_id', $contenedor->id)
            ->where('cantidad', '>', 0)
            ->with('material:id,nombre')
            ->get();

        if ($aBordo->isEmpty()) {
            return null;
        }

        return $aBordo
            ->map(fn (Existencia $e): string => Cantidad::sinCeros((string) $e->cantidad)
                .' '.($e->material->nombre ?? 'material'))
            ->implode(' · ');
    }

    /**
     * La tarjeta del contenedor: qué es y con cuánto salió. Ese número no
     * se le pregunta a nadie — es la existencia del contenedor.
     */
    private function fichaContenedor(string $nombre, string $material, string $salioCon, string $unidad): HtmlString
    {
        $llevaba = is_numeric($salioCon) ? Cantidad::sinCeros($salioCon) : '0';

        return new HtmlString(
            '<div class="mayap-aviso"><strong>'.e($nombre).'</strong> salió con <strong>'
            .e($llevaba).' '.e($unidad).'</strong> de '.e($material)
            .'. Lo que no traiga de vuelta queda cargado a esta obra, con su costo.</div>'
        );
    }

    /**
     * Los atajos del regreso, para no teclear un número en el caso normal.
     *
     * "A la mitad" solo se ofrece si el contenedor SALIÓ con al menos media
     * carga: si salió con menos, ese botón devolvería más de lo que llevaba
     * y el servicio lo rechazaría — mejor no ofrecer lo imposible.
     *
     * @return array<string, string>
     */
    private function opcionesRegresoContenedor(Get $get): array
    {
        $opciones = ['vacio' => 'Vacía — descargó todo'];

        $llevaba = (string) $get('contenedor_salio_con');
        $capacidad = (string) $get('contenedor_capacidad');

        if (is_numeric($llevaba) && is_numeric($capacidad) && bccomp($capacidad, '0', 4) > 0) {
            $mitad = bcdiv($capacidad, '2', 4);

            if (bccomp($llevaba, $mitad, 4) >= 0) {
                $opciones['mitad'] = 'A la mitad ('.Cantidad::sinCeros($mitad).')';
            }
        }

        $opciones['nada'] = 'No descargó nada';
        $opciones['otro'] = 'Otra cantidad';

        return $opciones;
    }

    /**
     * Traduce el atajo elegido al nivel real de regreso. Null = no hay
     * nada que registrar (no se contestó).
     *
     * @param array<string, mixed> $data
     */
    private function nivelRegresoContenedor(array $data): ?string
    {
        // Solo la pipa se mide por nivel. El camión de reparto lleva
        // varios materiales: su regreso se resuelve devolviendo la carga.
        if (($data['contenedor_tipo'] ?? null) !== 'granel') {
            return null;
        }

        $llevaba = (string) ($data['contenedor_salio_con'] ?? '0');
        $capacidad = (string) ($data['contenedor_capacidad'] ?? '0');
        $escrito = $data['contenedor_nivel'] ?? null;

        return match ($data['contenedor_regreso'] ?? null) {
            'vacio' => '0',
            'nada'  => is_numeric($llevaba) ? $llevaba : '0',
            'mitad' => is_numeric($capacidad) ? bcdiv($capacidad, '2', 4) : null,
            'otro'  => is_numeric($escrito) ? (string) $escrito : null,
            default => null,
        };
    }

    /**
     * REPARACIÓN EN LA OBRA — el segundo tiempo (Mauricio 2026-09-05).
     *
     * Mientras la máquina está parada esperando repuesto no hay jornada
     * que cerrar: lo que el encargado tiene que decir es si ya quedó
     * reparada o si al final hay que llevarla al taller — y por qué no
     * se pudo, que es lo que el taller necesita saber antes de recibirla.
     */
    public function resolverReparacionEnSitioAction(): Action
    {
        return Action::make('resolverReparacionEnSitio')
            ->modalHeading('Reparación en la obra')
            ->modalDescription('La máquina está parada esperando su reparación. Mientras tanto no se puede agendar a otra obra.')
            ->modalWidth('lg')
            ->modalSubmitActionLabel('Guardar')
            ->schema([
                Placeholder::make('resumen')
                    ->hiddenLabel()
                    ->content(fn (Get $get): HtmlString => $this->fichaMaquina((string) $get('maquina_nombre'), (string) $get('proyecto_nombre'), [
                        'Averiada desde' => e(ucfirst(Carbon::parse((string) $get('desde'))->translatedFormat('D d/m/Y')))
                            .((bool) $get('urgente') ? ' <small>· URGENTE</small>' : ''),
                        'Necesita' => filled($get('necesita'))
                            ? e((string) $get('necesita'))
                            : '<small>no se especificó</small>',
                    ]))
                    ->columnSpanFull(),

                ToggleButtons::make('resultado')
                    ->label('¿Cómo terminó?')
                    ->options([
                        'reparada' => 'Ya quedó reparada',
                        'taller'   => 'No se pudo: va al taller',
                    ])
                    ->colors(['reparada' => 'success', 'taller' => 'danger'])
                    ->icons(['reparada' => 'heroicon-o-check-circle', 'taller' => 'heroicon-o-truck'])
                    ->default('reparada')
                    ->required()
                    ->live()
                    ->columns(2)
                    ->gridDirection(GridDirection::Row)
                    ->extraAttributes(['class' => 'mayap-destino'])
                    ->helperText(fn (Get $get): string => $get('resultado') === 'taller'
                        ? 'La máquina deja la obra: se cierra su estadía y hay que decidir con qué se cubren los días comprometidos.'
                        : 'Vuelve a estar disponible y sigue trabajando en esta obra desde ya.')
                    ->columnSpanFull(),

                Textarea::make('detalle')
                    ->label('¿Qué se le hizo?')
                    ->rows(2)
                    ->placeholder('Se cambió la pieza, se ajustó…')
                    ->visible(fn (Get $get): bool => $get('resultado') !== 'taller')
                    ->helperText('Opcional, pero queda en la bitácora de la máquina.')
                    ->columnSpanFull(),

                // Lo que costó (Mauricio 2026-09-05): si el encargado
                // compró en la obra, lo anota él y a recepción le queda
                // pendiente respaldarlo; si no compró nada, no molesta.
                Toggle::make('hubo_gasto')
                    ->label('¿Se usó algún repuesto o material?')
                    ->live()
                    ->inline(false)
                    ->columnSpanFull(),

                // Comprado o de bodega: no es lo mismo. Lo de bodega ya
                // está pagado y lo que toca es BAJARLO del inventario.
                ToggleButtons::make('gasto_origen')
                    ->label('¿De dónde salió?')
                    ->options([
                        OrigenGastoReparacion::Encargado->value => 'Se compró',
                        OrigenGastoReparacion::Bodega->value    => 'Salió de bodega',
                    ])
                    ->colors([
                        OrigenGastoReparacion::Encargado->value => 'warning',
                        OrigenGastoReparacion::Bodega->value    => 'info',
                    ])
                    ->icons([
                        OrigenGastoReparacion::Encargado->value => 'heroicon-o-shopping-bag',
                        OrigenGastoReparacion::Bodega->value    => 'heroicon-o-archive-box',
                    ])
                    ->default(OrigenGastoReparacion::Encargado->value)
                    ->live()
                    ->required(fn (Get $get): bool => (bool) $get('hubo_gasto'))
                    ->visible(fn (Get $get): bool => (bool) $get('hubo_gasto'))
                    ->columns(2)
                    ->gridDirection(GridDirection::Row)
                    ->extraAttributes(['class' => 'mayap-destino'])
                    ->columnSpanFull(),

                TextInput::make('gasto_descripcion')
                    ->label('¿Qué se compró?')
                    ->maxLength(255)
                    ->placeholder('Set de puntas, manguera hidráulica…')
                    ->prefixIcon('heroicon-o-shopping-bag')
                    ->visible(fn (Get $get): bool => (bool) $get('hubo_gasto')
                        && $get('gasto_origen') !== OrigenGastoReparacion::Bodega->value)
                    ->required(fn (Get $get): bool => (bool) $get('hubo_gasto')
                        && $get('gasto_origen') !== OrigenGastoReparacion::Bodega->value)
                    ->columnSpanFull(),

                TextInput::make('gasto_monto')
                    ->label('¿Cuánto costó?')
                    ->numeric()
                    ->minValue(0)
                    ->prefix('L.')
                    ->visible(fn (Get $get): bool => (bool) $get('hubo_gasto')
                        && $get('gasto_origen') !== OrigenGastoReparacion::Bodega->value)
                    ->required(fn (Get $get): bool => (bool) $get('hubo_gasto')
                        && $get('gasto_origen') !== OrigenGastoReparacion::Bodega->value)
                    ->helperText('Se carga al historial de esta máquina y queda anotado en qué obra pasó. Recepción respalda la factura y decide si además se le carga al proyecto.')
                    ->columnSpanFull(),

                // De bodega: qué salió y de dónde. El costo NO se
                // pregunta — lo pone el costo promedio del inventario.
                Select::make('gasto_bodega_id')
                    ->label('¿De qué bodega salió?')
                    ->options(fn (): array => Bodega::query()
                        ->activas()
                        ->orderBy('nombre')
                        ->pluck('nombre', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->live()
                    ->visible(fn (Get $get): bool => (bool) $get('hubo_gasto')
                        && $get('gasto_origen') === OrigenGastoReparacion::Bodega->value)
                    ->required(fn (Get $get): bool => (bool) $get('hubo_gasto')
                        && $get('gasto_origen') === OrigenGastoReparacion::Bodega->value)
                    ->prefixIcon('heroicon-o-building-storefront')
                    ->columnSpanFull(),

                Select::make('gasto_material_id')
                    ->label('¿Qué se usó?')
                    ->options(fn (Get $get): array => $this->materialesConExistencia($get('gasto_bodega_id')))
                    ->searchable()
                    ->visible(fn (Get $get): bool => (bool) $get('hubo_gasto')
                        && $get('gasto_origen') === OrigenGastoReparacion::Bodega->value)
                    ->required(fn (Get $get): bool => (bool) $get('hubo_gasto')
                        && $get('gasto_origen') === OrigenGastoReparacion::Bodega->value)
                    ->prefixIcon('heroicon-o-cube')
                    ->helperText('Solo aparece lo que HAY en esa bodega, con su existencia.')
                    ->columnSpanFull(),

                TextInput::make('gasto_cantidad')
                    ->label('Cantidad usada')
                    ->numeric()
                    ->minValue(0)
                    ->step('any')
                    ->visible(fn (Get $get): bool => (bool) $get('hubo_gasto')
                        && $get('gasto_origen') === OrigenGastoReparacion::Bodega->value)
                    ->required(fn (Get $get): bool => (bool) $get('hubo_gasto')
                        && $get('gasto_origen') === OrigenGastoReparacion::Bodega->value)
                    ->helperText('Baja de la existencia al guardar. El costo lo pone el inventario, no hay que escribirlo.')
                    ->columnSpanFull(),

                Textarea::make('motivo_taller')
                    ->label('¿Por qué no se pudo reparar aquí?')
                    ->rows(2)
                    ->placeholder('El daño es mayor, falta equipo, hay que desarmarla…')
                    ->visible(fn (Get $get): bool => $get('resultado') === 'taller')
                    ->required(fn (Get $get): bool => $get('resultado') === 'taller')
                    ->helperText('Va con la máquina al taller: es lo primero que van a querer saber.')
                    ->columnSpanFull(),

                Radio::make('destino_agenda')
                    ->label('¿Con qué se cubren los días ya agendados?')
                    ->options($this->destinosSiSaleDeLaObra())
                    ->descriptions(DestinoAgendaFutura::descripciones())
                    ->default(DestinoAgendaFutura::Cancelar->value)
                    ->live()
                    ->visible(fn (Get $get): bool => $get('resultado') === 'taller' && (int) $get('agendados_futuros') > 0)
                    ->columnSpanFull(),

                Select::make('sustituta_id')
                    ->label('¿Qué máquina mandan en su lugar?')
                    ->options(fn (Get $get) => Maquina::query()
                        ->activas()
                        ->where('estado', EstadoMaquina::Disponible->value)
                        ->whereKeyNot((int) $get('maquina_id'))
                        ->orderBy('nombre')
                        ->pluck('nombre', 'id'))
                    ->searchable()
                    ->visible(fn (Get $get): bool => $get('resultado') === 'taller'
                        && ((int) $get('agendados_futuros') === 0
                            || $get('destino_agenda') === DestinoAgendaFutura::Sustituta->value))
                    ->required(fn (Get $get): bool => $get('resultado') === 'taller'
                        && (int) $get('agendados_futuros') > 0
                        && $get('destino_agenda') === DestinoAgendaFutura::Sustituta->value)
                    ->helperText('Toma su lugar en la obra y hereda los días agendados.')
                    ->columnSpanFull(),

                Hidden::make('mantenimiento_id'),
                Hidden::make('agenda_id'),
                Hidden::make('maquina_id'),
                Hidden::make('maquina_nombre'),
                Hidden::make('proyecto_nombre'),
                Hidden::make('desde'),
                Hidden::make('necesita'),
                Hidden::make('urgente'),
                Hidden::make('agendados_futuros'),
            ])
            ->fillForm(fn (array $arguments): array => [
                'mantenimiento_id'  => $arguments['mantenimiento_id'] ?? null,
                'agenda_id'         => $arguments['agenda_id'] ?? null,
                'maquina_id'        => $arguments['maquina_id'] ?? null,
                'maquina_nombre'    => $arguments['maquina_nombre'] ?? '',
                'proyecto_nombre'   => $arguments['proyecto_nombre'] ?? '',
                'desde'             => $arguments['desde'] ?? today()->toDateString(),
                'necesita'          => $arguments['necesita'] ?? null,
                'urgente'           => (bool) ($arguments['urgente'] ?? false),
                'agendados_futuros' => $arguments['agendados_futuros'] ?? 0,
                'resultado'         => 'reparada',
                'detalle'           => null,
                'hubo_gasto'        => false,
                'gasto_origen'      => OrigenGastoReparacion::Encargado->value,
                'gasto_descripcion' => null,
                'gasto_monto'       => null,
                'gasto_bodega_id'   => null,
                'gasto_material_id' => null,
                'gasto_cantidad'    => null,
                'motivo_taller'     => null,
                'destino_agenda'    => DestinoAgendaFutura::Cancelar->value,
                'sustituta_id'      => null,
            ])
            ->action(function (array $data): void {
                $mantenimiento = MantenimientoMaquina::find((int) ($data['mantenimiento_id'] ?? 0));
                $user = auth()->user();

                if ($mantenimiento === null || ! $user instanceof User) {
                    return;
                }

                try {
                    // El gasto se anota ANTES de cerrar: con el
                    // mantenimiento finalizado la bitácora es historia.
                    $this->registrarGastoSiHubo($mantenimiento, $data, $user);

                    if (($data['resultado'] ?? 'reparada') === 'taller') {
                        $this->mandarAlTaller($mantenimiento, $data, $user);

                        return;
                    }

                    app(MantenimientoService::class)->finalizar($mantenimiento, null, $user->id);

                    if (filled($data['detalle'] ?? null)) {
                        BitacoraMantenimiento::create([
                            'mantenimiento_maquina_id' => $mantenimiento->id,
                            'fase'                     => $mantenimiento->fase,
                            'detalle'                  => 'Reparada en la obra: '.$data['detalle'],
                            'user_id'                  => $user->id,
                        ]);
                    }
                } catch (MaquinariaException $e) {
                    Notification::make()
                        ->title('No se pudo cerrar la reparación')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Reparada — la máquina vuelve a trabajar')
                    ->body("{$mantenimiento->maquina->nombre} sigue en la obra y ya se le puede cerrar el día normalmente.")
                    ->success()
                    ->send();

                $this->refreshRecords();
            });
    }

    /**
     * Si el encargado compró algo en la obra, se anota como gasto de la
     * máquina y le queda PENDIENTE a recepción respaldarlo.
     *
     * @param array<string, mixed> $datos
     */
    private function registrarGastoSiHubo(MantenimientoMaquina $mantenimiento, array $datos, User $user): void
    {
        if (! ($datos['hubo_gasto'] ?? false)) {
            return;
        }

        $origen = OrigenGastoReparacion::tryFrom((string) ($datos['gasto_origen'] ?? ''))
            ?? OrigenGastoReparacion::Encargado;

        if ($origen->esDeBodega()) {
            $material = Material::find((int) ($datos['gasto_material_id'] ?? 0));

            if ($material === null || ! is_numeric($datos['gasto_cantidad'] ?? null)) {
                return;
            }

            app(RegistrarGastoReparacionService::class)->registrar(
                mantenimiento: $mantenimiento,
                descripcion: $material->nombre,
                monto: null,
                origen: $origen,
                usuario: $user,
                materialId: $material->id,
                bodegaId: (int) $datos['gasto_bodega_id'],
                cantidad: (string) $datos['gasto_cantidad'],
            );

            return;
        }

        if (! filled($datos['gasto_descripcion'] ?? null) || ! is_numeric($datos['gasto_monto'] ?? null)) {
            return;
        }

        app(RegistrarGastoReparacionService::class)->registrar(
            mantenimiento: $mantenimiento,
            descripcion: (string) $datos['gasto_descripcion'],
            monto: (string) $datos['gasto_monto'],
            origen: OrigenGastoReparacion::Encargado,
            usuario: $user,
        );
    }

    /**
     * Lo que HAY en esa bodega, con su existencia a la vista. Ofrecer el
     * catálogo entero invita a sacar lo que no está.
     *
     * @return array<int, string>
     */
    private function materialesConExistencia(mixed $bodegaId): array
    {
        if (! is_numeric($bodegaId)) {
            return [];
        }

        return Existencia::query()
            ->with('material:id,nombre,unidad_medida_id')
            ->where('bodega_id', (int) $bodegaId)
            ->where('cantidad', '>', 0)
            ->get()
            ->sortBy(fn (Existencia $e): string => $e->material->nombre)
            ->mapWithKeys(fn (Existencia $e): array => [
                $e->material_id => $e->material->nombre.' ('.rtrim(rtrim((string) $e->cantidad, '0'), '.').' disponibles)',
            ])
            ->all();
    }

    /**
     * La reparación en obra no salió: la máquina sale al taller, deja la
     * obra y la agenda comprometida se resuelve.
     *
     * @param array<string, mixed> $datos
     */
    private function mandarAlTaller(MantenimientoMaquina $mantenimiento, array $datos, User $user): void
    {
        $destino = (int) ($datos['agendados_futuros'] ?? 0) > 0 && filled($datos['destino_agenda'] ?? null)
            ? DestinoAgendaFutura::from((string) $datos['destino_agenda'])
            : null;

        $sustituta = ($destino === null || $destino === DestinoAgendaFutura::Sustituta) && filled($datos['sustituta_id'] ?? null)
            ? Maquina::find((int) $datos['sustituta_id'])
            : null;

        app(MantenimientoService::class)->escalarATaller(
            mantenimiento: $mantenimiento,
            motivo: (string) $datos['motivo_taller'],
            sustituta: $sustituta,
            destinoAgenda: $destino,
            userId: $user->id,
        );

        // Recién ahora deja la obra: la estadía se cierra con destino taller.
        $agendado = AgendaMaquina::find((int) ($datos['agenda_id'] ?? 0));

        if ($agendado !== null && $agendado->salida_confirmada_at === null) {
            app(ConfirmarLlegadaService::class)->confirmarSalida(
                $agendado,
                $user,
                null,
                DestinoSalidaMaquina::Taller,
            );
        }

        Notification::make()
            ->title('Salió al taller')
            ->body("{$mantenimiento->maquina->nombre} dejó la obra. Maquinaria y recepción ya recibieron el aviso con el motivo.")
            ->warning()
            ->send();

        $this->refreshRecords();
    }

    /**
     * PASO 1 — Modal "Cierre del día" (decisión Mauricio 2026-09-05):
     * con cuánto quedó el horómetro y QUÉ PASÓ con la máquina. Si se
     * quedó en la obra, cierra el día y la estadía sigue abierta; si
     * volvió a bodega, salió a otra obra o se fue al taller, la estadía
     * se cierra y la máquina queda libre. En los dos casos ENCADENA el
     * paso 2, "Registrar jornada" (horas y litros del día).
     */
    public function confirmarSalidaAction(): Action
    {
        return Action::make('confirmarSalida')
            ->modalHeading('Cierre del día')
            ->modalDescription('Al guardar se registran las horas y el combustible de la jornada.')
            ->modalWidth('lg')
            ->modalSubmitActionLabel('Guardar cierre del día')
            // El tercer camino del ciclo (decisión Mauricio 2026-07-22):
            // la máquina no terminó — SE AVERIÓ. Sale al taller (la
            // estadía se cierra sí o sí) y el modal de jornada abre con
            // la avería ya encendida.
            ->extraModalFooterActions(fn (array $arguments): array => [
                Action::make('confirmarSalidaAveria')
                    ->label('No terminó: se averió')
                    ->color('danger')
                    ->arguments($arguments)
                    ->action(function (array $arguments): void {
                        // La máquina NO se mueve todavía: cuando alguien
                        // reporta la avería sigue parada en la obra. Si
                        // hay que llevarla al taller lo dirá el modal de
                        // avería (2026-09-05).
                        $this->confirmarSalidaYEncadenar(
                            [...$arguments, 'destino_salida' => DestinoSalidaMaquina::SigueEnObra->value],
                            averia: true,
                        );
                    }),
            ])
            ->schema([
                // Ficha de cabecera: máquina, obra y los dos datos que
                // ubican el cierre (qué día se cierra y desde cuándo está
                // ahí). Estilos propios porque el contenido es HTML y las
                // clases de Tailwind del panel no llegan hasta acá.
                Placeholder::make('resumen')
                    ->hiddenLabel()
                    ->content(fn (Get $get): HtmlString => $this->fichaCierre((string) $get('maquina_nombre'), (string) $get('proyecto_nombre'), (string) $get('dia'), (string) $get('fecha'), (string) $get('llego'), (int) $get('dias_en_obra')))
                    ->columnSpanFull(),

                // Con cuánto cierra el día: contra la lectura de apertura
                // da las horas REALES de motor de esa jornada.
                TextInput::make('horometro_salida')
                    ->label('Horómetro al cerrar el día')
                    ->numeric()
                    ->minValue(0)
                    ->step('any')
                    ->prefixIcon('heroicon-o-clock')
                    ->suffix('h')
                    ->live(debounce: 500)
                    ->required(fn (Get $get): bool => (bool) $get('usa_horometro'))
                    ->visible(fn (Get $get): bool => (bool) $get('usa_horometro'))
                    ->helperText(function (Get $get): HtmlString {
                        $previo = is_numeric($get('horometro_previo')) ? (string) $get('horometro_previo') : null;
                        $cierre = is_numeric($get('horometro_salida')) ? (string) $get('horometro_salida') : null;

                        if ($previo === null) {
                            return new HtmlString('Lo que marca el reloj de horas al terminar el día.');
                        }

                        $motor = $this->horasEntreLecturas($previo, $cierre);

                        return new HtmlString(
                            'El día abrió en <strong>'.e(number_format((float) $previo, 2, '.', '')).' h</strong>'
                            .($motor !== null
                                ? ' · <strong>'.e($motor).' h</strong> de motor en la jornada'
                                : '')
                        );
                    })
                    ->columnSpanFull(),

                // La pregunta que mantiene el mapa vivo: quedarse NO cierra
                // la estadía, las otras tres SÍ. Botones con icono y color
                // (no una lista de radios): en obra esto se contesta con el
                // dedo y de un vistazo.
                ToggleButtons::make('destino_salida')
                    ->label('¿Qué pasó con la máquina?')
                    ->options(DestinoSalidaMaquina::class)
                    ->default(DestinoSalidaMaquina::SigueEnObra->value)
                    ->required()
                    ->live()
                    // 2×2 leyendo de izquierda a derecha, y los botones a
                    // todo el ancho de su celda: el modal es angosto y con
                    // botones al tamaño del texto quedaba medio vacío.
                    ->columns(2)
                    ->gridDirection(GridDirection::Row)
                    ->extraAttributes(['class' => 'mayap-destino'])
                    ->helperText(fn (Get $get): string => $this->destinoDe($get('destino_salida'))?->getDescription()
                        ?? 'De esta respuesta depende si la máquina queda libre o sigue apartada para esta obra.')
                    ->columnSpanFull(),

                Hidden::make('maquina_nombre'),
                Hidden::make('proyecto_nombre'),
                Hidden::make('dias_en_obra'),
                Hidden::make('agenda_id'),
                Hidden::make('etiqueta'),
                Hidden::make('fecha'),
                Hidden::make('dia'),
                Hidden::make('llego'),
                Hidden::make('usa_horometro'),
                Hidden::make('horometro_previo'),
            ])
            ->fillForm(function (array $arguments): array {
                $agendado = AgendaMaquina::with('maquina:id,horometro_actual,modalidad_trabajo')
                    ->find((int) ($arguments['agenda_id'] ?? 0));

                // El día abre con la última lectura conocida: la de llegada
                // el primer día, la del cierre de ayer los siguientes.
                $previo = $agendado !== null
                    ? (string) ($agendado->maquina->horometro_actual ?? $agendado->horometro_llegada ?? '0.00')
                    : null;

                $dia = is_string($arguments['dia'] ?? null) ? $arguments['dia'] : today()->toDateString();

                return [
                    'agenda_id'       => $arguments['agenda_id'] ?? null,
                    'etiqueta'        => $arguments['etiqueta'] ?? '',
                    'maquina_nombre'  => $arguments['maquina_nombre'] ?? '',
                    'proyecto_nombre' => $arguments['proyecto_nombre'] ?? '',
                    'fecha'           => $arguments['fecha'] ?? today()->toDateString(),
                    'dia'             => $dia,
                    'dias_en_obra'    => $agendado !== null
                        ? $agendado->fecha->startOfDay()->diffInDays(Carbon::parse($dia)->startOfDay()) + 1
                        : 1,
                    'llego'            => $arguments['llego'] ?? '',
                    'usa_horometro'    => $agendado?->maquina->modalidad_trabajo === ModalidadTrabajo::Horas,
                    'horometro_previo' => $previo,
                    'horometro_salida' => $previo,
                    'destino_salida'   => DestinoSalidaMaquina::SigueEnObra->value,
                ];
            })
            ->action(function (array $data): void {
                $this->confirmarSalidaYEncadenar($data, averia: false);
            });
    }

    /**
     * Cierra el día y encadena "Registrar jornada". Con $averia (el botón
     * rojo "No terminó: se averió"), la máquina sale al taller — la
     * estadía se cierra igual — y el modal de jornada abre con "¿Se
     * averió la máquina?" ya encendido.
     *
     * @param array<string, mixed> $datos
     */
    private function confirmarSalidaYEncadenar(array $datos, bool $averia): void
    {
        $agendado = AgendaMaquina::find((int) ($datos['agenda_id'] ?? 0));
        $user = auth()->user();

        if ($agendado === null || ! $user instanceof User) {
            return;
        }

        $destino = $this->destinoDe($datos['destino_salida'] ?? null);

        // La lectura con la que ABRIÓ el día, antes de que el service la
        // reemplace: su diferencia contra la de cierre son las horas de
        // motor de la jornada, la sugerencia más honesta que tenemos.
        $lecturaPrevia = Maquina::query()->whereKey($agendado->maquina_id)->value('horometro_actual');
        $previo = is_numeric($lecturaPrevia) ? number_format((float) $lecturaPrevia, 2, '.', '') : null;
        $dia = is_string($datos['dia'] ?? null) ? $datos['dia'] : today()->toDateString();

        try {
            $cerrado = app(ConfirmarLlegadaService::class)->confirmarSalida(
                $agendado,
                $user,
                isset($datos['horometro_salida']) && $datos['horometro_salida'] !== ''
                    ? (string) $datos['horometro_salida']
                    : null,
                $destino,
            );
        } catch (MaquinariaException $e) {
            Notification::make()
                ->title('No se pudo cerrar el día')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        $sigue = $destino === DestinoSalidaMaquina::SigueEnObra;

        Notification::make()
            ->title(match (true) {
                $averia => 'Se averió — ahora reporta qué pasó',
                $sigue  => 'Día cerrado — la máquina se quedó en la obra',
                default => 'Día cerrado — máquina libre',
            })
            ->body(
                "{$cerrado->maquina->nombre} · {$cerrado->proyecto->nombre}"
                .match (true) {
                    $averia => '. Registra las horas que alcanzó a trabajar, qué se averió y si se repara ahí mismo o tiene que ir al taller.',
                    $sigue  => '. Sigue en la obra: mañana se vuelve a cerrar el día aquí mismo. Ahora registra la jornada.',
                    default => '. '.($destino?->getLabel() ?? 'Quedó libre').'. Maquinaria ya recibió el aviso. Ahora registra la jornada.',
                }
            )
            ->success()
            ->send();

        $this->refreshRecords();

        // PASO 2: el modal de jornada se abre solo — salvo que el día ya
        // tenga su parte (se cerró la estadía después de haber registrado
        // las horas): ahí no hay nada que capturar dos veces.
        if ($this->tieneParteDelDia($cerrado, $dia)) {
            return;
        }

        $this->replaceMountedAction('registrarJornadaSalida', [
            ...$this->argsRegistrarJornada($cerrado, $dia, $this->horasDeHorometro($previo, $cerrado->horometro_salida), $previo),
            'reportar_averia' => $averia,
        ]);
    }

    /**
     * Ficha de cabecera de los modales del ciclo: quién (máquina), dónde
     * (obra) y hasta dos datos que sitúan el momento — la llegada o el
     * cierre del día.
     *
     * Va en HTML propio con su <style>: el contenido del Placeholder no
     * lo escanea Tailwind, así que las clases del panel no llegan hasta
     * acá. Ese mismo bloque lleva el ancho de los botones de destino (es
     * el único CSS de estos modales, no vale la pena un archivo aparte)
     * y el .dark que mantiene todo legible en tema oscuro.
     *
     * @param array<string, string> $meta Etiqueta => valor, en orden.
     */
    private function fichaMaquina(string $maquina, string $proyecto, array $meta, string $tono = 'indigo'): HtmlString
    {
        $maquina = e($maquina);
        $proyecto = e($proyecto);
        $clase = $tono === 'verde' ? 'mayap-ficha mayap-ficha--verde' : 'mayap-ficha';

        $celdas = '';

        foreach ($meta as $etiqueta => $valor) {
            $celdas .= '<div><div class="mayap-ficha__k">'.e($etiqueta).'</div>'
                .'<div class="mayap-ficha__v">'.$valor.'</div></div>';
        }

        return new HtmlString(<<<HTML
            <style>
            .mayap-ficha{border:1px solid #e5e7eb;border-radius:.75rem;background:#fff;overflow:hidden}
            .mayap-ficha__top{display:flex;gap:.75rem;align-items:flex-start;padding:.875rem 1rem}
            .mayap-ficha__ico{flex:none;width:2.25rem;height:2.25rem;border-radius:.5rem;display:flex;align-items:center;justify-content:center;background:#eef2ff;color:#4f46e5}
            .mayap-ficha--verde .mayap-ficha__ico{background:#ecfdf5;color:#059669}
            .mayap-ficha__ico svg{width:1.25rem;height:1.25rem}
            .mayap-ficha__maquina{font-weight:600;font-size:.9375rem;line-height:1.3;color:#111827}
            .mayap-ficha__obra{margin-top:.125rem;font-size:.8125rem;color:#6b7280;line-height:1.35}
            .mayap-ficha__meta{display:grid;grid-template-columns:1fr 1fr;border-top:1px solid #f3f4f6;background:#fafafa}
            .mayap-ficha__meta > div{padding:.625rem 1rem}
            .mayap-ficha__meta > div + div{border-left:1px solid #f3f4f6}
            .mayap-ficha__k{font-size:.6875rem;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#9ca3af}
            .mayap-ficha__v{margin-top:.125rem;font-size:.8125rem;font-weight:500;color:#374151}
            .mayap-ficha__v small{font-weight:400;color:#9ca3af}
            .dark .mayap-ficha{border-color:#374151;background:#111827}
            .dark .mayap-ficha__ico{background:rgba(99,102,241,.15);color:#a5b4fc}
            .dark .mayap-ficha--verde .mayap-ficha__ico,.dark .mayap-ficha.mayap-ficha--verde .mayap-ficha__ico{background:rgba(16,185,129,.15);color:#6ee7b7}
            .dark .mayap-ficha__maquina{color:#f9fafb}
            .dark .mayap-ficha__obra{color:#9ca3af}
            .dark .mayap-ficha__meta{background:rgba(255,255,255,.02);border-top-color:#374151}
            .dark .mayap-ficha__meta > div + div{border-left-color:#374151}
            .dark .mayap-ficha__k{color:#6b7280}
            .dark .mayap-ficha__v{color:#e5e7eb}
            .mayap-aviso{padding:.625rem .875rem;border-radius:.5rem;font-size:.8125rem;line-height:1.45;color:#92400e;background:#fffbeb;border:1px solid #fde68a}
            .dark .mayap-aviso{color:#fcd34d;background:rgba(217,119,6,.12);border-color:rgba(217,119,6,.35)}
            .mayap-destino .fi-fo-toggle-buttons-btn-ctn{display:flex}
            .mayap-destino .fi-fo-toggle-buttons-btn-ctn > *{width:100%;justify-content:flex-start}
            </style>
            <div class="{$clase}">
                <div class="mayap-ficha__top">
                    <div class="mayap-ficha__ico">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-9.026 0A1.115 1.115 0 0 0 3.25 6.615v.958m10.5 0H3.25m10.5 0v3.75m-10.5-3.75v3.75" />
                        </svg>
                    </div>
                    <div>
                        <div class="mayap-ficha__maquina">{$maquina}</div>
                        <div class="mayap-ficha__obra">{$proyecto}</div>
                    </div>
                </div>
                <div class="mayap-ficha__meta">{$celdas}</div>
            </div>
            HTML);
    }

    /**
     * La ficha del CIERRE DEL DÍA: qué día se cierra y desde cuándo está
     * la máquina en esa obra.
     */
    private function fichaCierre(
        string $maquina,
        string $proyecto,
        string $dia,
        string $desde,
        string $llego,
        int $diasEnObra,
    ): HtmlString {
        $estadia = $diasEnObra <= 1 ? 'primer día' : "día {$diasEnObra}";

        return $this->fichaMaquina($maquina, $proyecto, [
            'Cierre del'    => e(ucfirst(Carbon::parse($dia)->translatedFormat('D d/m/Y'))),
            'En obra desde' => e(Carbon::parse($desde)->translatedFormat('d/m/Y'))
                .' <small>· '.e($estadia).' · llegó '.e($llego).'</small>',
        ]);
    }

    /**
     * La ficha de la LLEGADA: para qué día está agendada y a qué hora se
     * la esperaba.
     */
    private function fichaLlegada(string $maquina, string $proyecto, string $fecha, ?string $hora): HtmlString
    {
        return $this->fichaMaquina($maquina, $proyecto, [
            'Agendada para'    => e(ucfirst(Carbon::parse($fecha)->translatedFormat('D d/m/Y'))),
            'Llegada prevista' => $hora !== null && $hora !== ''
                ? e($hora)
                : '<small>sin hora fijada</small>',
        ], tono: 'verde');
    }

    /**
     * ¿Urge la reparación? Mismo cuidado que los otros ToggleButtons.
     */
    private function prioridadDe(mixed $valor): ?PrioridadMantenimiento
    {
        if ($valor instanceof PrioridadMantenimiento) {
            return $valor;
        }

        return is_string($valor) ? PrioridadMantenimiento::tryFrom($valor) : null;
    }

    /**
     * Igual que destinoDe(): ToggleButtons con enum devuelve el enum, no
     * su value.
     */
    private function lugarDe(mixed $valor): ?LugarReparacion
    {
        if ($valor instanceof LugarReparacion) {
            return $valor;
        }

        return is_string($valor) ? LugarReparacion::tryFrom($valor) : null;
    }

    /**
     * Los destinos de la agenda que tienen sentido cuando la máquina SÍ
     * sale de la obra. "Se repara hoy mismo" ya no vive aquí: dejó de ser
     * una opción de agenda para ser la respuesta a "¿dónde se repara?"
     * (2026-09-05).
     *
     * @return array<string, string>
     */
    private function destinosSiSaleDeLaObra(): array
    {
        $opciones = DestinoAgendaFutura::options();

        unset($opciones[DestinoAgendaFutura::ReparacionHoy->value]);

        return $opciones;
    }

    /**
     * ToggleButtons con enum entrega el enum YA CASTEADO — no el string
     * del value (regla de la casa, misma trampa que en solicitudes). Se
     * normaliza acá para que el resto del flujo no tenga que saberlo.
     */
    private function destinoDe(mixed $valor): ?DestinoSalidaMaquina
    {
        if ($valor instanceof DestinoSalidaMaquina) {
            return $valor;
        }

        return is_string($valor) ? DestinoSalidaMaquina::tryFrom($valor) : null;
    }

    /**
     * Horas de MOTOR entre dos lecturas, para el texto de ayuda del
     * horómetro. Null cuando todavía no hay diferencia que mostrar.
     */
    private function horasEntreLecturas(?string $previo, ?string $cierre): ?string
    {
        if ($previo === null || $cierre === null || ! is_numeric($previo) || ! is_numeric($cierre)) {
            return null;
        }

        if (bccomp($cierre, $previo, 2) <= 0) {
            return null;
        }

        return number_format((float) bcsub($cierre, $previo, 2), 1, '.', '');
    }

    /**
     * Horas de MOTOR de la jornada: lo que avanzó el horómetro entre la
     * apertura y el cierre del día. Null si no hay con qué calcularlas.
     */
    private function horasDeHorometro(?string $previo, ?string $cierre): ?string
    {
        return $this->horasEntreLecturas($previo, $cierre);
    }

    /**
     * La reparación EN SITIO abierta de esa máquina, si la hay: mientras
     * exista, la máquina está parada en la obra y el clic del calendario
     * no ofrece cerrar el día sino resolver la reparación.
     */
    private function reparacionEnSitioAbierta(int $maquinaId): ?MantenimientoMaquina
    {
        return MantenimientoMaquina::query()
            ->where('maquina_id', $maquinaId)
            ->where('estado', EstadoMantenimiento::EnProceso->value)
            ->where('en_sitio', true)
            ->orderByDesc('fecha_inicio')
            ->first();
    }

    /**
     * ¿Ese día de esa máquina en esa obra ya tiene parte registrado?
     */
    private function tieneParteDelDia(AgendaMaquina $agendado, string $fecha): bool
    {
        return ParteTrabajo::query()
            ->whereDate('fecha', $fecha)
            ->whereHas('asignacion', fn ($q) => $q
                ->where('maquina_id', $agendado->maquina_id)
                ->where('proyecto_id', $agendado->proyecto_id))
            ->exists();
    }

    /**
     * PASO 2 — Modal "Registrar jornada": horas reales, combustible y
     * operador del día que acaba de cerrar. Mismas reglas que la Captura
     * del día (RegistrarDiaMaquinaService). Sin asignación previa, se
     * crea UNA automática con la tarifa estándar de la máquina y se
     * libera al guardar — todo queda en la bitácora de la máquina y en
     * el historial del proyecto. "Ahora no" deja la salida confirmada;
     * el evento gris del calendario vuelve a ofrecer la jornada.
     *
     * ¿Y si no terminó porque SE AVERIÓ? (decisión Mauricio 2026-07-22):
     * aquí mismo se reporta — con avería, las horas hasta la falla son
     * OBLIGATORIAS (el costo del día cierra en el momento), y al guardar
     * la máquina se va a mantenimiento con sustituta opcional. La
     * asignación automática NO se libera en ese caso: la corta el
     * MantenimientoService, y así la sustituta hereda la obra.
     */
    public function registrarJornadaSalidaAction(): Action
    {
        return Action::make('registrarJornadaSalida')
            ->modalHeading('Registrar jornada')
            ->modalWidth('2xl')
            ->modalSubmitActionLabel('Guardar jornada')
            ->modalCancelActionLabel('Ahora no')
            ->schema([
                Placeholder::make('resumen')
                    ->hiddenLabel()
                    ->content(fn (Get $get): HtmlString => $this->fichaMaquina((string) $get('maquina_nombre'), (string) $get('proyecto_nombre'), [
                        'Jornada del' => e(ucfirst(Carbon::parse((string) $get('fecha'))->translatedFormat('D d/m/Y'))),
                        'Horario'     => $get('llego') !== null
                            ? e((string) $get('llego')).($get('salio') !== null ? ' <small>→ '.e((string) $get('salio')).'</small>' : '')
                            : '<small>sin registrar</small>',
                    ], tono: 'verde'))
                    ->columnSpanFull(),

                // La asignación automática cambia la plata de la obra:
                // se avisa antes de guardar, no después.
                Placeholder::make('aviso_asignacion')
                    ->hiddenLabel()
                    ->visible(fn (Get $get): bool => $get('asignacion_id') === null)
                    ->content(fn (Get $get): HtmlString => new HtmlString(
                        '<div class="mayap-aviso">Esta máquina no tenía asignación en la obra: al guardar se le crea una de UN día con su tarifa estándar'
                        .($get('tarifa_maquina') !== null ? ' (L. '.e((string) $get('tarifa_maquina')).'/h)' : '')
                        .' y queda libre enseguida.</div>'
                    ))
                    ->columnSpanFull(),

                Hidden::make('agenda_id'),
                Hidden::make('maquina_id'),
                Hidden::make('etiqueta'),
                Hidden::make('maquina_nombre'),
                Hidden::make('proyecto_nombre'),
                Hidden::make('fecha'),
                Hidden::make('llego'),
                Hidden::make('salio'),
                Hidden::make('asignacion_id'),
                Hidden::make('jornada_maquina'),
                Hidden::make('litros_hora_maquina'),
                Hidden::make('tarifa_maquina'),
                Hidden::make('modalidad_maquina'),
                Hidden::make('horas_origen'),
                Hidden::make('usa_horometro'),
                Hidden::make('agendados_futuros'),
                Hidden::make('contenedor_id'),
                Hidden::make('contenedor_tipo'),
                Hidden::make('contenedor_carga'),
                Hidden::make('contenedor_nombre'),
                Hidden::make('contenedor_material'),
                Hidden::make('contenedor_unidad'),
                Hidden::make('contenedor_capacidad'),
                Hidden::make('contenedor_salio_con'),

                Fieldset::make('Jornada del día')
                    ->schema([
                        // Cómo se cobra el día (2026-08-16): sin esto el
                        // parte nacía siempre en 'horas' y la renta por
                        // viajes o km cobraba 0 de excedente.
                        $this->campoModalidad(),

                        TextInput::make('horas')
                            ->label('Horas trabajadas')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.5)
                            ->suffix('h')
                            ->prefixIcon('heroicon-o-clock')
                            ->helperText($this->pistaHoras(...))
                            ->live(debounce: 400)
                            // Con avería, el costo del día cierra AHORA. Y
                            // fuera de la modalidad "horas" también son
                            // obligatorias: sin ellas no nace el parte y
                            // los viajes o km se perderían en silencio.
                            // Las horas del día SIEMPRE se piden (decisión
                            // Mauricio 2026-09-05): son el costo interno de
                            // la obra, cobre por hora, por viaje o por km.
                            ->required()
                            ->validationMessages([
                                'required' => 'Registra las horas del día — son el costo de la obra (0 si no arrancó).',
                            ]),

                        TextInput::make('motivo_extra')
                            ->label('Motivo de horas extra')
                            ->placeholder('Solo si excede la jornada')
                            ->required(function (Get $get): bool {
                                $reales = $get('horas');
                                $jornada = $get('jornada_maquina');

                                return is_numeric($reales) && is_numeric($jornada)
                                    && (float) $reales > (float) $jornada;
                            })
                            ->validationMessages([
                                'required' => 'Explica el motivo: las horas superan la jornada de la máquina.',
                            ]),

                        ...$this->camposSegunModalidad(),

                        $this->campoOperador(),
                    ])
                    ->columns(2),

                // Combustible SIN preguntar litros ni precio (decisión
                // Mauricio 2026-09-05): lo que el encargado sabe de verdad
                // es en cuánto quedó el horómetro. El consumo sale de ahí
                // —horas de motor × el rendimiento de la ficha— y el precio
                // del último que se pagó. Pedirle litros cada tarde era
                // pedirle que inventara un número.
                Fieldset::make('Horómetro y combustible')
                    ->schema($this->camposHorometroYCombustible())
                    ->columns(2),

                // EL CONTENEDOR QUE CARGA (Mauricio 2026-09-10): la pipa
                // de agua, la cisterna de diésel. Con cuánto SALIÓ no se
                // pregunta —es la existencia del contenedor, el sistema ya
                // lo sabe—; lo único que nadie más puede saber es con
                // cuánto volvió. De esa resta sale lo que quedó en la obra.
                //
                // Va montado acá y no en pantalla aparte a propósito: el
                // operador ya está obligado a cerrar su día. Una pantalla
                // nueva se olvida, y si nadie marca el regreso el sistema
                // cree que el agua sigue en la pipa.
                Fieldset::make('Lo que cargaba')
                    ->visible(fn (Get $get): bool => $get('contenedor_tipo') === 'granel')
                    ->schema([
                        Placeholder::make('contenedor_resumen')
                            ->hiddenLabel()
                            ->content(fn (Get $get): HtmlString => $this->fichaContenedor(
                                (string) $get('contenedor_nombre'),
                                (string) $get('contenedor_material'),
                                (string) $get('contenedor_salio_con'),
                                (string) $get('contenedor_unidad'),
                            ))
                            ->columnSpanFull(),

                        ToggleButtons::make('contenedor_regreso')
                            ->label('¿Con cuánto regresó?')
                            ->options(fn (Get $get): array => $this->opcionesRegresoContenedor($get))
                            ->colors([
                                'vacio' => 'danger',
                                'mitad' => 'warning',
                                'nada'  => 'gray',
                                'otro'  => 'info',
                            ])
                            ->icons([
                                'vacio' => 'heroicon-o-arrow-down-circle',
                                'mitad' => 'heroicon-o-minus-circle',
                                'nada'  => 'heroicon-o-arrow-uturn-left',
                                'otro'  => 'heroicon-o-pencil',
                            ])
                            ->default('vacio')
                            ->live()
                            ->required(fn (Get $get): bool => filled($get('contenedor_id')))
                            ->columns(2)
                            ->gridDirection(GridDirection::Row)
                            ->extraAttributes(['class' => 'mayap-destino'])
                            ->helperText('Lo que falte contra lo que llevaba se descarga en la obra, con su costo.')
                            ->columnSpanFull(),

                        TextInput::make('contenedor_nivel')
                            ->label('¿Cuánto trae?')
                            ->numeric()
                            ->minValue(0)
                            ->step('any')
                            ->suffix(fn (Get $get): string => (string) $get('contenedor_unidad'))
                            ->visible(fn (Get $get): bool => $get('contenedor_regreso') === 'otro')
                            ->required(fn (Get $get): bool => $get('contenedor_regreso') === 'otro')
                            ->helperText(fn (Get $get): string => 'No puede pasar de '
                                .Cantidad::sinCeros((string) $get('contenedor_salio_con')).': es lo que llevaba.')
                            ->columnSpanFull(),
                    ])
                    ->columns(1),

                // EL CAMIÓN QUE REGRESA CON CARGA (Mauricio 2026-09-10).
                //
                // Si la obra recibió 35 de los 40 sacos que subieron, los
                // 5 restantes son existencia REAL del camión. Sin esta
                // puerta ese material quedaba atrapado ahí para siempre:
                // bien contado, pero inmovilizado, que es peor que no
                // tenerlo registrado. Acá vuelve a bodega.
                Fieldset::make('El camión trae carga')
                    ->visible(fn (Get $get): bool => $get('contenedor_tipo') === 'reparto'
                        && filled($get('contenedor_carga')))
                    ->schema([
                        Placeholder::make('carga_a_bordo')
                            ->hiddenLabel()
                            ->content(fn (Get $get): HtmlString => new HtmlString(
                                '<div class="mayap-aviso"><strong>'.e((string) $get('contenedor_nombre'))
                                .'</strong> todavía trae encima: <strong>'.e((string) $get('contenedor_carga'))
                                .'</strong>. O es material que la obra no recibió, o todavía no ha confirmado.</div>'
                            ))
                            ->columnSpanFull(),

                        ToggleButtons::make('contenedor_devuelve')
                            ->label('¿Qué pasa con esa carga?')
                            ->options([
                                'sigue'  => 'Sigue arriba — no la descargó',
                                'bodega' => 'Regresó a bodega: bajarla',
                            ])
                            ->colors(['sigue' => 'gray', 'bodega' => 'success'])
                            ->icons([
                                'sigue'  => 'heroicon-o-truck',
                                'bodega' => 'heroicon-o-building-storefront',
                            ])
                            ->default('sigue')
                            ->live()
                            ->columns(2)
                            ->gridDirection(GridDirection::Row)
                            ->extraAttributes(['class' => 'mayap-destino'])
                            ->helperText('Si el camión volvió a base con esto, bajarlo devuelve el material a la existencia de la bodega, con su mismo costo.')
                            ->columnSpanFull(),

                        Select::make('contenedor_bodega_destino')
                            ->label('¿A qué bodega la bajan?')
                            ->options(fn (): array => Bodega::query()
                                ->fijas()
                                ->where('activo', true)
                                ->orderBy('nombre')
                                ->pluck('nombre', 'id')
                                ->all())
                            ->searchable()
                            ->native(false)
                            ->visible(fn (Get $get): bool => $get('contenedor_devuelve') === 'bodega')
                            ->required(fn (Get $get): bool => $get('contenedor_devuelve') === 'bodega')
                            ->columnSpanFull(),
                    ])
                    ->columns(1),

                // ¿No terminó porque se averió? El mismo bloque que el
                // modal de asignaciones: motivo, sustituta opcional y
                // aviso de la agenda futura que se resolverá.
                Fieldset::make('Avería')
                    ->schema([
                        Toggle::make('reportar_averia')
                            ->label('¿Se averió la máquina?')
                            ->live()
                            ->inline(false),

                        Textarea::make('averia_motivo')
                            ->label('¿Qué se averió?')
                            ->rows(2)
                            ->visible(fn (Get $get): bool => (bool) $get('reportar_averia'))
                            ->requiredIf('reportar_averia', true)
                            ->helperText('Se avisa a maquinaria y gerencia con lo que escribas aquí.')
                            ->columnSpanFull(),

                        // LA pregunta de una avería (decisión Mauricio
                        // 2026-09-05): ¿la máquina se mueve o no? Antes se
                        // mandaba TODA avería al taller, aunque se
                        // resolviera en el sitio con un repuesto.
                        // ¿Urge o puede esperar? Lo dice quien está viendo
                        // la máquina parada (Mauricio 2026-09-05). Solo dos
                        // niveles: con tres, todo termina siendo "alta".
                        ToggleButtons::make('averia_prioridad')
                            ->label('¿Urge?')
                            ->options([
                                PrioridadMantenimiento::Urgente->value => 'Urgente — la obra está parada',
                                PrioridadMantenimiento::Normal->value  => 'Puede esperar',
                            ])
                            ->colors([
                                PrioridadMantenimiento::Urgente->value => 'danger',
                                PrioridadMantenimiento::Normal->value  => 'gray',
                            ])
                            ->icons([
                                PrioridadMantenimiento::Urgente->value => 'heroicon-o-fire',
                                PrioridadMantenimiento::Normal->value  => 'heroicon-o-clock',
                            ])
                            ->default(PrioridadMantenimiento::Normal->value)
                            ->required(fn (Get $get): bool => (bool) $get('reportar_averia'))
                            ->visible(fn (Get $get): bool => (bool) $get('reportar_averia'))
                            ->columns(2)
                            ->gridDirection(GridDirection::Row)
                            ->extraAttributes(['class' => 'mayap-destino'])
                            ->helperText('Marca URGENTE solo si de verdad para la obra: es lo que decide a qué máquina le entra primero el taller.')
                            ->columnSpanFull(),

                        ToggleButtons::make('lugar_reparacion')
                            ->label('¿Dónde se repara?')
                            ->options(LugarReparacion::class)
                            ->default(LugarReparacion::EnObra->value)
                            ->live()
                            ->required(fn (Get $get): bool => (bool) $get('reportar_averia'))
                            ->visible(fn (Get $get): bool => (bool) $get('reportar_averia'))
                            ->columns(2)
                            ->gridDirection(GridDirection::Row)
                            ->extraAttributes(['class' => 'mayap-destino'])
                            ->helperText(fn (Get $get): string => $this->lugarDe($get('lugar_reparacion'))?->getDescription()
                                ?? 'De esto depende si la obra conserva la máquina o hay que cubrirla.')
                            ->columnSpanFull(),

                        // Se repara ahí: lo único que falta es que le
                        // lleven algo. Ese pedido es el dato.
                        Textarea::make('averia_necesita')
                            ->label('¿Qué se necesita para repararla ahí?')
                            ->rows(2)
                            ->placeholder('Repuesto, herramienta, un mecánico…')
                            ->visible(fn (Get $get): bool => (bool) $get('reportar_averia')
                                && $this->lugarDe($get('lugar_reparacion')) === LugarReparacion::EnObra)
                            ->helperText('Maquinaria y recepción reciben el pedido con el nombre de la obra para despacharlo. La máquina se queda ahí y sus días agendados no se tocan.')
                            ->columnSpanFull(),

                        // Solo si SALE de la obra tiene sentido preguntar
                        // con qué se cubren los días comprometidos.
                        Radio::make('destino_agenda')
                            ->label('¿Con qué se cubren los días ya agendados?')
                            ->options($this->destinosSiSaleDeLaObra())
                            ->descriptions(DestinoAgendaFutura::descripciones())
                            ->default(DestinoAgendaFutura::Cancelar->value)
                            ->live()
                            ->visible(fn (Get $get): bool => (bool) $get('reportar_averia')
                                && $this->lugarDe($get('lugar_reparacion')) === LugarReparacion::Taller
                                && (int) $get('agendados_futuros') > 0)
                            ->columnSpanFull(),

                        Select::make('sustituta_id')
                            ->label('¿Qué máquina mandan en su lugar?')
                            ->options(fn (Get $get) => Maquina::query()
                                ->activas()
                                ->where('estado', EstadoMaquina::Disponible->value)
                                ->whereKeyNot((int) $get('maquina_id'))
                                ->orderBy('nombre')
                                ->pluck('nombre', 'id'))
                            ->searchable()
                            ->visible(fn (Get $get): bool => (bool) $get('reportar_averia')
                                && $this->lugarDe($get('lugar_reparacion')) === LugarReparacion::Taller
                                && ((int) $get('agendados_futuros') === 0
                                    || $get('destino_agenda') === DestinoAgendaFutura::Sustituta->value))
                            ->required(fn (Get $get): bool => (bool) $get('reportar_averia')
                                && $this->lugarDe($get('lugar_reparacion')) === LugarReparacion::Taller
                                && (int) $get('agendados_futuros') > 0
                                && $get('destino_agenda') === DestinoAgendaFutura::Sustituta->value)
                            ->helperText('Toma su lugar en la obra y hereda los días agendados.')
                            ->columnSpanFull(),
                    ])
                    ->columns(1),
            ])
            ->fillForm(fn (array $arguments): array => [
                'agenda_id'            => $arguments['agenda_id'] ?? null,
                'maquina_id'           => $arguments['maquina_id'] ?? null,
                'etiqueta'             => $arguments['etiqueta'] ?? '',
                'maquina_nombre'       => $arguments['maquina_nombre'] ?? '',
                'proyecto_nombre'      => $arguments['proyecto_nombre'] ?? '',
                'fecha'                => $arguments['fecha'] ?? today()->toDateString(),
                'llego'                => $arguments['llego'] ?? null,
                'salio'                => $arguments['salio'] ?? null,
                'usa_horometro'        => (bool) ($arguments['usa_horometro'] ?? false),
                'horometro_apertura'   => $arguments['horometro_apertura'] ?? null,
                'horometro_cierre'     => $arguments['horometro_cierre'] ?? null,
                'asignacion_id'        => $arguments['asignacion_id'] ?? null,
                'jornada_maquina'      => $arguments['jornada_maquina'] ?? null,
                'litros_hora_maquina'  => $arguments['litros_hora_maquina'] ?? null,
                'tarifa_maquina'       => $arguments['tarifa_maquina'] ?? null,
                'horas'                => $arguments['horas_sugeridas'] ?? null,
                'modalidad'            => $arguments['modalidad_maquina'] ?? ModalidadTrabajo::Horas->value,
                'modalidad_maquina'    => $arguments['modalidad_maquina'] ?? null,
                'horas_origen'         => $arguments['horas_origen'] ?? null,
                'km_recorridos'        => null,
                'viajes'               => null,
                'actividad'            => null,
                'motivo_extra'         => null,
                'litros'               => null,
                'precio_litro'         => app(RegistrarDiaMaquinaService::class)->ultimoPrecioLitro(),
                'operador_id'          => $arguments['operador_habitual_id'] ?? null,
                'reportar_averia'      => (bool) ($arguments['reportar_averia'] ?? false),
                'averia_motivo'        => null,
                'lugar_reparacion'     => LugarReparacion::EnObra->value,
                'averia_prioridad'     => PrioridadMantenimiento::Normal->value,
                'averia_necesita'      => null,
                'sustituta_id'         => null,
                'destino_agenda'       => DestinoAgendaFutura::Cancelar->value,
                'agendados_futuros'    => $arguments['agendados_futuros'] ?? 0,
                'contenedor_id'        => $arguments['contenedor_id'] ?? null,
                'contenedor_nombre'    => $arguments['contenedor_nombre'] ?? null,
                'contenedor_material'  => $arguments['contenedor_material'] ?? null,
                'contenedor_unidad'    => $arguments['contenedor_unidad'] ?? null,
                'contenedor_capacidad' => $arguments['contenedor_capacidad'] ?? null,
                'contenedor_salio_con' => $arguments['contenedor_salio_con'] ?? null,
                'contenedor_regreso'   => 'vacio',
                'contenedor_nivel'     => null,
                'contenedor_tipo'      => $arguments['contenedor_tipo'] ?? null,
                'contenedor_carga'     => $arguments['contenedor_carga'] ?? null,
                'contenedor_devuelve'  => 'sigue',

                'contenedor_bodega_destino' => null,
            ])
            ->action(function (array $data): void {
                $agendado = AgendaMaquina::with(['maquina:id,nombre', 'proyecto:id,nombre'])
                    ->find((int) ($data['agenda_id'] ?? 0));
                $user = auth()->user();

                if ($agendado === null || ! $user instanceof User) {
                    return;
                }

                $quiereAveria = ($data['reportar_averia'] ?? false) && filled($data['averia_motivo'] ?? null);

                if (! $quiereAveria && ! filled($data['horas'] ?? null) && ! filled($data['litros'] ?? null)) {
                    Notification::make()
                        ->title('Nada registrado')
                        ->body('Registra las horas trabajadas del día — o pulsa "Ahora no".')
                        ->warning()
                        ->send();

                    return;
                }

                // El combustible NO se pregunta: sale del horómetro. Horas
                // de motor × el rendimiento de la ficha (decisión Mauricio
                // 2026-09-05). Solo si la máquina no tiene consumo cargado
                // se usa lo que se haya escrito a mano.
                $litros = $this->litrosDelDia($data);

                // Sin asignación previa, se crea UNA automática con la
                // tarifa estándar de la máquina y se libera al final — un
                // solo guardado y todo queda en la bitácora (decisión
                // Mauricio 2026-07-16). Si maquinaria pactó otra tarifa,
                // esa asignación manual ya existía y se usa.
                $partes = 0;
                $consumos = 0;
                $saltados = [];

                $asignacionId = filled($data['asignacion_id'] ?? null) ? (int) $data['asignacion_id'] : null;
                $asignacionAutomatica = null;

                if ($asignacionId === null) {
                    try {
                        $asignacionAutomatica = app(AsignarMaquinaService::class)->asignar(
                            maquina: $agendado->maquina,
                            proyectoId: $agendado->proyecto_id,
                            fechaInicio: $agendado->fecha->toDateString(),
                            notas: 'ASIGNACIÓN AUTOMÁTICA DE UN DÍA: JORNADA REGISTRADA AL CERRAR EL CICLO DESDE EL CALENDARIO.',
                        );
                        $asignacionId = $asignacionAutomatica->id;
                    } catch (MaquinariaException $e) {
                        // La máquina no estaba libre para asignarse (p. ej.
                        // asignada a OTRA obra): la jornada queda pendiente
                        // con el porqué.
                        $saltados[] = "Horas/combustible no registrados: {$e->getMessage()}";
                    }
                }

                if ($asignacionId !== null) {
                    $resultado = app(RegistrarDiaMaquinaService::class)->capturar(
                        fecha: $agendado->fecha->toDateString(),
                        filas: [[
                            'asignacion_id' => $asignacionId,
                            'horas'         => $data['horas'] ?? null,
                            'motivo_extra'  => $data['motivo_extra'] ?? null,
                            'modalidad'     => $data['modalidad'] ?? null,
                            'km_recorridos' => $data['km_recorridos'] ?? null,
                            'viajes'        => $data['viajes'] ?? null,
                            'actividad'     => $data['actividad'] ?? null,
                            'litros'        => $litros,
                            'precio_litro'  => $data['precio_litro'] ?? null,
                            'operador_id'   => $data['operador_id'] ?? null,
                        ]],
                        userId: $user->id,
                    );

                    $partes = $resultado['partes'];
                    $consumos = $resultado['consumos'];
                    $saltados = [...$saltados, ...$resultado['saltados']];
                }

                // EL CONTENEDOR (Mauricio 2026-09-10): lo que la pipa no
                // trajo de vuelta se quedó en esta obra. Va acá, con la
                // jornada, y no en una pantalla aparte: si nadie marcara el
                // regreso, el sistema creería que el agua sigue cargada.
                $descargado = null;

                if (filled($data['contenedor_id'] ?? null)) {
                    $contenedor = Bodega::find((int) $data['contenedor_id']);

                    // El camión de reparto que vuelve a base con carga: se
                    // baja a bodega. Sin esta puerta ese material quedaba
                    // atrapado arriba del camión para siempre.
                    if ($contenedor instanceof Bodega
                        && ($data['contenedor_devuelve'] ?? null) === 'bodega'
                        && filled($data['contenedor_bodega_destino'] ?? null)
                    ) {
                        $bodegaDestino = Bodega::find((int) $data['contenedor_bodega_destino']);

                        if ($bodegaDestino instanceof Bodega) {
                            try {
                                $devuelto = app(RegistrarEntregaContenedorService::class)->devolverABodega(
                                    contenedor: $contenedor,
                                    bodega: $bodegaDestino,
                                    userId: $user->id,
                                    fecha: $agendado->fecha->toDateString(),
                                );

                                if ($devuelto !== []) {
                                    $descargado = count($devuelto).' material(es) devueltos a '.$bodegaDestino->nombre;
                                }
                            } catch (InventarioException $e) {
                                $saltados[] = "Devolución a bodega: {$e->getMessage()}";
                            }
                        }
                    }

                    $nivel = $this->nivelRegresoContenedor($data);

                    if ($contenedor instanceof Bodega && $nivel !== null) {
                        try {
                            $entregado = app(RegistrarEntregaContenedorService::class)->registrarRegreso(
                                contenedor: $contenedor,
                                obra: $agendado->proyecto,
                                nivelRegreso: $nivel,
                                userId: $user->id,
                                fecha: $agendado->fecha->toDateString(),
                            );

                            if (bccomp($entregado, '0', 4) > 0) {
                                $descargado = Cantidad::sinCeros($entregado)
                                    .' '.(string) ($data['contenedor_unidad'] ?? '')
                                    .' de '.(string) ($data['contenedor_material'] ?? 'material')
                                    .' descargados';
                            }
                        } catch (InventarioException $e) {
                            $saltados[] = "Contenedor: {$e->getMessage()}";
                        }
                    }
                }

                // Avería DESPUÉS de capturar la jornada (el parte entra
                // antes de cortar la asignación) y ANTES de liberar la
                // asignación automática: MantenimientoService la corta y
                // así la sustituta hereda la obra y la agenda futura.
                $averiaReportada = false;

                if ($quiereAveria) {
                    try {
                        $lugar = $this->lugarDe($data['lugar_reparacion'] ?? null) ?? LugarReparacion::EnObra;

                        $destino = $lugar === LugarReparacion::Taller
                            && (int) ($data['agendados_futuros'] ?? 0) > 0
                            && filled($data['destino_agenda'] ?? null)
                                ? DestinoAgendaFutura::from((string) $data['destino_agenda'])
                                : null;

                        $sustituta = $lugar === LugarReparacion::Taller
                            && ($destino === null || $destino === DestinoAgendaFutura::Sustituta)
                            && filled($data['sustituta_id'] ?? null)
                                ? Maquina::find((int) $data['sustituta_id'])
                                : null;

                        app(MantenimientoService::class)->enviarAMantenimiento(
                            maquina: $agendado->maquina,
                            motivo: (string) $data['averia_motivo'],
                            sustituta: $sustituta,
                            fecha: $agendado->fecha->toDateString(),
                            destinoAgenda: $destino,
                            lugar: $lugar,
                            necesita: filled($data['averia_necesita'] ?? null) ? (string) $data['averia_necesita'] : null,
                            proyectoId: $agendado->proyecto_id,
                            prioridad: $this->prioridadDe($data['averia_prioridad'] ?? null),
                        );
                        $averiaReportada = true;

                        // Si SÍ se va al taller, recién ahora deja la obra:
                        // la estadía se cierra con ese destino. Si se
                        // repara ahí, no se toca — la máquina sigue puesta.
                        if ($lugar === LugarReparacion::Taller && $agendado->salida_confirmada_at === null) {
                            app(ConfirmarLlegadaService::class)->confirmarSalida(
                                $agendado->refresh(),
                                $user,
                                null,
                                DestinoSalidaMaquina::Taller,
                            );
                        }
                    } catch (MaquinariaException $e) {
                        $saltados[] = "Avería: {$e->getMessage()}";
                    }
                }

                // La asignación automática fue solo para ESTA jornada:
                // se finaliza de inmediato y la máquina vuelve a Disponible.
                // Con avería reportada ya la finalizó el mantenimiento.
                if ($asignacionAutomatica !== null && ! $averiaReportada) {
                    app(AsignarMaquinaService::class)->finalizar(
                        $asignacionAutomatica,
                        $agendado->fecha->toDateString(),
                    );
                }

                $jornada = array_filter([
                    $partes > 0 ? "{$partes} parte(s) de horas" : null,
                    $consumos > 0 ? "{$consumos} consumo(s) de combustible" : null,
                    $descargado,
                    $averiaReportada ? 'avería reportada (en mantenimiento)' : null,
                ]);

                if ($jornada === []) {
                    Notification::make()
                        ->title('Jornada no registrada')
                        ->body($saltados !== [] ? implode(' · ', $saltados) : 'Revisa los datos e intenta de nuevo.')
                        ->warning()
                        ->persistent()
                        ->send();

                    return;
                }

                $notificacion = Notification::make()
                    ->title('Jornada registrada')
                    ->body(
                        "{$agendado->maquina->nombre} en {$agendado->proyecto->nombre} — "
                        .implode(' + ', $jornada)
                        .'. Quedó en la bitácora de la máquina y en el historial del proyecto.'
                        .($saltados !== [] ? ' Pendiente: '.implode(' · ', $saltados) : '')
                    )
                    ->success();

                if ($saltados !== []) {
                    $notificacion->warning()->persistent();
                }

                $notificacion->send();
                $this->refreshRecords();
            });
    }

    /**
     * Modal "Confirmar llegada" — un solo botón: la máquina YA está en
     * la obra. Queda quién y a qué hora, y maquinaria recibe el aviso.
     */
    public function confirmarLlegadaAction(): Action
    {
        return Action::make('confirmarLlegada')
            ->modalHeading('¿Ya llegó la máquina?')
            ->modalDescription('Queda registrada la hora de entrada y maquinaria recibe el aviso. Desde este momento la máquina cuenta como que está en esta obra.')
            ->modalWidth('lg')
            ->modalSubmitActionLabel('Sí, ya llegó')
            ->schema([
                Placeholder::make('resumen')
                    ->hiddenLabel()
                    ->content(fn (Get $get): HtmlString => $this->fichaLlegada((string) $get('maquina_nombre'), (string) $get('proyecto_nombre'), (string) $get('fecha'), is_string($get('hora')) ? $get('hora') : null))
                    ->columnSpanFull(),

                // El horómetro con el que LLEGA es el punto de partida de la
                // estadía. Como la permanencia no tiene fecha de fin, esta
                // lectura contra la del cierre es la única forma de saber
                // cuánto trabajó la máquina en ESTA obra.
                TextInput::make('horometro_llegada')
                    ->label('Horómetro al llegar')
                    ->numeric()
                    ->minValue(0)
                    ->step('any')
                    ->prefixIcon('heroicon-o-clock')
                    ->suffix('h')
                    ->required(fn (Get $get): bool => (bool) $get('usa_horometro'))
                    ->visible(fn (Get $get): bool => (bool) $get('usa_horometro'))
                    ->helperText(fn (Get $get): HtmlString => new HtmlString(
                        is_numeric($get('horometro_ultimo'))
                            ? 'La última lectura registrada fue <strong>'
                                .e(number_format((float) $get('horometro_ultimo'), 2, '.', '')).' h</strong>. Corrígela si el reloj marca otra cosa.'
                            : 'Lo que marca el reloj de horas en este momento.'
                    ))
                    ->columnSpanFull(),

                Hidden::make('agenda_id'),
                Hidden::make('maquina_nombre'),
                Hidden::make('proyecto_nombre'),
                Hidden::make('fecha'),
                Hidden::make('hora'),
                Hidden::make('usa_horometro'),
                Hidden::make('horometro_ultimo'),
            ])
            ->fillForm(function (array $arguments): array {
                $agendado = AgendaMaquina::with('maquina:id,horometro_actual,modalidad_trabajo')
                    ->find((int) ($arguments['agenda_id'] ?? 0));

                $ultimo = $agendado !== null ? (string) $agendado->maquina->horometro_actual : null;

                return [
                    'agenda_id'       => $arguments['agenda_id'] ?? null,
                    'maquina_nombre'  => $arguments['maquina_nombre'] ?? '',
                    'proyecto_nombre' => $arguments['proyecto_nombre'] ?? '',
                    'fecha'           => $arguments['fecha'] ?? today()->toDateString(),
                    'hora'            => $arguments['hora'] ?? null,
                    // Remolques, contenedores y unidades por km no tienen
                    // reloj de horas: ahí el campo ni aparece.
                    'usa_horometro'     => $agendado?->maquina->modalidad_trabajo === ModalidadTrabajo::Horas,
                    'horometro_ultimo'  => $ultimo,
                    'horometro_llegada' => $ultimo,
                ];
            })
            ->action(function (array $data): void {
                $agendado = AgendaMaquina::find((int) ($data['agenda_id'] ?? 0));
                $user = auth()->user();

                if ($agendado === null || ! $user instanceof User) {
                    return;
                }

                try {
                    $confirmado = app(ConfirmarLlegadaService::class)->confirmar(
                        $agendado,
                        $user,
                        isset($data['horometro_llegada']) && $data['horometro_llegada'] !== ''
                            ? (string) $data['horometro_llegada']
                            : null,
                    );

                    Notification::make()
                        ->title('Llegada confirmada')
                        ->body(
                            "{$confirmado->maquina->nombre} en {$confirmado->proyecto->nombre} — "
                            .'confirmada a las '.$confirmado->llegada_confirmada_at?->format('g:i A')
                            .'. Maquinaria ya recibió el aviso.'
                        )
                        ->success()
                        ->send();

                    $this->refreshRecords();
                } catch (MaquinariaException $e) {
                    Notification::make()
                        ->title('No se pudo confirmar')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Modal de CONTINGENCIA (decisión Mauricio 2026-07-20): la fecha del
     * agendado pasó y nadie confirmó la llegada — el evento quedó ROJO.
     * Dos salidas: SÍ llegó (confirmación tardía; el ciclo sigue igual:
     * salida y jornada con los mismos clicks) o NO llegó (constancia con
     * motivo — el evento se retira, la bitácora de la obra lo guarda y
     * maquinaria recibe la campanita).
     */
    public function resolverAgendaVencidaAction(): Action
    {
        return Action::make('resolverAgendaVencida')
            ->modalHeading('¿Qué pasó con esta máquina?')
            ->modalWidth('lg')
            ->modalSubmitActionLabel('Guardar')
            ->schema([
                Placeholder::make('resumen')
                    ->hiddenLabel()
                    ->content(fn (Get $get): HtmlString => new HtmlString(
                        '<div style="padding:.75rem 1rem;border-radius:.5rem;background:rgba(220,38,38,.08);border:1px solid rgba(220,38,38,.25)">'
                        .'<span style="font-weight:700;font-size:1.05rem">'.e((string) $get('etiqueta')).'</span>'
                        .'<br><span style="color:#6b7280;font-size:.85rem">'.e(Carbon::parse((string) $get('fecha'))->format('d/m/Y'))
                        .($get('hora') !== null ? ' · llegada prevista '.e((string) $get('hora')) : '')
                        .' — la fecha pasó y nadie confirmó la llegada.</span>'
                        .'</div>'
                    )),

                Radio::make('resolucion')
                    ->label('¿Qué pasó ese día?')
                    ->options([
                        'llego'    => 'SÍ llegó — solo faltó confirmarla (queda confirmada ahora y el ciclo sigue: salida y jornada)',
                        'no_llego' => 'NO llegó — dejar constancia con motivo (el evento se retira del calendario)',
                    ])
                    ->required()
                    ->live(),

                Textarea::make('motivo')
                    ->label('Motivo (queda en la bitácora de la obra)')
                    ->rows(2)
                    ->mayusculas()
                    ->placeholder('SE DAÑÓ EN RUTA / EL CLIENTE MOVIÓ LA FECHA / SIN OPERADOR')
                    ->visible(fn (Get $get): bool => $get('resolucion') === 'no_llego')
                    ->required(fn (Get $get): bool => $get('resolucion') === 'no_llego'),

                Hidden::make('agenda_id'),
                Hidden::make('etiqueta'),
                Hidden::make('fecha'),
                Hidden::make('hora'),
            ])
            ->fillForm(fn (array $arguments): array => [
                'agenda_id' => $arguments['agenda_id'] ?? null,
                'etiqueta'  => $arguments['etiqueta'] ?? '',
                'fecha'     => $arguments['fecha'] ?? today()->toDateString(),
                'hora'      => $arguments['hora'] ?? null,
            ])
            ->action(function (array $data): void {
                $agendado = AgendaMaquina::find((int) ($data['agenda_id'] ?? 0));
                $user = auth()->user();

                if ($agendado === null || ! $user instanceof User) {
                    return;
                }

                try {
                    if (($data['resolucion'] ?? '') === 'no_llego') {
                        $marcado = app(MarcarNoLlegoAgendaService::class)
                            ->marcar($agendado, (string) ($data['motivo'] ?? ''), $user);

                        Notification::make()
                            ->title('Constancia guardada')
                            ->body(
                                "{$marcado->maquina->nombre} quedó marcada como NO llegada a {$marcado->proyecto->nombre} "
                                .'el '.$marcado->fecha->format('d/m/Y').'. El motivo quedó en la bitácora de la obra.'
                            )
                            ->success()
                            ->send();
                    } else {
                        $confirmado = app(ConfirmarLlegadaService::class)->confirmar(
                            $agendado,
                            $user,
                            isset($data['horometro_llegada']) && $data['horometro_llegada'] !== ''
                            ? (string) $data['horometro_llegada']
                            : null,
                        );

                        Notification::make()
                            ->title('Llegada confirmada (tarde)')
                            ->body(
                                "{$confirmado->maquina->nombre} en {$confirmado->proyecto->nombre} — "
                                .'el siguiente click sobre el evento ofrece la salida y la jornada.'
                            )
                            ->success()
                            ->send();
                    }

                    $this->refreshRecords();
                } catch (MaquinariaException $e) {
                    Notification::make()
                        ->title('No se pudo resolver')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Con qué unidad se cobra el día. Se sugiere la del catálogo de la
     * máquina y se puede cambiar: una volqueta a veces va por horas.
     * ÚNICA fuente para los dos modales del calendario.
     */
    /**
     * La modalidad NO es una pregunta: ya viene de la máquina (decisión
     * Mauricio 2026-09-05 — "eso ya debería traerlo de maquinaria"). Se
     * deja editable porque una volqueta se puede cobrar por hora un día
     * suelto, pero se presenta como lo que es: un dato heredado que
     * casi nunca se toca.
     */
    private function campoModalidad(): Select
    {
        return Select::make('modalidad')
            ->label('Se cobra por')
            ->options(ModalidadTrabajo::options())
            ->default(ModalidadTrabajo::Horas->value)
            ->required()
            ->live()
            ->native(false)
            ->helperText(function (Get $get): string {
                $deLaMaquina = ModalidadTrabajo::tryFrom((string) $get('modalidad_maquina'));

                return $deLaMaquina === null
                    ? 'Decide qué más se registra y con qué unidad se cobra la renta.'
                    : 'Viene de la ficha de la máquina ('.$deLaMaquina->getLabel().'). Cámbialo solo si ESTE día se cobró distinto.';
            })
            ->columnSpanFull();
    }

    /**
     * El dato que EXIGE cada modalidad — mismas reglas que el parte de
     * Asignaciones (RegistrarParteService las valida igual).
     *
     * @return list<TextInput>
     */
    private function camposSegunModalidad(): array
    {
        return [
            TextInput::make('km_recorridos')
                ->label('Kilómetros recorridos')
                ->numeric()
                ->step('any')
                ->minValue(0.01)
                ->suffix('km')
                ->required(fn (Get $get): bool => $get('modalidad') === ModalidadTrabajo::Kilometraje->value)
                ->visible(fn (Get $get): bool => $get('modalidad') === ModalidadTrabajo::Kilometraje->value)
                ->helperText('Suman al kilometraje de la máquina y a su mantenimiento por km.'),

            TextInput::make('viajes')
                ->label('Viajes del día')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->required(fn (Get $get): bool => $get('modalidad') === ModalidadTrabajo::Viajes->value)
                ->visible(fn (Get $get): bool => $get('modalidad') === ModalidadTrabajo::Viajes->value),

            TextInput::make('actividad')
                ->label('Actividad / flete')
                ->maxLength(255)
                ->mayusculas()
                ->placeholder('FLETE DE CEMENTO A LA OBRA X')
                ->required(fn (Get $get): bool => $get('modalidad') === ModalidadTrabajo::Flete->value)
                ->visible(fn (Get $get): bool => $get('modalidad') === ModalidadTrabajo::Flete->value)
                ->columnSpanFull(),
        ];
    }

    private function montarRegistrarDia(
        int $maquinaId,
        int $proyectoId,
        string $etiqueta,
        string $fecha,
    ): void {
        // La jornada se registra contra la asignación ACTIVA de esa
        // máquina en esa obra (trae la tarifa pactada).
        $asignacionId = AsignacionMaquina::query()
            ->where('maquina_id', $maquinaId)
            ->where('proyecto_id', $proyectoId)
            ->where('estado', EstadoAsignacion::Activa->value)
            ->value('id');

        // Para el aviso de avería: cuántos agendados PLAN (sin llegada
        // confirmada) resolvería un mantenimiento — transferidos o
        // cancelados. Los confirmados son historia y no se tocan.
        $agendadosFuturos = AgendaMaquina::query()
            ->where('maquina_id', $maquinaId)
            ->whereDate('fecha', '>=', $fecha)
            ->whereNull('llegada_confirmada_at')
            ->count();

        // Jornada estándar (umbral del motivo de horas extra, misma
        // regla que RegistrarParteService) y modalidad con que se cobra
        // el día — ambas del catálogo de la máquina.
        $maquinaDatos = Maquina::query()
            ->whereKey($maquinaId)
            ->first(['horas_dia_renta', 'modalidad_trabajo', 'litros_por_hora', 'operador_habitual_id']);

        $this->mountAction('registrarDia', [
            'maquina_id'           => $maquinaId,
            'proyecto_id'          => $proyectoId,
            'asignacion_id'        => $asignacionId,
            'etiqueta'             => $etiqueta,
            'fecha'                => $fecha,
            'jornada_maquina'      => $maquinaDatos?->horas_dia_renta !== null ? (string) $maquinaDatos->horas_dia_renta : null,
            'litros_hora_maquina'  => $maquinaDatos?->litros_por_hora !== null ? (string) $maquinaDatos->litros_por_hora : null,
            'modalidad_maquina'    => $maquinaDatos?->modalidad_trabajo->value,
            'operador_habitual_id' => $maquinaDatos?->operador_habitual_id,
            'agendados_futuros'    => $agendadosFuturos,
        ]);
    }

    /**
     * Modal "Registrar jornada" — el día completo de UNA máquina: horas,
     * combustible (litros y lempiras) y avería si la hubo. Reusa
     * RegistrarDiaMaquinaService (mismas reglas que la Captura del día)
     * y MantenimientoService para la avería.
     */
    public function registrarDiaAction(): Action
    {
        return Action::make('registrarDia')
            ->modalHeading('Registrar jornada')
            ->modalWidth('2xl')
            ->modalSubmitActionLabel('Guardar jornada')
            ->visible(fn (): bool => auth()->user()?->can(Permisos::REGISTRAR_JORNADA_MAQUINA) ?? false)
            ->schema([
                Placeholder::make('resumen')
                    ->hiddenLabel()
                    ->content(fn (Get $get): HtmlString => new HtmlString(
                        '<div style="padding:.75rem 1rem;border-radius:.5rem;background:rgba(37,99,235,.08);border:1px solid rgba(37,99,235,.25)">'
                        .'<span style="font-weight:700;font-size:1.05rem">'.e((string) $get('etiqueta')).'</span>'
                        .'<br><span style="color:#6b7280;font-size:.85rem">'.e(Carbon::parse((string) $get('fecha'))->format('d/m/Y')).'</span>'
                        .($get('asignacion_id') === null
                            ? '<br><span style="color:#dc2626;font-weight:600;font-size:.85rem">⚠ Sin asignación activa a esta obra — solo podrás reportar avería. Para horas/combustible asígnala primero.</span>'
                            : '')
                        .'</div>'
                    )),

                Hidden::make('maquina_id'),
                Hidden::make('asignacion_id'),
                Hidden::make('etiqueta'),
                Hidden::make('fecha'),
                Hidden::make('jornada_maquina'),
                Hidden::make('litros_hora_maquina'),

                Fieldset::make('Trabajo del día')
                    ->schema([
                        // Cómo se cobra el día (2026-08-16): sin esto el
                        // parte nacía siempre en 'horas' y la renta por
                        // viajes o km cobraba 0 de excedente.
                        $this->campoModalidad(),

                        TextInput::make('horas')
                            ->label('Horas reales')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.5)
                            ->suffix('h')
                            ->prefixIcon('heroicon-o-clock')
                            // Sin horas no nace el parte: los viajes o los
                            // km se perderían en silencio.
                            ->required(fn (Get $get): bool => $get('modalidad') !== ModalidadTrabajo::Horas->value)
                            ->helperText(fn (Get $get): ?string => $get('modalidad') !== ModalidadTrabajo::Horas->value
                                ? 'Las horas del día SIEMPRE se anotan: son el costo interno de la obra.'
                                : null)
                            ->live(debounce: 400)
                            ->afterStateUpdated($this->estimarLitros(...)),

                        // Espejo de la regla del service: motivo obligatorio
                        // cuando las horas pasan de la jornada estándar.
                        TextInput::make('motivo_extra')
                            ->label('Motivo de horas extra')
                            ->placeholder('Solo si excede la jornada')
                            ->required(function (Get $get): bool {
                                $reales = $get('horas');
                                $jornada = $get('jornada_maquina');

                                return is_numeric($reales) && is_numeric($jornada)
                                    && (float) $reales > (float) $jornada;
                            })
                            ->validationMessages([
                                'required' => 'Explica el motivo: las horas reales superan la jornada de la máquina.',
                            ]),

                        ...$this->camposSegunModalidad(),
                    ])
                    ->columns(2),

                Fieldset::make('Combustible')
                    ->schema([
                        TextInput::make('litros')
                            ->label('Litros')
                            ->numeric()
                            ->minValue(0)
                            ->suffix('L')
                            ->prefixIcon('heroicon-o-fire')
                            ->helperText($this->pistaConsumoEstimado(...)),

                        TextInput::make('precio_litro')
                            ->label('Precio por litro')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('L.')
                            ->helperText('Litros × precio = costo en L. (referencia).'),
                    ])
                    ->columns(2),

                Fieldset::make('Jornada')
                    ->schema([
                        $this->campoOperador(),

                        Toggle::make('reportar_averia')
                            ->label('¿Se averió la máquina?')
                            ->live()
                            ->inline(false),

                        Textarea::make('averia_motivo')
                            ->label('¿Qué se averió?')
                            ->rows(2)
                            ->visible(fn (Get $get): bool => (bool) $get('reportar_averia'))
                            ->requiredIf('reportar_averia', true)
                            ->helperText('Se avisa a maquinaria y gerencia con lo que escribas aquí.')
                            ->columnSpanFull(),

                        // Misma pregunta que en el cierre del día: primero
                        // si la máquina se mueve o no (2026-09-05).
                        // ¿Urge o puede esperar? Lo dice quien está viendo
                        // la máquina parada (Mauricio 2026-09-05). Solo dos
                        // niveles: con tres, todo termina siendo "alta".
                        ToggleButtons::make('averia_prioridad')
                            ->label('¿Urge?')
                            ->options([
                                PrioridadMantenimiento::Urgente->value => 'Urgente — la obra está parada',
                                PrioridadMantenimiento::Normal->value  => 'Puede esperar',
                            ])
                            ->colors([
                                PrioridadMantenimiento::Urgente->value => 'danger',
                                PrioridadMantenimiento::Normal->value  => 'gray',
                            ])
                            ->icons([
                                PrioridadMantenimiento::Urgente->value => 'heroicon-o-fire',
                                PrioridadMantenimiento::Normal->value  => 'heroicon-o-clock',
                            ])
                            ->default(PrioridadMantenimiento::Normal->value)
                            ->required(fn (Get $get): bool => (bool) $get('reportar_averia'))
                            ->visible(fn (Get $get): bool => (bool) $get('reportar_averia'))
                            ->columns(2)
                            ->gridDirection(GridDirection::Row)
                            ->extraAttributes(['class' => 'mayap-destino'])
                            ->helperText('Marca URGENTE solo si de verdad para la obra: es lo que decide a qué máquina le entra primero el taller.')
                            ->columnSpanFull(),

                        ToggleButtons::make('lugar_reparacion')
                            ->label('¿Dónde se repara?')
                            ->options(LugarReparacion::class)
                            ->default(LugarReparacion::EnObra->value)
                            ->live()
                            ->required(fn (Get $get): bool => (bool) $get('reportar_averia'))
                            ->visible(fn (Get $get): bool => (bool) $get('reportar_averia'))
                            ->columns(2)
                            ->gridDirection(GridDirection::Row)
                            ->extraAttributes(['class' => 'mayap-destino'])
                            ->helperText(fn (Get $get): string => $this->lugarDe($get('lugar_reparacion'))?->getDescription()
                                ?? 'De esto depende si la obra conserva la máquina o hay que cubrirla.')
                            ->columnSpanFull(),

                        Textarea::make('averia_necesita')
                            ->label('¿Qué se necesita para repararla ahí?')
                            ->rows(2)
                            ->placeholder('Repuesto, herramienta, un mecánico…')
                            ->visible(fn (Get $get): bool => (bool) $get('reportar_averia')
                                && $this->lugarDe($get('lugar_reparacion')) === LugarReparacion::EnObra)
                            ->helperText('Maquinaria y recepción reciben el pedido con el nombre de la obra para despacharlo.')
                            ->columnSpanFull(),

                        Radio::make('destino_agenda')
                            ->label('¿Con qué se cubren los días ya agendados?')
                            ->options($this->destinosSiSaleDeLaObra())
                            ->descriptions(DestinoAgendaFutura::descripciones())
                            ->default(DestinoAgendaFutura::Cancelar->value)
                            ->live()
                            ->visible(fn (Get $get): bool => (bool) $get('reportar_averia')
                                && $this->lugarDe($get('lugar_reparacion')) === LugarReparacion::Taller
                                && (int) $get('agendados_futuros') > 0)
                            ->columnSpanFull(),

                        Select::make('sustituta_id')
                            ->label('¿Qué máquina mandan en su lugar?')
                            ->options(fn (Get $get) => Maquina::query()
                                ->activas()
                                ->where('estado', EstadoMaquina::Disponible->value)
                                ->whereKeyNot((int) $get('maquina_id'))
                                ->orderBy('nombre')
                                ->pluck('nombre', 'id'))
                            ->searchable()
                            ->visible(fn (Get $get): bool => (bool) $get('reportar_averia')
                                && $this->lugarDe($get('lugar_reparacion')) === LugarReparacion::Taller
                                && ((int) $get('agendados_futuros') === 0
                                    || $get('destino_agenda') === DestinoAgendaFutura::Sustituta->value))
                            ->required(fn (Get $get): bool => (bool) $get('reportar_averia')
                                && $this->lugarDe($get('lugar_reparacion')) === LugarReparacion::Taller
                                && (int) $get('agendados_futuros') > 0
                                && $get('destino_agenda') === DestinoAgendaFutura::Sustituta->value)
                            ->helperText('Toma su lugar en la obra y hereda los días agendados.')
                            ->columnSpanFull(),

                        Hidden::make('agendados_futuros'),
                        Hidden::make('modalidad_maquina'),
                        Hidden::make('proyecto_id'),
                    ])
                    ->columns(1),
            ])
            ->fillForm(fn (array $arguments): array => [
                'maquina_id'          => $arguments['maquina_id'] ?? null,
                'proyecto_id'         => $arguments['proyecto_id'] ?? null,
                'asignacion_id'       => $arguments['asignacion_id'] ?? null,
                'etiqueta'            => $arguments['etiqueta'] ?? '',
                'fecha'               => $arguments['fecha'] ?? today()->toDateString(),
                'horas'               => null,
                'modalidad'           => $arguments['modalidad_maquina'] ?? ModalidadTrabajo::Horas->value,
                'modalidad_maquina'   => $arguments['modalidad_maquina'] ?? null,
                'horas_origen'        => $arguments['horas_origen'] ?? null,
                'km_recorridos'       => null,
                'viajes'              => null,
                'actividad'           => null,
                'jornada_maquina'     => $arguments['jornada_maquina'] ?? null,
                'litros_hora_maquina' => $arguments['litros_hora_maquina'] ?? null,
                'litros'              => null,
                'precio_litro'        => app(RegistrarDiaMaquinaService::class)->ultimoPrecioLitro(),
                'motivo_extra'        => null,
                'operador_id'         => $arguments['operador_habitual_id'] ?? null,
                'reportar_averia'     => false,
                'averia_motivo'       => null,
                'lugar_reparacion'    => LugarReparacion::EnObra->value,
                'averia_prioridad'    => PrioridadMantenimiento::Normal->value,
                'averia_necesita'     => null,
                'sustituta_id'        => null,
                'destino_agenda'      => DestinoAgendaFutura::Cancelar->value,
                'agendados_futuros'   => $arguments['agendados_futuros'] ?? 0,
            ])
            ->action(function (array $data): void {
                $this->guardarJornada($data);
            });
    }

    /**
     * @param array<string, mixed> $data
     */
    private function guardarJornada(array $data): void
    {
        $fecha = (string) ($data['fecha'] ?? today()->toDateString());
        $quiereJornada = filled($data['horas'] ?? null) || filled($data['litros'] ?? null);

        $partes = 0;
        $consumos = 0;
        $saltados = [];

        if ($quiereJornada && $data['asignacion_id'] === null) {
            $saltados[] = 'Horas/combustible no registrados: la máquina no tiene asignación activa a esta obra.';
        }

        if ($quiereJornada && $data['asignacion_id'] !== null) {
            $resultado = app(RegistrarDiaMaquinaService::class)->capturar(
                fecha: $fecha,
                filas: [[
                    'asignacion_id' => (int) $data['asignacion_id'],
                    'horas'         => $data['horas'] ?? null,
                    'motivo_extra'  => $data['motivo_extra'] ?? null,
                    'modalidad'     => $data['modalidad'] ?? null,
                    'km_recorridos' => $data['km_recorridos'] ?? null,
                    'viajes'        => $data['viajes'] ?? null,
                    'actividad'     => $data['actividad'] ?? null,
                    'litros'        => $data['litros'] ?? null,
                    'precio_litro'  => $data['precio_litro'] ?? null,
                    'operador_id'   => $data['operador_id'] ?? null,
                ]],
                userId: is_numeric(auth()->id()) ? (int) auth()->id() : null,
            );

            $partes = $resultado['partes'];
            $consumos = $resultado['consumos'];
            $saltados = [...$saltados, ...$resultado['saltados']];
        }

        // Avería DESPUÉS de registrar la jornada: enviar a mantenimiento
        // finaliza la asignación activa (el parte debe entrar antes).
        $averiaReportada = false;

        if (($data['reportar_averia'] ?? false) && filled($data['averia_motivo'] ?? null)) {
            try {
                $maquina = Maquina::findOrFail((int) $data['maquina_id']);

                // La emergencia decide el destino de la agenda futura;
                // sin decisión (sin días futuros), aplica lo clásico.
                $lugar = $this->lugarDe($data['lugar_reparacion'] ?? null) ?? LugarReparacion::EnObra;

                $destino = $lugar === LugarReparacion::Taller
                    && (int) ($data['agendados_futuros'] ?? 0) > 0
                    && filled($data['destino_agenda'] ?? null)
                        ? DestinoAgendaFutura::from((string) $data['destino_agenda'])
                        : null;

                $sustituta = $lugar === LugarReparacion::Taller
                    && ($destino === null || $destino === DestinoAgendaFutura::Sustituta)
                    && filled($data['sustituta_id'] ?? null)
                        ? Maquina::find((int) $data['sustituta_id'])
                        : null;

                app(MantenimientoService::class)->enviarAMantenimiento(
                    maquina: $maquina,
                    motivo: (string) $data['averia_motivo'],
                    sustituta: $sustituta,
                    fecha: $fecha,
                    destinoAgenda: $destino,
                    lugar: $lugar,
                    necesita: filled($data['averia_necesita'] ?? null) ? (string) $data['averia_necesita'] : null,
                    proyectoId: filled($data['proyecto_id'] ?? null) ? (int) $data['proyecto_id'] : null,
                    prioridad: $this->prioridadDe($data['averia_prioridad'] ?? null),
                );
                $averiaReportada = true;
            } catch (MaquinariaException $e) {
                $saltados[] = "Avería: {$e->getMessage()}";
            }
        }

        $registrado = array_filter([
            $partes > 0 ? "{$partes} parte(s)" : null,
            $consumos > 0 ? "{$consumos} consumo(s)" : null,
            $averiaReportada ? 'avería reportada (en mantenimiento)' : null,
        ]);

        if ($registrado === []) {
            Notification::make()
                ->title('Nada registrado')
                ->body($saltados === [] ? 'Llena horas, litros o reporta la avería.' : implode(' · ', $saltados))
                ->warning()
                ->persistent()
                ->send();

            return;
        }

        $notificacion = Notification::make()
            ->title('Jornada registrada: '.implode(' + ', $registrado))
            ->success();

        if ($saltados !== []) {
            $notificacion->body('Pendiente: '.implode(' · ', $saltados))->warning()->persistent();
        }

        $notificacion->send();
        $this->refreshRecords();
    }

    /**
     * Acción "agendar" del widget — la monta onDateSelect al arrastrar
     * sobre los días. Misma definición compartida que el botón de la
     * página y la Resource de Agenda.
     */
    public function agendarAction(): Action
    {
        return AgendarMaquinasAction::make()
            ->after(fn () => $this->refreshRecords());
    }

    /**
     * Drag (o click) sobre días del calendario → modal Agendar con el
     * rango YA prellenado. El atajo principal para agendar rápido.
     *
     * @param array<string, mixed>|null $view
     * @param array<string, mixed>|null $resource
     */
    #[Override]
    public function onDateSelect(string $start, ?string $end, bool $allDay, ?array $view, ?array $resource): void
    {
        if (! (auth()->user()?->can('Create:AgendaMaquina') ?? false)) {
            return;
        }

        [$inicio, $fin] = $this->calculateTimezoneOffset($start, $end, $allDay);

        // FullCalendar manda el fin EXCLUSIVO en selecciones all-day.
        $hasta = $fin?->subDay() ?? $inicio;

        // El pasado no se agenda (misma regla del service, decisión
        // Mauricio 2026-07-22): drag sobre días idos ni abre el modal,
        // y un rango que arranca atrás se recorta a hoy.
        if ($hasta->lt(today())) {
            Notification::make()
                ->title('El pasado no se agenda')
                ->body('Esos días ya pasaron — lo trabajado vive en los partes de trabajo. Agenda de hoy en adelante.')
                ->info()
                ->send();

            return;
        }

        $desde = $inicio->lt(today()) ? today() : $inicio;

        $this->mountAction('agendar', [
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
        ]);
    }

    /**
     * FullCalendar lo llama con el rango visible.
     *
     * @param array{start: string, end: string, timezone: string} $fetchInfo
     *
     * @return array<int, array<string, mixed>>
     */
    #[Override]
    public function fetchEvents(array $fetchInfo): array
    {
        return app(CalendarioMaquinariaService::class)->eventos(
            substr($fetchInfo['start'], 0, 10),
            substr($fetchInfo['end'], 0, 10),
            $this->maquinaId,
            $this->proyectoId,
            $this->soloMisObras(),
        );
    }

    /**
     * El encargado de obra ve SOLO sus obras (mismo alcance que
     * requisiciones y solicitudes); null = sin límite (maquinaria,
     * gerencia y roles de visión amplia).
     *
     * @return list<int>|null
     */
    private function soloMisObras(): ?array
    {
        $user = auth()->user();

        if (! $user instanceof User || ! Roles::soloEncargado($user)) {
            return null;
        }

        // array_values + cast: PHPStan exige list<int>, no array<mixed>.
        return array_values(Proyecto::query()
            ->whereHas('encargados', fn ($q) => $q->whereKey($user->id))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all());
    }

    /** La página manda los filtros; el calendario re-pide sus eventos. */
    #[On('calendario-maquinaria-filtrar')]
    public function filtrar(?int $maquinaId = null, ?int $proyectoId = null): void
    {
        $this->maquinaId = $maquinaId;
        $this->proyectoId = $proyectoId;

        $this->refreshRecords();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function config(): array
    {
        return [
            'locale'        => 'es',
            'firstDay'      => 1,
            'initialView'   => 'dayGridMonth',
            'height'        => 'auto',
            'headerToolbar' => [
                'left'   => 'prev,next today',
                'center' => 'title',
                'right'  => 'dayGridMonth,listWeek',
            ],
            'buttonText' => [
                'today' => 'Hoy',
                'month' => 'Mes',
                'list'  => 'Semana',
            ],
        ];
    }

    /**
     * Bloque de horómetro y combustible del día.
     *
     * Lo ÚNICO que se pregunta es en cuánto cerró el horómetro: de ahí
     * salen las horas de motor, y de esas × el rendimiento de la ficha,
     * los litros. El precio es el último pagado, no una pregunta diaria
     * (decisión Mauricio 2026-09-05). Los litros a mano solo aparecen
     * cuando la máquina no tiene su consumo cargado — ahí no hay con qué
     * estimar y callarse sería perder el gasto.
     *
     * @return array<int, mixed>
     */
    private function camposHorometroYCombustible(): array
    {
        return [
            TextInput::make('horometro_cierre')
                ->label('Horómetro al cerrar el día')
                ->numeric()
                ->minValue(0)
                ->step('any')
                ->suffix('h')
                ->prefixIcon('heroicon-o-clock')
                ->live(debounce: 500)
                ->visible(fn (Get $get): bool => (bool) $get('usa_horometro'))
                ->helperText(fn (Get $get): string => is_numeric($get('horometro_apertura'))
                    ? 'El día abrió en '.number_format((float) $get('horometro_apertura'), 2).' h.'
                    : 'Lo que marca el reloj de horas al terminar.')
                ->columnSpanFull(),

            Placeholder::make('resumen_combustible')
                ->hiddenLabel()
                ->content($this->panelCombustible(...))
                ->columnSpanFull(),

            // Sin rendimiento cargado no hay nada que estimar: se pide.
            TextInput::make('litros')
                ->label('Combustible (litros)')
                ->numeric()
                ->minValue(0)
                ->suffix('L')
                ->prefixIcon('heroicon-o-fire')
                ->visible(fn (Get $get): bool => ! is_numeric($get('litros_hora_maquina'))
                    || (float) $get('litros_hora_maquina') <= 0.0)
                ->helperText('Esta máquina no tiene consumo promedio cargado, así que los litros no se pueden estimar. Cárgale los L/h en su ficha y deja de escribirlos.')
                ->columnSpanFull(),

            Hidden::make('horometro_apertura'),
            Hidden::make('precio_litro'),
        ];
    }

    /**
     * Los litros del día. Con rendimiento cargado se CALCULAN (horas de
     * motor del horómetro × L/h) y nadie los escribe; sin rendimiento se
     * respeta lo que se haya puesto a mano. Null = no hubo consumo que
     * registrar.
     *
     * @param array<string, mixed> $datos
     */
    private function litrosDelDia(array $datos): ?string
    {
        $rendimiento = $datos['litros_hora_maquina'] ?? null;

        if (! is_numeric($rendimiento) || (float) $rendimiento <= 0.0) {
            return filled($datos['litros'] ?? null) ? (string) $datos['litros'] : null;
        }

        $motor = $this->horasEntreLecturas(is_numeric($datos['horometro_apertura'] ?? null) ? (string) $datos['horometro_apertura'] : null, is_numeric($datos['horometro_cierre'] ?? null) ? (string) $datos['horometro_cierre'] : null);

        // Sin recorrido del horómetro se cae a las horas cobradas: es peor
        // no registrar el combustible que estimarlo con lo que hay.
        if ($motor === null) {
            $motor = is_numeric($datos['horas'] ?? null) ? (string) $datos['horas'] : null;
        }

        if ($motor === null || (float) $motor <= 0.0) {
            return null;
        }

        return number_format((float) $motor * (float) $rendimiento, 2, '.', '');
    }

    /**
     * El panel que muestra lo que el sistema dedujo: horas de motor,
     * litros y costo. Es un RESULTADO, no un formulario — por eso se lee
     * y no se llena.
     */
    private function panelCombustible(Get $get): HtmlString
    {
        $rendimiento = is_numeric($get('litros_hora_maquina')) ? (float) $get('litros_hora_maquina') : null;
        $precio = is_numeric($get('precio_litro')) ? (float) $get('precio_litro') : null;
        $motor = $this->horasEntreLecturas(is_numeric($get('horometro_apertura')) ? (string) $get('horometro_apertura') : null, is_numeric($get('horometro_cierre')) ? (string) $get('horometro_cierre') : null);

        $filas = [
            'Horas de motor' => $motor !== null ? e($motor).' h' : '—',
        ];

        if ($rendimiento !== null && $rendimiento > 0.0) {
            $litros = $motor !== null ? (float) $motor * $rendimiento : null;

            $filas['Combustible estimado'] = $litros !== null
                ? number_format($litros, 1).' L <small>· '.number_format($rendimiento, 2).' L/h</small>'
                : '—';

            $filas['Costo del combustible'] = $litros !== null && $precio !== null
                ? 'L. '.number_format($litros * $precio, 2).' <small>· L. '.number_format($precio, 2).'/L</small>'
                : '<small>falta el precio del último tanqueo</small>';
        }

        $celdas = '';

        foreach ($filas as $etiqueta => $valor) {
            $celdas .= '<div><div class="mayap-ficha__k">'.e($etiqueta).'</div>'
                .'<div class="mayap-ficha__v">'.$valor.'</div></div>';
        }

        return new HtmlString('<div class="mayap-ficha"><div class="mayap-ficha__meta" style="border-top:0">'.$celdas.'</div></div>');
    }

    /**
     * Pie de "Horas trabajadas". Cuando el número lo puso el HORÓMETRO
     * (cierre menos apertura del día) se dice, porque entonces no es una
     * sugerencia de reloj sino la lectura real de la máquina — y lo que
     * el encargado tiene que hacer es descontarle el tiempo muerto, no
     * inventar el número.
     */
    /**
     * Quién manejó hoy. Sale del CATÁLOGO de operadores, no de texto
     * libre (decisión Mauricio 2026-09-05): un operador es una persona,
     * esté en planilla o sea externo, y escribirlo a mano cada día
     * llenaba la base de "JUAN", "juan p." y "JUAN PEREZ" como si fueran
     * tres señores.
     *
     * Viene prellenado con el operador habitual de la máquina — casi
     * siempre es el mismo — y el que falta se da de alta sin salir del
     * modal, porque en obra nadie va a ir a otra pantalla a las 5 PM.
     */
    private function campoOperador(): Select
    {
        return Select::make('operador_id')
            ->label('Operador')
            ->options(fn (): array => Operador::opciones())
            ->searchable()
            ->preload()
            ->prefixIcon('heroicon-o-user')
            ->placeholder('¿Quién manejó hoy?')
            ->createOptionForm(OperadorForm::altaRapida())
            ->createOptionModalHeading('Nuevo operador')
            ->createOptionUsing(fn (array $data): int => Operador::create($data)->id)
            ->helperText('Viene puesto el operador habitual de la máquina. Si manejó otro y no está en la lista, se agrega aquí mismo.')
            ->columnSpanFull();
    }

    private function pistaHoras(Get $get): string
    {
        if ($get('modalidad') !== ModalidadTrabajo::Horas->value) {
            return 'Las horas del día SIEMPRE se anotan: son el costo interno de la obra.';
        }

        if ($get('horas_origen') === 'horometro') {
            return 'Lo que avanzó el horómetro entre la apertura y el cierre del día. Bájalo si hubo tiempo muerto (motor encendido sin trabajar).';
        }

        return 'Sugerido: el tiempo que estuvo en la obra. Ajústalo a lo real.';
    }

    /**
     * Los litros no se preguntan a ciegas: con el rendimiento nominal de
     * la máquina (L/h de su ficha) el consumo del día se estima solo en
     * cuanto hay horas. Solo rellena si el campo está vacío — lo que el
     * encargado escriba manda siempre.
     */
    private function estimarLitros(Get $get, Set $set): void
    {
        $rendimiento = $get('litros_hora_maquina');
        $horas = $get('horas');

        if (filled($get('litros'))) {
            return;
        }

        if (! is_numeric($rendimiento) || (float) $rendimiento <= 0.0) {
            return;
        }

        if (! is_numeric($horas) || (float) $horas <= 0.0) {
            return;
        }

        $set('litros', number_format((float) $horas * (float) $rendimiento, 2, '.', ''));
    }

    /**
     * Pista de consumo bajo el campo de litros de la Captura del día
     * (el modal de maquinaria, que captura varias máquinas de corrido y
     * no pasa por el ciclo llegada → cierre).
     *
     * Con el rendimiento nominal de la máquina (L/h) y las horas del día
     * se anticipa cuánto DEBERÍA haber gastado.
     */
    private function pistaConsumoEstimado(Get $get): string
    {
        $base = 'Los litros alimentan la orden de compra a la gasolinera.';

        $rendimiento = $get('litros_hora_maquina');
        $horas = $get('horas');

        if (! is_numeric($rendimiento) || (float) $rendimiento <= 0.0) {
            return $base.' (Esta máquina no tiene consumo promedio cargado.)';
        }

        if (! is_numeric($horas) || (float) $horas <= 0.0) {
            return $base.' Rinde '.number_format((float) $rendimiento, 2).' L/h.';
        }

        $estimado = (float) $horas * (float) $rendimiento;

        return $base.' Por '.number_format((float) $horas, 2).' h a '
            .number_format((float) $rendimiento, 2).' L/h, se esperarían ~'
            .number_format($estimado, 1).' L.';
    }
}
