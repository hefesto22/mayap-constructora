<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * `View:CapturaDelDia` → `RegistrarJornada:Maquina` (decisión Mauricio
 * 2026-08-07).
 *
 * El permiso nació con el nombre de la pantalla "Captura del día", que se
 * retiró el 2026-07-20 cuando el calendario pasó a ser la única puerta de
 * captura. Al borrarse la página, Shield dejó de generar su permiso y este
 * quedó huérfano: no aparecía ni en la pestaña "Páginas" (la página ya no
 * existe) ni en "Personalizados" (no estaba en App\Support\Permisos), así
 * que era el único permiso del sistema que solo se podía mover tocando el
 * seeder. Ahora vive en Permisos y se administra desde el panel.
 *
 * Renombra la FILA, no la borra: las asignaciones de roles y de usuarios
 * apuntan por `permission_id`, así que quien lo tenía lo conserva.
 */
return new class extends Migration
{
    private const string VIEJO = 'View:CapturaDelDia';

    private const string NUEVO = 'RegistrarJornada:Maquina';

    public function up(): void
    {
        $this->renombrar(self::VIEJO, self::NUEVO);
    }

    public function down(): void
    {
        $this->renombrar(self::NUEVO, self::VIEJO);
    }

    /**
     * Renombra conservando las asignaciones. Si el destino YA existe
     * (alguien sembró antes de migrar), traslada las asignaciones del
     * origen al destino y borra el origen — nunca deja a un rol sin el
     * permiso que tenía.
     */
    private function renombrar(string $origen, string $destino): void
    {
        DB::transaction(function () use ($origen, $destino): void {
            $viejo = DB::table('permissions')
                ->where('name', $origen)
                ->where('guard_name', 'web')
                ->first();

            if ($viejo === null) {
                return;
            }

            $nuevo = DB::table('permissions')
                ->where('name', $destino)
                ->where('guard_name', 'web')
                ->first();

            if ($nuevo === null) {
                DB::table('permissions')
                    ->where('id', $viejo->id)
                    ->update(['name' => $destino, 'updated_at' => now()]);

                return;
            }

            foreach (['role_has_permissions', 'model_has_permissions'] as $tabla) {
                DB::table($tabla)
                    ->where('permission_id', $viejo->id)
                    ->get()
                    ->each(function (object $fila) use ($tabla, $nuevo): void {
                        $datos = (array) $fila;
                        $datos['permission_id'] = $nuevo->id;

                        DB::table($tabla)->insertOrIgnore($datos);
                    });

                DB::table($tabla)->where('permission_id', $viejo->id)->delete();
            }

            DB::table('permissions')->where('id', $viejo->id)->delete();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
