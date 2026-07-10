<?php

declare(strict_types=1);

use App\Support\Permisos;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Desglosa el permiso `VerTodosLosEstados:Proyecto` (todo-o-nada) en 7
 * permisos POR ESTADO (VerBorradores/VerEnviadas/.../VerCanceladas
 * :Proyecto) para control fino desde la pestaña Personalizados de Roles.
 *
 * Data migration: todo rol que tenía el permiso viejo recibe los 7 nuevos
 * (conserva exactamente la misma visibilidad); el viejo se elimina.
 */
return new class extends Migration
{
    public function up(): void
    {
        $viejo = DB::table('permissions')
            ->where('name', 'VerTodosLosEstados:Proyecto')
            ->where('guard_name', 'web')
            ->first();

        // Crear los 7 nuevos (idempotente).
        foreach (Permisos::VER_ESTADO_PROYECTO as $nombre) {
            DB::table('permissions')->insertOrIgnore([
                'name'       => $nombre,
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($viejo !== null) {
            $nuevosIds = DB::table('permissions')
                ->whereIn('name', array_values(Permisos::VER_ESTADO_PROYECTO))
                ->where('guard_name', 'web')
                ->pluck('id');

            // Roles que tenían el permiso viejo → reciben los 7 nuevos.
            $rolesIds = DB::table('role_has_permissions')
                ->where('permission_id', $viejo->id)
                ->pluck('role_id');

            foreach ($rolesIds as $roleId) {
                foreach ($nuevosIds as $permisoId) {
                    DB::table('role_has_permissions')->insertOrIgnore([
                        'role_id'       => $roleId,
                        'permission_id' => $permisoId,
                    ]);
                }
            }

            // El viejo desaparece (el FK cascade limpia sus asignaciones).
            DB::table('permissions')->where('id', $viejo->id)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Restaura el permiso todo-o-nada para los roles que tienen los 7.
        $viejoId = DB::table('permissions')->insertGetId([
            'name'       => 'VerTodosLosEstados:Proyecto',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $nuevosIds = DB::table('permissions')
            ->whereIn('name', array_values(Permisos::VER_ESTADO_PROYECTO))
            ->where('guard_name', 'web')
            ->pluck('id');

        $rolesConTodos = DB::table('role_has_permissions')
            ->whereIn('permission_id', $nuevosIds)
            ->select('role_id')
            ->groupBy('role_id')
            ->havingRaw('COUNT(DISTINCT permission_id) = ?', [$nuevosIds->count()])
            ->pluck('role_id');

        foreach ($rolesConTodos as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore([
                'role_id'       => $roleId,
                'permission_id' => $viejoId,
            ]);
        }

        DB::table('permissions')->whereIn('id', $nuevosIds)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
