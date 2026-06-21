# Visibilidad por bodega (Fase 2)

Controla qué inventario ve cada usuario según las bodegas que tiene asignadas.

## Modelo

- **Asignación:** pivot `bodega_user` (muchos-a-muchos). Un usuario puede cubrir varias bodegas. UI en *Administración → Usuarios → Bodegas asignadas*.
- **Bypass:** permiso Spatie/Shield `ver_todas_las_bodegas`. `super_admin` lo cumple automáticamente (Gate::before de Shield); se le puede asignar a roles administrativos (gerencia) para que vean todo. Helper: `User::puedeVerTodasLasBodegas()`.
- **Sin el permiso:** el usuario solo ve el inventario de sus bodegas asignadas (+ el stock en obra, ver abajo).

## Alcance

| Entidad | Regla |
|---|---|
| `Existencias` | Stock de las bodegas asignadas **+ todo el stock en obra** (proyecto). Se oculta el stock de otras bodegas. |
| `Movimientos` | Visibles si tocan (origen o destino) una bodega asignada, o son de obra. |
| `Compras` | Solo las de las bodegas asignadas. |
| `Requisiciones` | La lista NO se filtra (son por obra, no por bodega). Solo se limita el **selector de bodega de despacho** a las del usuario. |
| Selectores (registrar entrada, compra, despacho) | Solo muestran las bodegas del usuario. |

## Mecanismo (decisión técnica)

La restricción se aplica con un **scope de consulta** (`scopeVisibleParaUsuario(User)`) usado en los `getEloquentQuery` de los Resources y en los selectores — **NO** como Global Scope. Razón: un Global Scope sobre `existencias` rompería operaciones legítimas cross-bodega del motor de inventario (ej. un traslado bodega A→B necesita leer/crear la existencia de B). Así el motor de costeo y los servicios quedan intactos; la restricción vive en la capa de lectura del usuario.

Defense in depth: además del selector limitado, la acción "Registrar entrada" revalida que el usuario pueda escribir en la bodega elegida.

## Usuario sin bodegas asignadas

No ve stock de ninguna bodega (solo el de obra). Es el comportamiento seguro por defecto: para darle acceso, asignale bodegas o el permiso `ver_todas_las_bodegas`.

## Pendiente (futuro)

Visibilidad por **proyecto/obra** (que un usuario de obra solo vea su proyecto) es una capa aparte, no incluida en Fase 2.
