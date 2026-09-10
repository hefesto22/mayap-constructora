<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\EstadoMaquina;
use App\Enums\ModalidadTrabajo;
use App\Enums\TipoMaquina;
use App\Models\Maquina;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Flota REAL de MAYAP, tomada del Excel de activos fijos (2026-09-04).
 *
 * ⚠️ DESTRUCTIVO: borra TODAS las máquinas y toda su operación (agenda,
 * asignaciones, partes, combustible, mantenimientos, solicitudes, líneas de
 * renta, planes) antes de sembrar. Es un seeder de reseteo del módulo de
 * maquinaria, no un seeder incremental. Correrlo dos veces deja el mismo
 * resultado, pero destruye lo capturado en el medio.
 *
 * ALCANCE (Mauricio, 2026-09-04): entra TODO el activo fijo rodante y de
 * campo — "se toma como maquinaria: combustible, mantenimiento, reparaciones,
 * horómetro, todo lo mismo". Las 57 filas del Excel quedan sembradas.
 *
 * La modalidad de trabajo es lo que las diferencia y define qué campos pide
 * el formulario y cómo se cobra:
 *   - horas: maquinaria pesada e implementos (llevan horómetro).
 *   - viajes: volquetas, camiones y cabezal.
 *   - kilometraje: pickups y vehículos livianos.
 *   - flete: remolques y plataformas — no tienen motor, así que no llevan
 *     horómetro ni combustible propios; se cobran por el acarreo.
 *
 * Las tarifas van todas en CERO: sin tarifa no se le puede cobrar renta a
 * nadie, así que hay que completarlas desde Maquinaria antes de rentar.
 *
 * DATOS QUE FALTAN: el Excel es un listado contable de una sola columna. No
 * trae placa, serie, año en la mayoría, horómetro, ni tarifas. Todo eso queda
 * en cero / null para que Maquinaria lo complete desde el formulario. Las
 * filas marcadas "PENDIENTE IDENTIFICAR" en las notas son las que el Excel
 * lista sin ningún dato distintivo (aparecen como "Carmix", "Camion",
 * "Volqueta", "Excavadora" a secas) o repetidas.
 */
class MaquinariaRealMayapSeeder extends Seeder
{
    /**
     * Tablas a vaciar, EN ORDEN de dependencia (hijas primero).
     *
     * Varias FKs hacia maquinas son restrictOnDelete, así que sin esta
     * limpieza el borrado revienta. compras.mantenimiento_id es nullOnDelete,
     * así que se resuelve solo.
     *
     * @var list<string>
     */
    private const array TABLAS_A_LIMPIAR = [
        'bitacoras_mantenimiento',
        'cambios_mantenimiento',
        'planes_mantenimiento',
        'partes_trabajo',
        'consumos_combustible',
        'mantenimientos_maquina',
        'solicitudes_maquina',
        'agenda_maquina',
        'proyecto_lineas_renta',
        'asignaciones_maquina',
    ];

    public function run(): void
    {
        $this->protegerProduccion();

        DB::transaction(function (): void {
            $this->limpiarOperacionDeMaquinaria();

            foreach ($this->flota() as $maquina) {
                Maquina::create([
                    ...$maquina,
                    // El código (MAQ-00001…) lo genera el modelo en creating.
                    'estado'             => EstadoMaquina::Disponible->value,
                    'horometro_actual'   => 0,
                    'kilometraje_actual' => 0,
                    'tarifa_hora'        => 0,
                    'tarifa_viaje'       => 0,
                    'tarifa_km'          => 0,
                    'horas_dia_renta'    => 8,
                    'activo'             => true,
                ]);
            }
        });

        $this->command?->info('✓ Flota real sembrada: '.Maquina::count().' máquinas.');
        $this->command?->warn('Faltan tarifas, placas, series y horómetros — se completan desde Maquinaria.');
    }

