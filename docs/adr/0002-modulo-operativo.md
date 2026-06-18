# ADR-0002 — Módulo operativo: inventario, maquinaria y finanzas

**Estado:** Aceptado
**Fecha:** 2026-06-18
**Decididores:** Mauricio Cruz (arquitecto técnico Grupo Olympo)
**Relacionado:** `docs/arquitectura/sistema-completo.md`

## Contexto

El MVP cubre la parte comercial (catálogos, fichas APU, presupuestos). El sistema completo
de la constructora requiere el módulo operativo: bodega/inventario, control de maquinaria
por unidad, compras a proveedores y finanzas (cuentas por cobrar/pagar). Antes de escribir
migraciones se cerraron cuatro decisiones de modelado que afectan toda la arquitectura.

## Decisiones

### 1. Stock multi-ubicación (bodega física + obra)

El stock se lleva **por ubicación**, donde una ubicación puede ser la bodega física **o**
un proyecto. Cuando llegan 100 bolsas a una obra que ya tenía 30, la existencia de esa
obra queda en 130.

- **Razón:** el dueño quiere control físico del material en cada obra día a día, no solo
  en bodega central. Cada obra es una mini-bodega.
- **Consecuencia:** existe un movimiento `consumo_obra` (gasto real en sitio) distinto del
  `traslado` (mover entre ubicaciones). Sin el consumo, el stock en obra se infla.
- **Implementación:** tabla `existencias` con `bodega_id` nullable y `proyecto_id`
  nullable, con CHECK que exige exactamente uno de los dos. Esto preserva FKs reales +
  CHECK constraints (estilo del codebase), evitando una relación polimórfica sin
  integridad referencial.

### 2. Imputación de costo al despachar (no al consumir)

El costo golpea el presupuesto del proyecto cuando el material **sale de la bodega central
hacia la obra**, no cuando se consume físicamente.

- **Razón:** en ese momento la empresa ya gastó en ese proyecto. El stock en obra es
  control físico de lo no usado, no un pasivo pendiente.
- **Consecuencia:** el reporte de costo real del proyecto suma los despachos, no los
  consumos. El consumo en obra es trazabilidad operativa, no contable.

### 3. Costeo: promedio ponderado móvil (moving weighted average)

El costo unitario se recalcula **solo cuando entra mercadería**; las salidas usan el
promedio vigente sin alterarlo.

- **Ejemplo validado:** compro 10 @ L.10 (prom 10) → compro 10 @ L.15 (20 u, valor 250,
  prom 12.50) → uso 10 (quedan 10 @ 12.50, valor 125, el promedio NO cambia) → compro
  10 @ L.17 (20 u, valor 295, prom 14.75).
- **Razón:** refleja "lo que hay" según las compras vivas, no el histórico completo.
  Estándar para inventario de construcción.
- **Implementación:** `existencia.cantidad` + `existencia.valor_total`. El promedio se
  deriva dividiendo (`valor_total / cantidad`); nunca se almacena el promedio directo,
  así no se desincroniza. Matemática con bcmath (scale interno), redondeo half-up al
  exponer (mismo patrón que el calculador de fichas).
- **Descartadas:** PEPS (más complejo, innecesario aquí) y última-compra (distorsiona
  el costo real cuando hay stock viejo).

### 4. Maquinaria propia se cobra a sí misma por horas reales

La máquina que sale a una obra propia imputa costo al proyecto = `horas_reales ×
costo_hora` (el costo_hora incluye combustible al precio del lunes + tarifa interna).

- **Horas extra:** si horas_reales > horas_programadas, la diferencia exige **motivo
  obligatorio**. No se cierra la asignación con horas extra sin explicación.
- **Sustitución por avería:** una asignación puede reasignarse a otra máquina con motivo
  ("avería"); la averiada pasa a estado `mantenimiento`. El intercambio queda registrado.
- **Razón:** el dueño necesita saber el costo real de maquinaria por obra y por qué se
  excedió lo programado, con trazabilidad de quién y por qué.

### 5. Cuentas por cobrar/pagar: saldos + abonos (sin partida doble)

Cada cliente/proveedor lleva un saldo; los abonos lo bajan; los reportes salen de ahí.

- **Razón:** una constructora de este tamaño no necesita contabilidad de partida doble.
  Saldos + abonos cubre el 100% de la operación real.
- **Reversibilidad:** si algún día se necesita contabilidad formal, se monta encima sin
  rehacer este módulo (los abonos ya son el libro de movimientos).

## Consecuencias

**Positivas:** modelo simple y fiel a la operación real; integridad referencial fuerte
(FKs + CHECK); trazabilidad completa vía `activitylog` + bitácoras de movimientos;
reversible hacia contabilidad formal si hiciera falta.

**Negativas:** la regla "exactamente una ubicación" en `existencias` y `movimientos`
requiere CHECK constraints y validación en el Service (no se puede expresar con una sola
FK). Aceptado: es el precio de mantener integridad referencial real en vez de polimorfismo
sin FK.

**Mitigaciones:** Service de inventario centraliza toda escritura de existencias/movimientos
(ninguna escritura directa desde Resources); transacciones con `lockForUpdate` sobre la
existencia afectada en cada movimiento (evita race conditions en stock y en el WAC).
