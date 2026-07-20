<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\EstadoAsignacion;
use App\Enums\EstadoMaquina;
use App\Exceptions\Maquinaria\MaquinariaException;
use App\Filament\Actions\AgendarMaquinasAction;
use App\Models\AgendaMaquina;
use App\Models\AsignacionMaquina;
use App\Models\Maquina;
use App\Models\Proyecto;
use App\Models\User;
use App\Services\Maquinaria\AsignarMaquinaService;
use App\Services\Maquinaria\CalendarioMaquinariaService;
use App\Services\Maquinaria\ConfirmarLlegadaService;
use App\Services\Maquinaria\MantenimientoService;
use App\Services\Maquinaria\MarcarNoLlegoAgendaService;
use App\Services\Maquinaria\RegistrarDiaMaquinaService;
use App\Support\Roles;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\On;
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

        if (! (auth()->user()?->can('View:CapturaDelDia') ?? false)) {
            return;
        }

        if (str_starts_with($id, 'asignacion-')) {
            $asignacion = AsignacionMaquina::with(['maquina:id,nombre', 'proyecto:id,nombre'])
                ->find((int) substr($id, 11));

            if ($asignacion === null || $asignacion->estado !== EstadoAsignacion::Activa) {
                return;
            }

            $this->montarRegistrarDia(
                maquinaId: $asignacion->maquina_id,
                proyectoId: $asignacion->proyecto_id,
                etiqueta: "{$asignacion->maquina->nombre} → {$asignacion->proyecto->nombre}",
                fecha: today()->toDateString(),
            );
        }

        // parte- / mantenimiento-: informativos, sin acción.
    }

    /**
     * El CICLO del día de un agendado, para cualquier rol con permiso
     * sobre esa obra: valida ANTES de abrir el modal para hablar claro —
     * sin permiso (silencio), todavía no es el día (aviso), la máquina
     * sigue en OTRA obra (aviso con el dato). Según el punto del ciclo:
     * AZUL "Confirmar llegada" → VIOLETA "¿Ya terminó aquí?" → al terminar
     * se abre "Registrar jornada". Cerrado el ciclo, el click vuelve a
     * ofrecer la jornada mientras no exista el parte (después, el verde
     * reemplaza al evento y esto ya no se alcanza).
     */
    private function montarConfirmarLlegada(AgendaMaquina $agendado): void
    {
        $user = auth()->user();
        $servicio = app(ConfirmarLlegadaService::class);

        if (! $user instanceof User || ! $servicio->puedeConfirmar($agendado, $user)) {
            return;
        }

        // Ciclo cerrado (llegó y terminó): lo que queda por hacer es la
        // JORNADA — si el evento sigue visible es porque aún no hay
        // parte de ese día.
        if ($agendado->llegada_confirmada_at !== null && $agendado->salida_confirmada_at !== null) {
            $this->mountAction('registrarJornadaSalida', $this->argsRegistrarJornada($agendado));

            return;
        }

        // Adentro de la obra (llegó, no ha salido): ofrecer la salida.
        if ($agendado->llegada_confirmada_at !== null) {
            $this->mountAction('confirmarSalida', [
                'agenda_id' => $agendado->id,
                'etiqueta'  => "{$agendado->maquina->nombre} → {$agendado->proyecto->nombre}",
                'fecha'     => $agendado->fecha->toDateString(),
                'llego'     => $agendado->llegada_confirmada_at->format('g:i A'),
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
            'agenda_id' => $agendado->id,
            'etiqueta'  => "{$agendado->maquina->nombre} → {$agendado->proyecto->nombre}",
            'fecha'     => $agendado->fecha->toDateString(),
            'hora'      => $agendado->horaEntrada12(),
        ]);
    }

    /**
     * Los argumentos que el modal "Registrar jornada" necesita, frescos
     * al momento de abrirlo: la asignación activa (tarifa pactada), los
     * datos de la máquina (jornada estándar y tarifa por defecto) y las
     * horas sugeridas según el ciclo llegó → terminó.
     *
     * @return array<string, mixed>
     */
    private function argsRegistrarJornada(AgendaMaquina $agendado): array
    {
        $agendado->loadMissing(['maquina:id,nombre', 'proyecto:id,nombre']);

        $asignacionId = AsignacionMaquina::query()
            ->where('maquina_id', $agendado->maquina_id)
            ->where('proyecto_id', $agendado->proyecto_id)
            ->where('estado', EstadoAsignacion::Activa->value)
            ->value('id');

        $maquinaDatos = Maquina::query()
            ->whereKey($agendado->maquina_id)
            ->first(['jornada_horas', 'tarifa_hora']);

        // Sugerencia honesta: de la llegada a la salida (o a ahora),
        // redondeado a media hora — quien registra lo ajusta a lo real.
        $hasta = $agendado->salida_confirmada_at ?? now();
        $horasSugeridas = $agendado->llegada_confirmada_at !== null
            ? round($agendado->llegada_confirmada_at->diffInMinutes($hasta) / 30) / 2
            : 0.0;

        return [
            'agenda_id'       => $agendado->id,
            'etiqueta'        => "{$agendado->maquina->nombre} → {$agendado->proyecto->nombre}",
            'fecha'           => $agendado->fecha->toDateString(),
            'llego'           => $agendado->llegada_confirmada_at?->format('g:i A'),
            'salio'           => $agendado->salida_confirmada_at?->format('g:i A'),
            'asignacion_id'   => $asignacionId,
            'jornada_maquina' => $maquinaDatos?->jornada_horas !== null ? (string) $maquinaDatos->jornada_horas : null,
            'tarifa_maquina'  => $maquinaDatos?->tarifa_hora !== null ? (string) $maquinaDatos->tarifa_hora : null,
            'horas_sugeridas' => $horasSugeridas > 0 ? number_format($horasSugeridas, 1, '.', '') : null,
        ];
    }

    /**
     * PASO 1 — Modal "¿Ya terminó aquí?": solo confirma la SALIDA (la
     * máquina queda libre, campanita a maquinaria) y ENCADENA el paso 2,
     * "Registrar jornada" (decisión Mauricio 2026-07-16: primero se
     * marca que terminó, después se capturan horas y litros).
     */
    public function confirmarSalidaAction(): Action
    {
        return Action::make('confirmarSalida')
            ->modalHeading('¿La máquina ya terminó aquí?')
            ->modalWidth('lg')
            ->modalSubmitActionLabel('Sí, ya terminó')
            ->schema([
                Placeholder::make('resumen')
                    ->hiddenLabel()
                    ->content(fn (Get $get): HtmlString => new HtmlString(
                        '<div style="padding:.75rem 1rem;border-radius:.5rem;background:rgba(37,99,235,.08);border:1px solid rgba(37,99,235,.25)">'
                        .'<span style="font-weight:700;font-size:1.05rem">'.e((string) $get('etiqueta')).'</span>'
                        .'<br><span style="color:#6b7280;font-size:.85rem">'.e(Carbon::parse((string) $get('fecha'))->format('d/m/Y'))
                        .' · llegó '.e((string) $get('llego')).'</span>'
                        .'<br><span style="color:#6b7280;font-size:.85rem">Al confirmar, la máquina queda libre, se avisa a maquinaria y podrás registrar la jornada (horas y combustible).</span>'
                        .'</div>'
                    )),

                Hidden::make('agenda_id'),
                Hidden::make('etiqueta'),
                Hidden::make('fecha'),
                Hidden::make('llego'),
            ])
            ->fillForm(fn (array $arguments): array => [
                'agenda_id' => $arguments['agenda_id'] ?? null,
                'etiqueta'  => $arguments['etiqueta'] ?? '',
                'fecha'     => $arguments['fecha'] ?? today()->toDateString(),
                'llego'     => $arguments['llego'] ?? '',
            ])
            ->action(function (array $data): void {
                $agendado = AgendaMaquina::find((int) ($data['agenda_id'] ?? 0));
                $user = auth()->user();

                if ($agendado === null || ! $user instanceof User) {
                    return;
                }

                try {
                    $cerrado = app(ConfirmarLlegadaService::class)->confirmarSalida($agendado, $user);
                } catch (MaquinariaException $e) {
                    Notification::make()
                        ->title('No se pudo confirmar la salida')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Salida confirmada — máquina libre')
                    ->body(
                        "{$cerrado->maquina->nombre} terminó en {$cerrado->proyecto->nombre} a las "
                        .$cerrado->salida_confirmada_at?->format('g:i A')
                        .'. Maquinaria ya recibió el aviso. Ahora registra la jornada.'
                    )
                    ->success()
                    ->send();

                $this->refreshRecords();

                // PASO 2: el modal de jornada se abre solo.
                $this->replaceMountedAction('registrarJornadaSalida', $this->argsRegistrarJornada($cerrado));
            });
    }

    /**
     * PASO 2 — Modal "Registrar jornada": horas reales, combustible y
     * operador del día que acaba de cerrar. Mismas reglas que la Captura
     * del día (RegistrarDiaMaquinaService). Sin asignación previa, se
     * crea UNA automática con la tarifa estándar de la máquina y se
     * libera al guardar — todo queda en la bitácora de la máquina y en
     * el historial del proyecto. "Ahora no" deja la salida confirmada;
     * el evento gris del calendario vuelve a ofrecer la jornada.
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
                    ->content(fn (Get $get): HtmlString => new HtmlString(
                        '<div style="padding:.75rem 1rem;border-radius:.5rem;background:rgba(22,163,74,.08);border:1px solid rgba(22,163,74,.25)">'
                        .'<span style="font-weight:700;font-size:1.05rem">'.e((string) $get('etiqueta')).'</span>'
                        .'<br><span style="color:#6b7280;font-size:.85rem">'.e(Carbon::parse((string) $get('fecha'))->format('d/m/Y'))
                        .($get('llego') !== null ? ' · llegó '.e((string) $get('llego')) : '')
                        .($get('salio') !== null ? ' → terminó '.e((string) $get('salio')) : '')
                        .'</span>'
                        .($get('asignacion_id') === null
                            ? '<br><span style="color:#6b7280;font-size:.85rem">ℹ Sin asignación previa: al guardar, la máquina se asignará sola a esta obra por ese día con su tarifa estándar'
                                .($get('tarifa_maquina') !== null ? ' (L. '.e((string) $get('tarifa_maquina')).'/h)' : '')
                                .' y quedará libre.</span>'
                            : '')
                        .'</div>'
                    )),

                Hidden::make('agenda_id'),
                Hidden::make('etiqueta'),
                Hidden::make('fecha'),
                Hidden::make('llego'),
                Hidden::make('salio'),
                Hidden::make('asignacion_id'),
                Hidden::make('jornada_maquina'),
                Hidden::make('tarifa_maquina'),

                Fieldset::make('Jornada del día')
                    ->schema([
                        TextInput::make('horas')
                            ->label('Horas trabajadas')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.5)
                            ->suffix('h')
                            ->prefixIcon('heroicon-o-clock')
                            ->helperText('Sugerido: el tiempo que estuvo en la obra. Ajústalo a lo real.')
                            ->live(debounce: 400),

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

                        TextInput::make('litros')
                            ->label('Combustible (litros)')
                            ->numeric()
                            ->minValue(0)
                            ->suffix('L')
                            ->prefixIcon('heroicon-o-fire'),

                        TextInput::make('precio_litro')
                            ->label('Precio por litro')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('L.'),

                        TextInput::make('operador')
                            ->label('Operador')
                            ->prefixIcon('heroicon-o-user')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ])
            ->fillForm(fn (array $arguments): array => [
                'agenda_id'       => $arguments['agenda_id'] ?? null,
                'etiqueta'        => $arguments['etiqueta'] ?? '',
                'fecha'           => $arguments['fecha'] ?? today()->toDateString(),
                'llego'           => $arguments['llego'] ?? null,
                'salio'           => $arguments['salio'] ?? null,
                'asignacion_id'   => $arguments['asignacion_id'] ?? null,
                'jornada_maquina' => $arguments['jornada_maquina'] ?? null,
                'tarifa_maquina'  => $arguments['tarifa_maquina'] ?? null,
                'horas'           => $arguments['horas_sugeridas'] ?? null,
                'motivo_extra'    => null,
                'litros'          => null,
                'precio_litro'    => app(RegistrarDiaMaquinaService::class)->ultimoPrecioLitro(),
                'operador'        => null,
            ])
            ->action(function (array $data): void {
                $agendado = AgendaMaquina::with(['maquina:id,nombre', 'proyecto:id,nombre'])
                    ->find((int) ($data['agenda_id'] ?? 0));
                $user = auth()->user();

                if ($agendado === null || ! $user instanceof User) {
                    return;
                }

                if (! filled($data['horas'] ?? null) && ! filled($data['litros'] ?? null)) {
                    Notification::make()
                        ->title('Nada registrado')
                        ->body('Llena las horas trabajadas o los litros de combustible — o pulsa "Ahora no".')
                        ->warning()
                        ->send();

                    return;
                }

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
                            'litros'        => $data['litros'] ?? null,
                            'precio_litro'  => $data['precio_litro'] ?? null,
                            'operador'      => $data['operador'] ?? null,
                        ]],
                        userId: $user->id,
                    );

                    $partes = $resultado['partes'];
                    $consumos = $resultado['consumos'];
                    $saltados = [...$saltados, ...$resultado['saltados']];
                }

                // La asignación automática fue solo para ESTA jornada:
                // se finaliza de inmediato y la máquina vuelve a Disponible.
                if ($asignacionAutomatica !== null) {
                    app(AsignarMaquinaService::class)->finalizar(
                        $asignacionAutomatica,
                        $agendado->fecha->toDateString(),
                    );
                }

                $jornada = array_filter([
                    $partes > 0 ? "{$partes} parte(s) de horas" : null,
                    $consumos > 0 ? "{$consumos} consumo(s) de combustible" : null,
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
            ->modalWidth('lg')
            ->modalSubmitActionLabel('Sí, ya llegó')
            ->schema([
                Placeholder::make('resumen')
                    ->hiddenLabel()
                    ->content(fn (Get $get): HtmlString => new HtmlString(
                        '<div style="padding:.75rem 1rem;border-radius:.5rem;background:rgba(22,163,74,.08);border:1px solid rgba(22,163,74,.25)">'
                        .'<span style="font-weight:700;font-size:1.05rem">'.e((string) $get('etiqueta')).'</span>'
                        .'<br><span style="color:#6b7280;font-size:.85rem">'.e(Carbon::parse((string) $get('fecha'))->format('d/m/Y'))
                        .($get('hora') !== null ? ' · llegada prevista '.e((string) $get('hora')) : '')
                        .'</span>'
                        .'<br><span style="color:#6b7280;font-size:.85rem">Al confirmar queda registrada la hora y se avisa a maquinaria.</span>'
                        .'</div>'
                    )),

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
                    $confirmado = app(ConfirmarLlegadaService::class)->confirmar($agendado, $user);

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
                        $confirmado = app(ConfirmarLlegadaService::class)->confirmar($agendado, $user);

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

        // Para el aviso de avería: cuántos agendados (desde este día)
        // resolvería un mantenimiento — transferidos o cancelados.
        $agendadosFuturos = AgendaMaquina::query()
            ->where('maquina_id', $maquinaId)
            ->whereDate('fecha', '>=', $fecha)
            ->count();

        // La jornada estándar de la máquina: umbral del motivo de horas
        // extra (misma regla que RegistrarParteService).
        $jornadaMaquina = Maquina::query()->whereKey($maquinaId)->value('jornada_horas');

        $this->mountAction('registrarDia', [
            'maquina_id'        => $maquinaId,
            'asignacion_id'     => $asignacionId,
            'etiqueta'          => $etiqueta,
            'fecha'             => $fecha,
            'jornada_maquina'   => $jornadaMaquina !== null ? (string) $jornadaMaquina : null,
            'agendados_futuros' => $agendadosFuturos,
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
            ->visible(fn (): bool => auth()->user()?->can('View:CapturaDelDia') ?? false)
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

                Fieldset::make('Horas trabajadas')
                    ->schema([
                        TextInput::make('horas')
                            ->label('Horas reales')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.5)
                            ->suffix('h')
                            ->prefixIcon('heroicon-o-clock')
                            ->live(debounce: 400),

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
                            ->helperText('Los litros alimentan la orden de compra a la gasolinera.'),

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
                        TextInput::make('operador')
                            ->label('Operador')
                            ->prefixIcon('heroicon-o-user'),

                        Toggle::make('reportar_averia')
                            ->label('¿Se averió la máquina?')
                            ->live()
                            ->inline(false),

                        Textarea::make('averia_motivo')
                            ->label('¿Qué se averió?')
                            ->rows(2)
                            ->visible(fn (Get $get): bool => (bool) $get('reportar_averia'))
                            ->requiredIf('reportar_averia', true)
                            ->helperText('Se envía a mantenimiento y su asignación activa se finaliza.')
                            ->columnSpanFull(),

                        Select::make('sustituta_id')
                            ->label('Máquina sustituta (opcional)')
                            ->options(fn (Get $get) => Maquina::query()
                                ->where('estado', EstadoMaquina::Disponible->value)
                                ->whereKeyNot((int) $get('maquina_id'))
                                ->orderBy('nombre')
                                ->pluck('nombre', 'id'))
                            ->searchable()
                            ->visible(fn (Get $get): bool => (bool) $get('reportar_averia'))
                            ->helperText('Toma su lugar en la obra y hereda los días agendados.'),

                        Placeholder::make('aviso_agenda')
                            ->hiddenLabel()
                            ->visible(fn (Get $get): bool => (bool) $get('reportar_averia') && (int) $get('agendados_futuros') > 0)
                            ->content(fn (Get $get): HtmlString => new HtmlString(
                                '<span style="color:#d97706;font-weight:600;font-size:.85rem">⚠ Esta máquina tiene '
                                .e((string) $get('agendados_futuros'))
                                .' día(s) agendado(s) a futuro: se transferirán a la sustituta, o se cancelarán si no eliges una (con aviso a gerencia).</span>'
                            ))
                            ->columnSpanFull(),

                        Hidden::make('agendados_futuros'),
                    ])
                    ->columns(2),
            ])
            ->fillForm(fn (array $arguments): array => [
                'maquina_id'        => $arguments['maquina_id'] ?? null,
                'asignacion_id'     => $arguments['asignacion_id'] ?? null,
                'etiqueta'          => $arguments['etiqueta'] ?? '',
                'fecha'             => $arguments['fecha'] ?? today()->toDateString(),
                'horas'             => null,
                'jornada_maquina'   => $arguments['jornada_maquina'] ?? null,
                'litros'            => null,
                'precio_litro'      => app(RegistrarDiaMaquinaService::class)->ultimoPrecioLitro(),
                'motivo_extra'      => null,
                'operador'          => null,
                'reportar_averia'   => false,
                'averia_motivo'     => null,
                'sustituta_id'      => null,
                'agendados_futuros' => $arguments['agendados_futuros'] ?? 0,
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
                    'litros'        => $data['litros'] ?? null,
                    'precio_litro'  => $data['precio_litro'] ?? null,
                    'operador'      => $data['operador'] ?? null,
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

                // La sustituta toma su lugar en la obra Y hereda los días
                // agendados; sin sustituta, los futuros se cancelan con
                // aviso a maquinaria + gerencia (lo hace el service).
                $sustituta = filled($data['sustituta_id'] ?? null)
                    ? Maquina::find((int) $data['sustituta_id'])
                    : null;

                app(MantenimientoService::class)->enviarAMantenimiento(
                    maquina: $maquina,
                    motivo: (string) $data['averia_motivo'],
                    sustituta: $sustituta,
                    fecha: $fecha,
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
    public function onDateSelect(string $start, ?string $end, bool $allDay, ?array $view, ?array $resource): void
    {
        if (! (auth()->user()?->can('Create:AgendaMaquina') ?? false)) {
            return;
        }

        [$inicio, $fin] = $this->calculateTimezoneOffset($start, $end, $allDay);

        $this->mountAction('agendar', [
            'desde' => $inicio->toDateString(),
            // FullCalendar manda el fin EXCLUSIVO en selecciones all-day.
            'hasta' => $fin?->subDay()->toDateString() ?? $inicio->toDateString(),
        ]);
    }

    /**
     * FullCalendar lo llama con el rango visible.
     *
     * @param array{start: string, end: string, timezone: string} $fetchInfo
     *
     * @return array<int, array<string, mixed>>
     */
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
}
