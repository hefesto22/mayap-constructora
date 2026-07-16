<?php

declare(strict_types=1);

namespace App\Filament\Forms\Components;

use Filament\Forms\Components\Field;

/**
 * Selector de RANGO de fechas en UN solo calendario (patrón aerolínea:
 * click en el primer día, click en el último, rango sombreado).
 *
 * Montado sobre flatpickr en modo "range" (los datepickers de Filament
 * son de fecha única). El estado es una lista de fechas Y-m-d:
 * ['2026-07-15'] para un día, ['2026-07-15', '2026-07-18'] para rango.
 */
final class RangoFechas extends Field
{
    protected string $view = 'filament.forms.components.rango-fechas';
}
