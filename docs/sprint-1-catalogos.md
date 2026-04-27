# Sprint 1 — Catálogos base

Módulo que sienta la base de datos del sistema: unidades de medida globales, zonas operativas, y la base de precios por zona. Es el cimiento sobre el que Sprint 2 (Fichas APU) y Sprint 3 (Presupuestos + PDF) se calculan.

## Modelo de dominio

```
unidades_medida (global)
  ├── codigo (M2, BOLSA, JDR…)
  ├── nombre, simbolo
  └── activo

zonas (1 por área operativa)
  ├── codigo (SRC, TGU, SPS…)
  ├── nombre, descripcion
  └── activa

items (base de precios POR zona)
  ├── zona_id ────► zonas
  ├── unidad_medida_id ────► unidades_medida
  ├── categoria ENUM: materiales | mano_obra | herramienta_equipo | indirectos
  ├── codigo (auto: {ZONA}-{CAT}-#####)
  ├── nombre, descripcion
  ├── precio_unitario (decimal 12,2)
  ├── observaciones_precio
  ├── precio_actualizado_at (auto)
  └── activo
```

## Convenciones del módulo

### Auto-código de items

Patrón: `{CODIGO_ZONA}-{PREFIJO_CATEGORIA}-{NUMERO_5_DIGITOS}`

Prefijos:
- `MAT` Materiales
- `MO` Mano de obra
- `HE` Herramienta y equipo
- `IND` Indirectos

Ejemplos: `SRC-MAT-00001`, `TGU-MO-00042`, `SPS-HE-00003`.

Generación en `Item::creating` con `lockForUpdate` dentro de transacción (concurrencia segura). Un usuario no debe escribir el código manualmente — el form lo oculta en CREATE y lo muestra readonly en EDIT.

### Auto-uppercase de texto

Triple defensa para mantener consistencia del dominio constructor:

1. **CSS visual** en input: `extraInputAttributes(['style' => 'text-transform: uppercase'])`.
2. **Backend al enviar**: macro `->mayusculas()` en TextInput/Textarea (registrado en `AppServiceProvider`).
3. **Mutator del modelo**: trait `HasUppercaseAttributes` aplicado a `Item`, `Zona`, `UnidadMedida`.

Aplica a: nombre, descripción, observaciones, código.
**NO aplica a**: símbolos físicos (m², kg), nombres de personas, emails, passwords.

### Layout estándar de forms

Tabs de Filament v4 con tres pestañas estándar:

1. **Identificación** — qué es y dónde aplica.
2. **{Tema principal}** — datos centrales (precio y unidad para items).
3. **Estado** — toggle activo + sección "Información del registro" (creado, último cambio, count de actividades).

Ver `feedback_patron_diseno_filament.md` en memoria para los detalles completos.

## Features clave

### Clonado de items entre zonas

Service: `App\Services\Catalogos\ClonarItemsEntreZonas`

Caso de uso: cuando se crea una zona nueva, hereda items de una zona existente como punto de partida. Los items clonados son **independientes** — editar destino no afecta origen. Códigos se regeneran con prefijo de zona destino. Detección de duplicados por `nombre + categoría` (configurable).

Disponible desde:
- **Form de creación de Zona** (Tab "Inicialización"): `Heredar base de precios desde [zona]`.
- **Header action de Edit Zona**: `Clonar items desde otra zona`.

Auditoría: cada operación exitosa (clonados > 0) registra entrada semántica en `activitylog` (log_name: `clonado_items`) con properties `{origen, destino, clonados, omitidos, ids_clonados}`.

### Edición inline de precio

En el listado de "Base de precios", la columna `Precio (L.)` es un `TextInputColumn`. El usuario edita directamente sin abrir la página de edición — Enter o blur guarda automáticamente. El `ItemObserver` mantiene `precio_actualizado_at` sincronizado.

Para editar otros campos (nombre, descripción, observaciones, etc.) sí se abre la página de edición completa.

### Indicador de precios viejos

Filtro toggle "Precios viejos (>90 días)" en el listado. La columna `Precio actualizado` se colorea en rojo cuando el precio tiene más de 90 días sin cambiar.

## Comandos útiles del módulo

```bash
# Cargar 21 items demo realistas (cementos, varillas, jornadas, etc.)
php artisan db:seed --class=Database\\Seeders\\ItemDemoSeeder

# Re-ejecutar seeders idempotentes (sin perder datos)
php artisan db:seed --class=Database\\Seeders\\CatalogosSeeder

# Tests específicos del módulo
php artisan test --filter=Catalogos
php artisan test tests/Feature/Filament/CatalogosResourcesTest.php
```

