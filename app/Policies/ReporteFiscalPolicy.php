<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ReporteFiscal;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Los reportes fiscales heredan los permisos de Compra (cero permisos
 * Shield nuevos, misma decisión que renta y mantenimiento) — pero con
 * el mismo recorte que las columnas de dinero: el encargado de obra,
 * aunque vea compras, NO ve totales ni facturas.
 */
class ReporteFiscalPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Compra')
            && ! ($authUser instanceof User && Roles::soloEncargado($authUser));
    }

    public function view(AuthUser $authUser, ReporteFiscal $reporte): bool
    {
        return $this->viewAny($authUser);
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Compra')
            && ! ($authUser instanceof User && Roles::soloEncargado($authUser));
    }

    public function update(AuthUser $authUser, ReporteFiscal $reporte): bool
    {
        return false; // El reporte no se edita: se regenera.
    }

    public function delete(AuthUser $authUser, ReporteFiscal $reporte): bool
    {
        return false; // Archivo de control permanente.
    }
}
