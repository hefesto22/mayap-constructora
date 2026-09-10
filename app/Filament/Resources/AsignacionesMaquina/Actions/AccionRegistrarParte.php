<?php

declare(strict_types=1);

namespace App\Filament\Resources\AsignacionesMaquina\Actions;

use App\Enums\EstadoAsignacion;
use App\Enums\MetodoCapturaHoras;
use App\Enums\ModalidadTrabajo;
use App\Exceptions\Maquinaria\ParteInvalidoException;
use App\Models\AsignacionMaquina;
use App\Services\Maquinaria\RegistrarParteService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\HtmlString;

/**
 * Acción "Registrar parte" sobre una asignación activa. La modalidad se
 * sugiere de la máquina (decisión Mauricio 2026-07-20 — cada tipo trabaja
 * distinto): pesada por horómetro, pick-ups por km, volquetas por viajes,
 * camiones por flete. Las horas del día SIEMPRE se capturan (son el costo
 * interno de la obra); la modalidad agrega su dato. Delega en
 * RegistrarParteService, que valida y calcula horas extra y costo.
 */
final class AccionRegistrarParte
{
    public static function make(): Action
    {
        return Action::make('registrar_parte')
            ->label('Registrar parte')
            ->icon('heroicon-o-clock')
            ->color('primary')
            ->modalHeading('Registrar parte de trabajo')
            ->modalDescription('Captura el trabajo del día según cómo funciona la máquina: horas, kilómetros, viajes o flete. Las horas siempre se anotan — son el costo de la obra.')
            ->modalSubmitActionLabel('Registrar parte')
            ->visible(fn (AsignacionMaquina $record): bool => $record->estado === EstadoAsignacion::Activa)
            ->fillForm(function (AsignacionMaquina $record): array {
                // load() y NO loadMissing(): la tabla precarga 'maquina'
                // con pocas columnas y modalidad_trabajo llegaría null
                // (4.ª vez la misma trampa — regla de la casa).
                $record->load('maquina');

                return [
                    'modalidad'       => $record->maquina->modalidad_trabajo->value,
                    'metodo_captura'  => MetodoCapturaHoras::Horometro->value,
                    'fecha'           => now()->toDateString(),
                    'lectura_inicial' => (string) $record->maquina->horometro_actual,
                ];
            })
            ->schema([
                Select::make('modalidad')
                    ->label('Cómo trabaja esta máquina')
                    ->options(ModalidadTrabajo::options())
                    ->required()
                    ->live()
                    ->native(false)
                    ->helperText('Se sugiere según la máquina; se puede cambiar en este parte (una volqueta a veces va por horas).'),

                Select::make('metodo_captura')
                    ->label('Método de captura')
                    ->options(MetodoCapturaHoras::options())
                    ->default(MetodoCapturaHoras::Horometro->value)
                    ->required()
                    ->live()
                    ->native(false)
                    ->visible(fn (Get $get): bool => $get('modalidad') === ModalidadTrabajo::Horas->value),

                TextInput::make('lectura_inicial')
                    ->label('Lectura inicial del horómetro')
                    ->numeric()
                    ->step('any')
                    ->suffix('h')
                    ->live(onBlur: true)
                    ->afterStateUpdated(self::sugerirHorasCobradas(...))
                    ->visible(self::esPorHorometro(...))
                    ->helperText('Por defecto, el horómetro actual de la máquina. Si acá aparece un número mayor, alguien la usó sin reportar.'),

                TextInput::make('lectura_final')
                    ->label('Lectura final del horómetro')
                    ->numeric()
                    ->step('any')
                    ->suffix('h')
                    ->live(onBlur: true)
                    ->afterStateUpdated(self::sugerirHorasCobradas(...))
                    ->required(self::esPorHorometro(...))
                    ->visible(self::esPorHorometro(...)),

                // El horómetro mide MOTOR ENCENDIDO; esto es lo que se le
                // cobra a la obra. Se sugiere el delta completo y se puede
                // bajar: la diferencia es tiempo muerto, no un error.
                TextInput::make('horas')
                    ->label(fn (Get $get): string => self::esPorHorometro($get)
                        ? 'Horas a cobrar a la obra'
                        : 'Horas trabajadas')
                    ->numeric()
                    ->step('any')
                    ->minValue(0.01)
                    ->suffix('h')
                    ->live(onBlur: true)
                    ->required()
                    ->helperText(function (Get $get): string {
                        if (! self::esPorHorometro($get)) {
                            return 'Las horas del día siempre se anotan: son el costo interno de la obra.';
                        }

                        return 'Se sugiere el recorrido del horómetro. Bajalo si parte de ese tiempo fue calentamiento, espera o traslado dentro de la obra.';
                    }),

                // Resumen en vivo: que el que captura VEA el tiempo muerto
                // mientras escribe, no después de guardar.
                Placeholder::make('resumen_horas')
                    ->hiddenLabel()
                    ->visible(self::esPorHorometro(...))
                    ->content(self::resumenHoras(...)),

                Textarea::make('motivo_idle')
                    ->label('¿Por qué tanto tiempo muerto?')
                    ->rows(2)
                    ->visible(self::esPorHorometro(...))
                    ->placeholder('SE LLOVIO TODA LA TARDE / ESPERANDO MATERIAL / TRASLADO DENTRO DE LA OBRA')
                    ->helperText('Obligatorio solo si el tiempo muerto pasa del 35% del tiempo de motor. Por debajo de eso es un día normal.'),

                // ── Kilometraje (pick-ups) ─────────────────────────────
                TextInput::make('km_recorridos')
                    ->label('Kilómetros recorridos')
                    ->numeric()
                    ->step('any')
                    ->minValue(0.01)
                    ->suffix('km')
                    ->required(fn (Get $get): bool => $get('modalidad') === ModalidadTrabajo::Kilometraje->value)
                    ->visible(fn (Get $get): bool => $get('modalidad') === ModalidadTrabajo::Kilometraje->value)
                    ->helperText('Suman al kilometraje de la máquina y alimentan su mantenimiento por km.'),

                // ── Viajes (volquetas) ─────────────────────────────────
                TextInput::make('viajes')
                    ->label('Viajes del día')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->required(fn (Get $get): bool => $get('modalidad') === ModalidadTrabajo::Viajes->value)
                    ->visible(fn (Get $get): bool => $get('modalidad') === ModalidadTrabajo::Viajes->value),

                TextInput::make('viaje_origen')
                    ->label('Origen')
                    ->maxLength(150)
                    ->mayusculas()
                    ->placeholder('BANCO DE ARENA')
                    ->visible(fn (Get $get): bool => $get('modalidad') === ModalidadTrabajo::Viajes->value),

                TextInput::make('viaje_destino')
                    ->label('Destino')
                    ->maxLength(150)
                    ->mayusculas()
                    ->placeholder('OBRA LAS FLORES')
                    ->visible(fn (Get $get): bool => $get('modalidad') === ModalidadTrabajo::Viajes->value),

                TextInput::make('viaje_material')
                    ->label('Material acarreado (opcional)')
                    ->maxLength(150)
                    ->mayusculas()
                    ->placeholder('MATERIAL SELECTO')
                    ->visible(fn (Get $get): bool => $get('modalidad') === ModalidadTrabajo::Viajes->value),

                // ── Flete / actividad (camiones, pick-ups) ─────────────
                TextInput::make('actividad')
                    ->label('Actividad / flete')
                    ->maxLength(255)
                    ->mayusculas()
                    ->placeholder('FLETE DE CEMENTO A LA OBRA X')
                    ->required(fn (Get $get): bool => $get('modalidad') === ModalidadTrabajo::Flete->value)
                    ->visible(fn (Get $get): bool => $get('modalidad') === ModalidadTrabajo::Flete->value),

                DatePicker::make('fecha')
                    ->label('Fecha')
                    ->default(now())
                    ->required()
                    ->native(false)
                    ->maxDate(now()),

                TextInput::make('operador')
                    ->label('Operador')
                    ->maxLength(150)
                    ->placeholder('Nombre del operador'),

                Textarea::make('motivo_horas_extra')
                    ->label('Motivo de horas extra')
                    ->rows(2)
                    ->helperText('Obligatorio solo si las horas superan la jornada estándar de la máquina.'),

                Textarea::make('notas')
                    ->label('Notas')
                    ->rows(2),
            ])
            ->action(function (AsignacionMaquina $record, array $data): void {
                $userId = auth()->id();
                $userId = is_numeric($userId) ? (int) $userId : null;

                $service = app(RegistrarParteService::class);

                $modalidad = ModalidadTrabajo::from((string) $data['modalidad']);
                $porHorometro = $modalidad === ModalidadTrabajo::Horas
                    && $data['metodo_captura'] === MetodoCapturaHoras::Horometro->value;

                $kmRecorridos = isset($data['km_recorridos']) && $data['km_recorridos'] !== ''
                    ? (string) $data['km_recorridos']
                    : null;
                $viajes = isset($data['viajes']) && $data['viajes'] !== '' ? (int) $data['viajes'] : null;

                try {
                    if ($porHorometro) {
                        $service->registrarPorHorometro(
                            asignacion: $record,
                            lecturaFinal: (string) $data['lectura_final'],
                            lecturaInicial: isset($data['lectura_inicial'])
                                ? (string) $data['lectura_inicial']
                                : null,
                            // Si viene vacío, el service cobra el delta entero.
                            horasCobradas: isset($data['horas']) && $data['horas'] !== ''
                                ? (string) $data['horas']
                                : null,
                            motivoIdle: $data['motivo_idle'] ?? null,
                            fecha: isset($data['fecha']) ? (string) $data['fecha'] : null,
                            motivoHorasExtra: $data['motivo_horas_extra'] ?? null,
                            operador: $data['operador'] ?? null,
                            userId: $userId,
                            notas: $data['notas'] ?? null,
                            modalidad: $modalidad,
                            kmRecorridos: $kmRecorridos,
                            viajes: $viajes,
                            viajeOrigen: $data['viaje_origen'] ?? null,
                            viajeDestino: $data['viaje_destino'] ?? null,
                            viajeMaterial: $data['viaje_material'] ?? null,
                            actividad: $data['actividad'] ?? null,
                        );
                    } else {
                        $service->registrarManual(
                            asignacion: $record,
                            horas: (string) $data['horas'],
                            fecha: isset($data['fecha']) ? (string) $data['fecha'] : null,
                            motivoHorasExtra: $data['motivo_horas_extra'] ?? null,
                            operador: $data['operador'] ?? null,
                            userId: $userId,
                            notas: $data['notas'] ?? null,
                            modalidad: $modalidad,
                            kmRecorridos: $kmRecorridos,
                            viajes: $viajes,
                            viajeOrigen: $data['viaje_origen'] ?? null,
                            viajeDestino: $data['viaje_destino'] ?? null,
                            viajeMaterial: $data['viaje_material'] ?? null,
                            actividad: $data['actividad'] ?? null,
                        );
                    }
                } catch (ParteInvalidoException $e) {
                    Notification::make()
                        ->title('No se pudo registrar el parte')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Parte registrado')
                    ->body('El trabajo del día y su costo se cargaron a la obra.')
                    ->success()
                    ->send();
            });
    }

    /**
     * ¿Este parte se está capturando leyendo el horómetro?
     */
    private static function esPorHorometro(Get $get): bool
    {
        return $get('modalidad') === ModalidadTrabajo::Horas->value
            && $get('metodo_captura') === MetodoCapturaHoras::Horometro->value;
    }

    /**
     * Al mover cualquiera de las dos lecturas, sugiere el recorrido completo
     * del horómetro como horas a cobrar. Quien captura lo baja si hubo
     * tiempo muerto.
     */
    private static function sugerirHorasCobradas(Get $get, Set $set): void
    {
        $motor = self::horasMotor($get);

        if ($motor !== null) {
            $set('horas', $motor);
        }
    }

    /**
     * Recorrido del horómetro, o null si las lecturas no están completas.
     */
    private static function horasMotor(Get $get): ?float
    {
        $inicial = $get('lectura_inicial');
        $final = $get('lectura_final');

        if (! is_numeric($inicial) || ! is_numeric($final)) {
            return null;
        }

        $motor = (float) $final - (float) $inicial;

        return $motor >= 0 ? round($motor, 2) : null;
    }

    /**
     * Lo que ve quien captura mientras escribe: cuánto corrió el motor,
     * cuánto se cobra y cuánto queda como tiempo muerto.
     */
    private static function resumenHoras(Get $get): HtmlString
    {
        $motor = self::horasMotor($get);

        if ($motor === null || $motor <= 0.0) {
            return new HtmlString('<span style="opacity:.6;">Escribí las dos lecturas para ver el resumen.</span>');
        }

        $cobradas = is_numeric($get('horas')) ? (float) $get('horas') : $motor;
        $muertas = max(0.0, round($motor - $cobradas, 2));
        $pct = round($muertas / $motor * 100, 1);

        // 35% es el tope que el service tolera sin pedir explicación: la
        // construcción promedia ~30% de idle sobre el tiempo de motor.
        $color = $pct > 35.0 ? '#b45309' : '#15803d';
        $aviso = $pct > 35.0
            ? ' — hace falta escribir el motivo'
            : '';

        return new HtmlString(
            '<div style="border:1px solid rgba(128,128,128,.25); border-radius:10px; padding:10px 14px; font-size:.85rem;">'
            .'<strong>'.number_format($motor, 2).' h</strong> de motor · '
            .'<strong>'.number_format($cobradas, 2).' h</strong> a la obra · '
            .'<span style="color:'.$color.'; font-weight:700;">'
            .number_format($muertas, 2).' h muertas ('.$pct.'%)'.$aviso
            .'</span></div>'
        );
    }
}
