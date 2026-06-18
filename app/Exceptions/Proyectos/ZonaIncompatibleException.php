<?php

declare(strict_types=1);

namespace App\Exceptions\Proyectos;

/**
 * Se lanza cuando se intenta agregar a un proyecto una ficha que
 * pertenece a otra zona.
 *
 * Es un INVARIANTE DE DOMINIO: las fichas tienen precios específicos
 * por zona; mezclar zonas en un mismo proyecto produciría cotizaciones
 * inconsistentes (cliente en TGU recibe precios de SRC, etc.).
 *
 * El UI de Filament filtra el selector de fichas por zona desde el
 * inicio para que esto no ocurra; esta excepción es la defensa última
 * (Service layer) para casos de bypass: importaciones, API directa, etc.
 */
final class ZonaIncompatibleException extends ProyectoException
{
    public function __construct(
        public readonly int $proyectoId,
        public readonly string $proyectoZonaCodigo,
        public readonly int $fichaId,
        public readonly string $fichaZonaCodigo,
    ) {
        parent::__construct(
            "La ficha #{$fichaId} es de zona {$fichaZonaCodigo}, pero el ".
            "proyecto #{$proyectoId} es de zona {$proyectoZonaCodigo}. ".
            'Las fichas y los proyectos deben pertenecer a la misma zona '.
            'porque los precios dependen de la zona.'
        );
    }
}
