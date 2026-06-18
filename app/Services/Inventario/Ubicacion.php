<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use App\Enums\TipoUbicacion;

/**
 * Value Object inmutable que representa una ubicación de stock concreta:
 * una bodega física O una obra (proyecto), junto con su id.
 *
 * Unifica el manejo del par `bodega_id` XOR `proyecto_id` que llevan las
 * tablas `existencias` y `movimientos_inventario`. En vez de pasar dos
 * parámetros nullable por todo el Service (y arriesgar combinaciones
 * inválidas), se pasa UN Ubicacion bien formado por construcción.
 *
 * Uso:
 *   Ubicacion::bodega(5)         // stock en la bodega 5
 *   Ubicacion::obra($proyectoId) // stock en la obra (proyecto) dada
 */
final readonly class Ubicacion
{
    private function __construct(
        public TipoUbicacion $tipo,
        public int $id,
    ) {}

    public static function bodega(int $bodegaId): self
    {
        return new self(TipoUbicacion::Bodega, $bodegaId);
    }

    public static function obra(int $proyectoId): self
    {
        return new self(TipoUbicacion::Obra, $proyectoId);
    }

    public function esBodega(): bool
    {
        return $this->tipo === TipoUbicacion::Bodega;
    }

    public function esObra(): bool
    {
        return $this->tipo === TipoUbicacion::Obra;
    }

    /**
     * Atributos para localizar/crear la fila de existencia de esta
     * ubicación: ['bodega_id' => 5, 'proyecto_id' => null] o viceversa.
     *
     * @return array<string, int|null>
     */
    public function atributosExistencia(): array
    {
        return [
            'bodega_id'   => $this->esBodega() ? $this->id : null,
            'proyecto_id' => $this->esObra() ? $this->id : null,
        ];
    }

    /**
     * Atributos para un lado (origen|destino) de un movimiento:
     * ['bodega_origen_id' => 5, 'proyecto_origen_id' => null].
     *
     * @return array<string, int|null>
     */
    public function atributosMovimiento(string $lado): array
    {
        return [
            "bodega_{$lado}_id"   => $this->esBodega() ? $this->id : null,
            "proyecto_{$lado}_id" => $this->esObra() ? $this->id : null,
        ];
    }

    public function esIgualA(self $otra): bool
    {
        return $this->tipo === $otra->tipo && $this->id === $otra->id;
    }

    public function descripcion(): string
    {
        return $this->esBodega() ? "bodega #{$this->id}" : "obra #{$this->id}";
    }
}
