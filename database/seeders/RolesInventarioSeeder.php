<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\Permisos;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles de operación del inventario (Fase 2).
 *
 * Debe correr DESPUÉS de AdminUserSeeder (que ejecuta `shield:generate` y
 * crea los permisos por recurso). Asigna permisos buscándolos por sufijo de
 * recurso, así es robusto ante los afijos que genere Shield.
 *
 *  - BODEGUERO: el encargado de la bodega. Control TOTAL de lo que entra y
 *    sale de SU bodega (existencias, requisiciones, compras). El catálogo de
 *    materiales, bodegas y proveedores los ve en SOLO LECTURA (no los
 *    administra). El alcance por bodega lo da el scope de Fase 2.
 *  - GERENCIA: ve y administra TODO el inventario en TODAS las bodegas
 *    (permiso Permisos::VER_TODAS_LAS_BODEGAS), sin ser super_admin.
 */
class RolesInventarioSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $bodeguero = Role::firstOrCreate(['name' => 'bodeguero', 'guard_name' => 'web']);
        $gerencia = Role::firstOrCreate(['name' => 'gerencia', 'guard_name' => 'web']);

        // BODEGUERO: control total de su bodega + lectura de catálogos.
        $bodeguero->syncPermissions(array_merge(
            $this->permisosDe('Existencia'),
            $this->permisosDe('Compra'),
            $this->permisosDe('Requisicion'),
            $this->soloLectura('Material'),
            $this->soloLectura('Bodega'),
            $this->soloLectura('Proveedor'),
            $this->existentes(['View:MyProfilePage', 'page_MyProfilePage']),
        ));

        // GERENCIA: todo el inventario en todas las bodegas.
        $gerencia->syncPermissions(array_merge(
            $this->permisosDe('Existencia'),
            $this->permisosDe('Compra'),
            $this->permisosDe('Requisicion'),
            $this->permisosDe('Material'),
            $this->permisosDe('Bodega'),
            $this->permisosDe('Proveedor'),
            $this->permisosDe('CuentaPorPagar'),
            $this->existentes([Permisos::VER_TODAS_LAS_BODEGAS, 'View:MyProfilePage', 'page_MyProfilePage']),
            // Gerencia SÍ audita el registro de actividad.
            $this->existentes(['ViewAny:Activity', 'View:Activity']),
        ));

        // ── Roles operativos Fase G1 ───────────────────────────────────
        // RECEPCIÓN: realiza y registra las compras (las requisiciones sin
        // stock le llegan a ella). Ve requisiciones y catálogos en lectura.
        $recepcion = Role::firstOrCreate(['name' => 'recepcion', 'guard_name' => 'web']);
        $recepcion->syncPermissions(array_merge(
            $this->permisosDe('Compra'),
            $this->permisosDe('Proveedor'),
            $this->permisosDe('CuentaPorPagar'),
            $this->soloLectura('Requisicion'),
            $this->soloLectura('Material'),
            $this->soloLectura('Bodega'),
            $this->soloLectura('Existencia'),
            $this->existentes(['View:MyProfilePage', 'page_MyProfilePage']),
        ));

        // MAQUINARIA: administra el parque de máquinas, asignaciones,
        // partes, combustible y mantenimientos. Proyectos en lectura.
        $maquinaria = Role::firstOrCreate(['name' => 'maquinaria', 'guard_name' => 'web']);
        $maquinaria->syncPermissions(array_merge(
            $this->permisosDe('Maquina'),
            $this->permisosDe('AsignacionMaquina'),
            $this->permisosDe('ParteTrabajo'),
            $this->permisosDe('ConsumoCombustible'),
            $this->permisosDe('MantenimientoMaquina'),
            $this->soloLectura('Proyecto'),
            $this->existentes(['View:MyProfilePage', 'page_MyProfilePage']),
        ));

        // ENCARGADO DE OBRA: pide material para SUS obras y confirma lo que
        // llega. El alcance "solo mis obras" lo aplica el scoping de los
        // Resources, no los permisos. NO ve catálogos ni existencias — su
        // ventana al material es la propia requisición.
        $encargado = Role::firstOrCreate(['name' => 'encargado_obra', 'guard_name' => 'web']);
        $encargado->syncPermissions(array_merge(
            $this->existentes(['ViewAny:Requisicion', 'View:Requisicion', 'Create:Requisicion', 'Update:Requisicion']),
            $this->soloLectura('Proyecto'),
            $this->existentes(['View:MyProfilePage', 'page_MyProfilePage']),
        ));

        // ── Permisos PERSONALIZADOS (App\Support\Permisos = única fuente) ──
        // Se crean aquí (Shield no genera permisos custom) y se administran
        // desde la pantalla de Roles, pestaña "Personalizados": ahí se decide
        // qué rol puede pausar, finalizar, cancelar, registrar anticipos,
        // ajustar plazos o ver todo.
        foreach (array_keys(Permisos::PERSONALIZADOS) as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }

        $permisosEjecucion = Permisos::EJECUCION_PROYECTO;

        // Gerencia los recibe todos. El encargado de obra solo lo operativo
        // de campo: pausar (lluvia, falta de material) y reactivar — las
        // decisiones contractuales (finalizar/cancelar/anticipo/plazo)
        // quedan en gerencia. Ajustable desde la pantalla de Roles.
        $gerencia->givePermissionTo($permisosEjecucion);
        $encargado->givePermissionTo([Permisos::PAUSAR_PROYECTO, Permisos::REACTIVAR_PROYECTO]);

        // Visibilidad POR ESTADO en Proyectos: las obras vivas las ve todo
        // rol con acceso; cada estado comercial/histórico (Borrador,
        // Enviada, Aprobada, Vencida, Rechazada, Finalizada, Cancelada) se
        // otorga uno por uno desde Roles. Gerencia y maquinaria: todos.
        $verEstados = array_values(Permisos::VER_ESTADO_PROYECTO);

        $gerencia->givePermissionTo($verEstados);
        $maquinaria->givePermissionTo($verEstados);

        // Reportes PDF: solo gerencia por defecto (el de costos revela el
        // MARGEN de la empresa; el de composición, precios contractuales).
        $gerencia->givePermissionTo([
            Permisos::DESCARGAR_PDF_COSTOS_PROYECTO,
            Permisos::DESCARGAR_PDF_COMPOSICION_PROYECTO,
        ]);

        // Anular compras: SOLO gerencia (recepción no se auto-anula sus
        // errores sin supervisión — se pide a gerencia o se ajusta el rol).
        $gerencia->givePermissionTo(Permisos::ANULAR_COMPRA);

        // Verificar recepción (G2): quien RECIBE el material cuenta los
        // bultos — bodeguero (porción bodega), encargado (porción obra),
        // gerencia de respaldo. Recepción NO: quien compró no se auto-valida.
        $bodeguero->givePermissionTo(Permisos::VERIFICAR_RECEPCION_COMPRA);
        $encargado->givePermissionTo(Permisos::VERIFICAR_RECEPCION_COMPRA);
        $gerencia->givePermissionTo(Permisos::VERIFICAR_RECEPCION_COMPRA);

        // El encargado ve las compras (lectura) para poder verificar las
        // que traen material a SUS obras — el alcance lo da el scoping.
        $encargado->givePermissionTo($this->soloLectura('Compra'));

        // Imprevistos: comprar a obra material NO presupuestado lo autoriza
        // solo gerencia por defecto (recepción se apega al presupuesto).
        $gerencia->givePermissionTo(Permisos::COMPRAR_FUERA_DE_PRESUPUESTO);

        // Corregir un conteo YA CONFIRMADO mueve inventario (ajuste ±):
        // solo gerencia por defecto (ajustable desde Roles → Personalizados).
        $gerencia->givePermissionTo(Permisos::CORREGIR_RECEPCION_COMPRA);

        // El super_admin sincronizó sus permisos ANTES de que estos custom
        // existieran (shield:super-admin en AdminUserSeeder) — asignarle
        // TODOS los personalizados para que el orden de seeders no importe.
        Role::where('name', Utils::getSuperAdminName())
            ->first()
            ?->givePermissionTo(array_keys(Permisos::PERSONALIZADOS));

        $this->command?->info('✓ Roles: bodeguero, gerencia, recepcion, maquinaria, encargado_obra.');
    }

    /**
     * Todos los permisos generados por SHIELD para un recurso, cualquier
     * acción (`{Accion}:{Modelo}` en PascalCase). EXCLUYE los permisos
     * PERSONALIZADOS: también terminan en `:{Modelo}` (Anular:Compra,
     * VerificarRecepcion:Compra...) pero se asignan uno a uno de forma
     * explícita — si no, se cuelan de contrabando a roles que no deben
     * tenerlos (bug real: recepción ganó Anular:Compra por el LIKE).
     *
     * @return array<int, string>
     */
    private function permisosDe(string $modelo): array
    {
        return Permission::query()
            ->where('name', 'like', '%:'.$modelo)
            ->whereNotIn('name', array_keys(Permisos::PERSONALIZADOS))
            ->pluck('name')
            ->all();
    }

    /**
     * Solo permisos de lectura (ver listado / ver registro) de un recurso.
     *
     * @return array<int, string>
     */
    private function soloLectura(string $modelo): array
    {
        return Permission::query()
            ->whereIn('name', ["ViewAny:{$modelo}", "View:{$modelo}"])
            ->pluck('name')
            ->all();
    }

    /**
     * Filtra una lista a los permisos que realmente existen (evita error si
     * Shield aún no generó alguno).
     *
     * @param array<int, string> $nombres
     *
     * @return array<int, string>
     */
    private function existentes(array $nombres): array
    {
        return Permission::query()
            ->whereIn('name', $nombres)
            ->pluck('name')
            ->all();
    }
}