## Tests

| Suite | Cobertura |
|---|---|
| `tests/Unit/Enums/CategoriaItemTest.php` | Enum `CategoriaItem` (label, color, icono, options) |
| `tests/Feature/Catalogos/UnidadMedidaTest.php` | Seeder, unicidad, scope, FK restrict |
| `tests/Feature/Catalogos/ZonaTest.php` | Seeder, unicidad, scope, FK restrict |
| `tests/Feature/Catalogos/ItemTest.php` | Unicidad por zona, CHECK constraints, observer, scopes, casts |
| `tests/Feature/Catalogos/AutoCodigoItemTest.php` | Auto-código secuencial, no choca entre zonas, manual respetado |
| `tests/Feature/Catalogos/UppercaseMutatorsTest.php` | Mutators uppercase en los 3 modelos, manejo de null |
| `tests/Feature/Catalogos/ClonarItemsEntreZonasTest.php` | Clonado completo, skip duplicados, independencia, auditoría |
| `tests/Feature/Filament/CatalogosResourcesTest.php` | Render de listados, filtros, edición inline, action de clonado |

## Estructura de archivos del módulo

```
app/
├── Enums/
│   └── CategoriaItem.php
├── Filament/
│   ├── Concerns/
│   │   └── NotificaResultadoClonado.php
│   └── Resources/
│       ├── Items/
│       │   ├── ItemResource.php
│       │   ├── Pages/{List,Create,Edit}Item.php
│       │   ├── Schemas/ItemForm.php
│       │   └── Tables/ItemsTable.php
│       ├── UnidadesMedida/
│       │   ├── UnidadMedidaResource.php
│       │   ├── Pages/{List,Create,Edit}UnidadMedida.php
│       │   ├── Schemas/UnidadMedidaForm.php
│       │   └── Tables/UnidadesMedidaTable.php
│       └── Zonas/
│           ├── ZonaResource.php
│           ├── Pages/{List,Create,Edit}Zona.php
│           ├── Schemas/ZonaForm.php
│           └── Tables/ZonasTable.php
├── Models/
│   ├── Concerns/
│   │   └── HasUppercaseAttributes.php
│   ├── Item.php
│   ├── UnidadMedida.php
│   └── Zona.php
├── Observers/
│   └── ItemObserver.php
├── Policies/
│   ├── ItemPolicy.php
│   ├── UnidadMedidaPolicy.php
│   └── ZonaPolicy.php
└── Services/
    └── Catalogos/
        └── ClonarItemsEntreZonas.php

database/
├── factories/
│   ├── ItemFactory.php
│   ├── UnidadMedidaFactory.php
│   └── ZonaFactory.php
├── migrations/
│   ├── 2026_04_27_100000_create_unidades_medida_table.php
│   ├── 2026_04_27_100100_create_zonas_table.php
│   └── 2026_04_27_100200_create_items_table.php
└── seeders/
    ├── CatalogosSeeder.php
    ├── ItemDemoSeeder.php
    ├── UnidadMedidaSeeder.php
    └── ZonaSeeder.php
```

## Deuda técnica anotada (no bloquea Sprint 2)

- **Reemplazo de phpcpd**: paquete abandonado, no compatible con PHP 8.4. Buscar alternativa moderna cuando se monte CI real (Psalm con plugin de duplicación, regla custom de PHPStan, fork mantenido).
- **Auditoría histórica de precios**: los cambios individuales se registran via `activitylog` por modelo, pero no hay tabla dedicada de histórico de precios. Se decidió que el snapshot inmutable vive en presupuestos emitidos (Sprint 3).
- **5 dígitos en el código**: permite hasta 99,999 items por zona+categoría. Suficiente para esta década.
- **Performance del clonado masivo**: para >200 items por operación, los inserts se serializan en una transacción grande. Mover a Job si se llega a ese volumen.
- **Action "Deshacer último clonado"**: feature pendiente, los IDs ya se registran en `activitylog->properties->ids_clonados` para implementarlo cuando se necesite.
- **Permiso `Clone:Zona` separado**: actualmente cualquier usuario con `Update:Zona` puede clonar. Considerar separar si se llega a producción multi-rol.
- **Export/Import Excel de la base de precios**: pedirá Sprint 3 cuando se necesite para presupuestos.
