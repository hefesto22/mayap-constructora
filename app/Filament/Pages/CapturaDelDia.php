<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\Maquinaria\RegistrarDiaMaquinaService;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Captura del día — planilla rápida de maquinaria: una fila por asignación
 * activa (máquina → obra), columnas horas · litros · precio/litro. Se llena
 * como Excel y UN "Guardar todo" registra partes + consumos del día
 * (RegistrarDiaMaquinaService orquesta las puertas únicas existentes).
 *
 * Las horas vienen prellenadas de la agenda azul del día; el precio del
 * litro recuerda el último usado. Filas vacías se ignoran.
 *
 * @property Schema $form
 */
class CapturaDelDia extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bolt';

    protected static string|UnitEnum|null $navigationGroup = 'Maquinaria';

    protected static ?string $navigationLabel = 'Captura del día';

    protected static ?string $title = 'Captura del día';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.captura-del-dia';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('View:CapturaDelDia') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'fecha' => today()->toDateString(),
            'filas' => app(RegistrarDiaMaquinaService::class)->filasDelDia(today()->toDateString()),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                DatePicker::make('fecha')
                    ->label('Fecha')
                    ->native(false)
                    ->maxDate(today())
                    ->required()
                    ->live()
                    // Cambió el día → recargar las filas de ese día.
                    ->afterStateUpdated(function (?string $state): void {
                        if ($state !== null) {
                            $this->form->fill([
                                'fecha' => $state,
                                'filas' => app(RegistrarDiaMaquinaService::class)->filasDelDia($state),
                            ]);
                        }
                    }),

                Repeater::make('filas')
                    ->label('Máquinas con asignación activa')
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->columns(12)
                    ->schema([
                        Hidden::make('asignacion_id'),
                        Hidden::make('ya_registrado'),

                        Placeholder::make('etiqueta_maquina')
                            ->hiddenLabel()
                            ->content(fn (Get $get): HtmlString => new HtmlString(
                                '<span style="font-weight:600">'.e((string) $get('etiqueta')).'</span>'
                                .($get('ya_registrado') !== '' && $get('ya_registrado') !== null
                                    ? '<br><span style="font-size:.75rem;color:#16a34a">'.e((string) $get('ya_registrado')).' ya registrado hoy</span>'
                                    : '')
                            ))
                            ->columnSpan(4),

                        Hidden::make('etiqueta'),

                        TextInput::make('horas')
                            ->label('Horas')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.5)
                            ->columnSpan(2),

                        TextInput::make('motivo_extra')
                            ->label('Motivo extra')
                            ->placeholder('si excede jornada')
                            ->columnSpan(2),

                        TextInput::make('litros')
                            ->label('Litros')
                            ->numeric()
                            ->minValue(0)
                            ->columnSpan(1),

                        TextInput::make('precio_litro')
                            ->label('L./litro')
                            ->numeric()
                            ->minValue(0)
                            ->columnSpan(1),

                        TextInput::make('operador')
                            ->label('Operador')
                            ->columnSpan(2),
                    ]),
            ]);
    }

    public function guardar(): void
    {
        $datos = $this->form->getState();

        /** @var list<array<string, mixed>> $filas */
        $filas = array_values($datos['filas'] ?? []);

        $resultado = app(RegistrarDiaMaquinaService::class)->capturar(
            fecha: (string) $datos['fecha'],
            filas: $filas,
            userId: is_numeric(auth()->id()) ? (int) auth()->id() : null,
        );

        if ($resultado['partes'] === 0 && $resultado['consumos'] === 0 && $resultado['saltados'] === []) {
            Notification::make()
                ->title('Nada que registrar')
                ->body('Llena horas o litros en al menos una fila.')
                ->warning()
                ->send();

            return;
        }

        $notificacion = Notification::make()
            ->title("{$resultado['partes']} parte(s) + {$resultado['consumos']} consumo(s) registrados")
            ->success();

        if ($resultado['saltados'] !== []) {
            $notificacion
                ->body('Saltados: '.implode(' · ', array_slice($resultado['saltados'], 0, 3)))
                ->warning()
                ->persistent();
        }

        $notificacion->send();

        // Recargar la planilla: las marcas "✓ ya registrado" se actualizan.
        $this->form->fill([
            'fecha' => $datos['fecha'],
            'filas' => app(RegistrarDiaMaquinaService::class)->filasDelDia((string) $datos['fecha']),
        ]);
    }
}
