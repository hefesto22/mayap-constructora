<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;

/**
 * Nombres de los roles operativos — ÚNICA fuente (no repetir strings en
 * Resources, seeders ni tests; ver §8 de instrucciones).
 *
 * La matriz operativa (docs/arquitectura/sistema-completo.md):
 *  - bodeguero:      despacha requisiciones y verifica lo que entra a bodega.
 *  - recepcion:      realiza/registra las compras (requisiciones sin stock).
 *  - maquinaria:     agenda del equipo, asignaciones, partes, mantenimiento.
 *  - encargado_obra: pide material para SUS obras y confirma lo que llega.
 *  - gerencia:       ve todo (sin ser super_admin).
 */
final class Roles
{
    public const string BODEGUERO = 'bodeguero';

    public const string RECEPCION = 'recepcion';

    public const string MAQUINARIA = 'maquinaria';

    public const string ENCARGADO_OBRA = 'encargado_obra';

    public const string GERENCIA = 'gerencia';

    /**
     * Roles operativos con acceso al panel — ÚNICA lista (la consume
     * User::canAccessPanel). Agregar un rol nuevo aquí = puede entrar.
     *
     * @var list<string>
     */
    public const array OPERATIVOS = [
        self::BODEGUERO,
        self::RECEPCION,
        self::MAQUINARIA,
        self::ENCARGADO_OBRA,
        self::GERENCIA,
    ];

    /**
     * ¿El usuario opera bodega (o tiene visión total)?
     */
    public static function despachaBodega(?User $user): bool
    {
        return $user !== null && $user->hasAnyRole([self::BODEGUERO, self::GERENCIA])
            || self::esSuper($user);
    }

    /**
     * ¿El usuario realiza compras?
     */
    public static function compra(?User $user): bool
    {
        return $user !== null && $user->hasAnyRole([self::RECEPCION, self::GERENCIA])
            || self::esSuper($user);
    }

    /**
     * ¿El usuario es SOLO encargado de obra (alcance limitado a sus obras)?
     * Si además tiene un rol de visión amplia, el scoping no aplica.
     */
    public static function soloEncargado(?User $user): bool
    {
        return $user !== null
            && $user->hasRole(self::ENCARGADO_OBRA)
            && ! $user->hasAnyRole([self::BODEGUERO, self::RECEPCION, self::MAQUINARIA, self::GERENCIA])
            && ! self::esSuper($user);
    }

    private static function esSuper(?User $user): bool
    {
        return $user !== null
            && $user->hasRole(Utils::getSuperAdminName());
    }
}
