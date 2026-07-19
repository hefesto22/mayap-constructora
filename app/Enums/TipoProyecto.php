<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Tipo de proyecto — define QUÉ se cotiza y cómo se compone.
 *
 * - Presupuestado: obra completa con renglones de fichas APU
 *   (composición por capítulos, avance físico, requisiciones, etc.).
 * - RentaMaquinaria: proyecto liviano para clientes externos que solo
 *   rentan máquinas por horas o días. La composición son líneas de
 *   renta (máquina × cantidad × tarifa) y al aprobarse se agenda
 *   automáticamente en el calendario de maquinaria.
 *
 * Ambos tipos comparten el MISMO ciclo de estados (EstadoProyecto),
 * bitácora, permisos y cliente. El tipo se fija al CREAR y no cambia:
 * convertir una renta en obra presupuestada = crear otro proyecto.
 *
 * El CHECK constraint de `proyectos` valida el conjunto.
 */
enum TipoProyecto: string implements HasColor, HasIcon, HasLabel
{
    case Presupuestado = 'presupuestado';
    case RentaMaquinaria = 'renta_maquinaria';

    public function getLabel(): string
    {
        return match ($this) {
            self::Presupuestado   => 'Presupuestado',
            self::RentaMaquinaria => 'Renta de maquinaria',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Presupuestado   => 'primary',
            self::RentaMaquinaria => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Presupuestado   => 'heroicon-o-clipboard-document-list',
            self::RentaMaquinaria => 'heroicon-o-truck',
        };
    }

    /**
     * ¿Es una renta de maquinaria? Azúcar semántica para los condicionales
     * de forms, services y policies.
     */
    public function esRenta(): bool
    {
        return $this === self::RentaMaquinaria;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(static fn (self $caso): array => [$caso->value => $caso->getLabel()])
            ->all();
    }
}