    /**
     * En producción este seeder borraría la operación real. Se exige una
     * variable de entorno explícita para correrlo ahí.
     */
    private function protegerProduccion(): void
    {
        if (App::environment('production') && env('MAQUINARIA_SEED_FORZAR') !== '1') {
            throw new RuntimeException(
                'MaquinariaRealMayapSeeder BORRA toda la operación de maquinaria. '.
                'En producción hay que correrlo con MAQUINARIA_SEED_FORZAR=1 y con respaldo hecho.'
            );
        }
    }

    private function limpiarOperacionDeMaquinaria(): void
    {
        foreach (self::TABLAS_A_LIMPIAR as $tabla) {
            DB::table($tabla)->delete();
        }

        // forceDelete: las máquinas usan SoftDeletes y un delete suave dejaría
        // los códigos MAQ-XXXXX ocupados, corriendo el correlativo sin razón.
        Maquina::withTrashed()->forceDelete();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function flota(): array
    {
        $horas = ModalidadTrabajo::Horas->value;
        $viajes = ModalidadTrabajo::Viajes->value;
        $km = ModalidadTrabajo::Kilometraje->value;
        $flete = ModalidadTrabajo::Flete->value;

        return [
            // ── Retroexcavadoras ──────────────────────────────────────────
            $this->maq('RETROEXCAVADORA 416 N.1', TipoMaquina::Retroexcavadora, $horas, marca: 'CATERPILLAR', modelo: '416'),
            $this->maq('RETROEXCAVADORA 416 N.2', TipoMaquina::Retroexcavadora, $horas, marca: 'CATERPILLAR', modelo: '416'),
            $this->maq('RETROEXCAVADORA 420E', TipoMaquina::Retroexcavadora, $horas, marca: 'CATERPILLAR', modelo: '420E'),
            $this->maq('RETROEXCAVADORA 426 JZ', TipoMaquina::Retroexcavadora, $horas, marca: 'CATERPILLAR', modelo: '426'),

            // ── Excavadoras ───────────────────────────────────────────────
            $this->maq('EXCAVADORA HYUNDAI', TipoMaquina::Excavadora, $horas, marca: 'HYUNDAI'),
            $this->maq('EXCAVADORA', TipoMaquina::Excavadora, $horas, nota: 'PENDIENTE IDENTIFICAR: el Excel de activos fijos la lista sin marca ni modelo.'),

            // ── Carmix (hormigoneras autocargantes) ───────────────────────
            $this->maq('CARMIX 2.5', TipoMaquina::Otro, $horas, marca: 'CARMIX', modelo: '2.5'),
            $this->maq('CARMIX ONE', TipoMaquina::Otro, $horas, marca: 'CARMIX', modelo: 'ONE'),
            $this->maq('CARMIX (2)', TipoMaquina::Otro, $horas, marca: 'CARMIX', nota: 'PENDIENTE IDENTIFICAR: el Excel repite "Carmix" sin modelo.'),
            $this->maq('CARMIX (3)', TipoMaquina::Otro, $horas, marca: 'CARMIX', nota: 'PENDIENTE IDENTIFICAR: el Excel repite "Carmix" sin modelo.'),

            // ── Compactación y nivelación ─────────────────────────────────
            $this->maq('VIBROCOMPACTADOR DE 1.5 TON', TipoMaquina::Compactadora, $horas),
            $this->maq('VIBROCOMPACTADOR DE 10 TON', TipoMaquina::Compactadora, $horas),
            $this->maq('MOTONIVELADORA USADA', TipoMaquina::Motoniveladora, $horas),
            $this->maq('PATROL SEM', TipoMaquina::Motoniveladora, $horas, marca: 'SEM'),
            $this->maq('TRACTOR CATERPILLAR', TipoMaquina::Bulldozer, $horas, marca: 'CATERPILLAR'),

            // ── Volquetas ─────────────────────────────────────────────────
            $this->maq('VOLQUETA INTERNACIONAL 2004', TipoMaquina::Volqueta, $viajes, marca: 'INTERNATIONAL', anio: 2004),
            $this->maq('VOLQUETA INTERNACIONAL 2006', TipoMaquina::Volqueta, $viajes, marca: 'INTERNATIONAL', anio: 2006),
            $this->maq('VOLQUETA VOLVO 2007', TipoMaquina::Volqueta, $viajes, marca: 'VOLVO', anio: 2007),
            $this->maq('VOLQUETA FREIGHTLINER 2006', TipoMaquina::Volqueta, $viajes, marca: 'FREIGHTLINER', anio: 2006),
            $this->maq('VOLQUETA STERLING 2009 (BLANCA)', TipoMaquina::Volqueta, $viajes, marca: 'STERLING', anio: 2009),
            $this->maq('VOLQUETA', TipoMaquina::Volqueta, $viajes, nota: 'PENDIENTE IDENTIFICAR: el Excel la lista sin marca ni año.'),

            // ── Camiones y cabezal ────────────────────────────────────────
            $this->maq('CAMION ISUZU NKR 2006', TipoMaquina::Otro, $viajes, marca: 'ISUZU', modelo: 'NKR', anio: 2006),
            $this->maq('CAMION ISUZU NPR 2009', TipoMaquina::Otro, $viajes, marca: 'ISUZU', modelo: 'NPR', anio: 2009),
            $this->maq('CAMION ISUZU NPS 2007', TipoMaquina::Otro, $viajes, marca: 'ISUZU', modelo: 'NPS', anio: 2007),
            $this->maq('CAMION HINO 2007', TipoMaquina::Otro, $viajes, marca: 'HINO', anio: 2007),
            $this->maq('CAMION HINO 300 2024', TipoMaquina::Otro, $viajes, marca: 'HINO', modelo: '300', anio: 2024),
            $this->maq('CAMION GMC 1987', TipoMaquina::Otro, $viajes, marca: 'GMC', anio: 1987),
            $this->maq('CAMION KENWORTH 2008', TipoMaquina::Otro, $viajes, marca: 'KENWORTH', anio: 2008),
            $this->maq('CAMION PLATAFORMA MACK', TipoMaquina::Otro, $viajes, marca: 'MACK'),
            $this->maq('CAMION FREIGHTLINER FL70 CON GRUA', TipoMaquina::Grua, $horas, marca: 'FREIGHTLINER', modelo: 'FL70'),
            $this->maq('CAMION', TipoMaquina::Otro, $viajes, nota: 'PENDIENTE IDENTIFICAR: el Excel lo lista sin marca ni año.'),
            $this->maq('CABEZAL VOLVO 2007', TipoMaquina::Otro, $viajes, marca: 'VOLVO', anio: 2007),

            // ── Pickups y vehículos livianos (por kilometraje) ────────────
            $this->maq('HILUX GRIS 2019', TipoMaquina::Otro, $km, marca: 'TOYOTA', modelo: 'HILUX', anio: 2019),
            $this->maq('HILUX DOBLE CABINA 2018', TipoMaquina::Otro, $km, marca: 'TOYOTA', modelo: 'HILUX', anio: 2018),
            $this->maq('HILUX DOBLE CABINA 2022', TipoMaquina::Otro, $km, marca: 'TOYOTA', modelo: 'HILUX', anio: 2022),
            $this->maq('HILUX DOBLE CABINA 2023', TipoMaquina::Otro, $km, marca: 'TOYOTA', modelo: 'HILUX', anio: 2023),
            $this->maq('TOYOTA SR5 1990', TipoMaquina::Otro, $km, marca: 'TOYOTA', modelo: 'SR5', anio: 1990),
            $this->maq('TOYOTA LUXE', TipoMaquina::Otro, $km, marca: 'TOYOTA', modelo: 'LUXE'),
            $this->maq('SIENNA', TipoMaquina::Otro, $km, marca: 'TOYOTA', modelo: 'SIENNA'),
            $this->maq('MITSUBISHI 2019', TipoMaquina::Otro, $km, marca: 'MITSUBISHI', anio: 2019),
            $this->maq('SPORTERO 2020', TipoMaquina::Otro, $km, marca: 'MITSUBISHI', modelo: 'SPORTERO', anio: 2020),
            $this->maq('NISSAN DOBLE CABINA 1998', TipoMaquina::Otro, $km, marca: 'NISSAN', anio: 1998),
            $this->maq('NISSAN FRONTIER', TipoMaquina::Otro, $km, marca: 'NISSAN', modelo: 'FRONTIER'),
            $this->maq('FORD RANGER 2022', TipoMaquina::Otro, $km, marca: 'FORD', modelo: 'RANGER', anio: 2022),
            $this->maq('MOTACARGA', TipoMaquina::Otro, $km),

            // ── Remolques y plataformas (sin motor: se cobran por flete) ──
            $this->maq('LOW BOY', TipoMaquina::Otro, $flete, nota: 'Remolque sin motor: no lleva horómetro ni combustible propio.'),
            $this->maq('REMOLQUE PARA MAQUINARIA', TipoMaquina::Otro, $flete, nota: 'Remolque sin motor: no lleva horómetro ni combustible propio.'),
            $this->maq('REMOLQUE STOUGHTON 2001', TipoMaquina::Otro, $flete, marca: 'STOUGHTON', anio: 2001, nota: 'Remolque sin motor: no lleva horómetro ni combustible propio.'),
            $this->maq('PLATAFORMA STOUGHTON 2001', TipoMaquina::Otro, $flete, marca: 'STOUGHTON', anio: 2001, nota: 'Remolque sin motor: no lleva horómetro ni combustible propio.'),

            // ── Implementos de retroexcavadora ────────────────────────────
            // Van como máquina para poder llevarles horómetro y reparaciones;
            // en la calle trabajan montados sobre una retro.
            $this->maq('MARTILLO HIDRAULICO DAEMO', TipoMaquina::Otro, $horas, marca: 'DAEMO', nota: 'Implemento de retroexcavadora: trabaja montado sobre una retro.'),
            $this->maq('MARTILLO HIDRAULICO ATLAS', TipoMaquina::Otro, $horas, marca: 'ATLAS', nota: 'Implemento de retroexcavadora: trabaja montado sobre una retro.'),

            // ── Equipo de campamento ──────────────────────────────────────
            $this->maq('CONTENEDOR MOVIL (1)', TipoMaquina::Otro, $flete, nota: 'PENDIENTE IDENTIFICAR: el Excel lista tres contenedores idénticos.'),
            $this->maq('CONTENEDOR MOVIL (2)', TipoMaquina::Otro, $flete, nota: 'PENDIENTE IDENTIFICAR: el Excel lista tres contenedores idénticos.'),
            $this->maq('CONTENEDOR MOVIL (3)', TipoMaquina::Otro, $flete, nota: 'PENDIENTE IDENTIFICAR: el Excel lista tres contenedores idénticos.'),
            $this->maq('SISTEMA DE CISTERNA (1)', TipoMaquina::Otro, $horas, nota: 'PENDIENTE IDENTIFICAR: el Excel lista dos sistemas idénticos.'),
            $this->maq('SISTEMA DE CISTERNA (2)', TipoMaquina::Otro, $horas, nota: 'PENDIENTE IDENTIFICAR: el Excel lista dos sistemas idénticos.'),
            $this->maq('HERRAMIENTA MENOR', TipoMaquina::Otro, $horas, nota: 'Renglón CONTABLE agregado del Excel, no un activo individual. Conviene desglosarlo o sacarlo del módulo.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function maq(
        string $nombre,
        TipoMaquina $tipo,
        string $modalidad,
        ?string $marca = null,
        ?string $modelo = null,
        ?int $anio = null,
        ?string $nota = null,
    ): array {
        return [
            'nombre'            => $nombre,
            'tipo'              => $tipo->value,
            'modalidad_trabajo' => $modalidad,
            'marca'             => $marca,
            'modelo'            => $modelo,
            'anio'              => $anio,
            'notas'             => $nota ?? 'Cargada del Excel de activos fijos (2026-09-04). Faltan placa, serie, horómetro y tarifas.',
        ];
    }
}
