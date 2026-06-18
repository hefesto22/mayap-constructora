# Arquitectura del Sistema Completo — Constructora MAYAP

**Estado:** Diseño aprobado en conversación (2026-06-18). Implementación por fases.
**Alcance:** Este documento describe el sistema operativo COMPLETO de la constructora,
más allá del MVP comercial (catálogos + fichas APU + presupuestos). Es el mapa que
ordena los módulos post-MVP y garantiza que todos cuelguen de un mismo kernel.

> Para las decisiones puntuales (costeo, multi-ubicación, maquinaria interna, CxC/CxP)
> ver `docs/adr/0002-modulo-operativo.md`.

---

## 1. El marco mental: matriz verticales × áreas

El error a evitar es confundir **líneas de negocio** con **áreas operativas**. No son la
misma lista, aunque "Maquinaria" aparezca en ambas.

**Verticales de negocio** (de dónde entra/sale el dinero):

1. **Proyectos** — obras civiles (alcantarillado, tubería, drenaje, lotificaciones).
2. **Maquinaria** — alquiler de equipo (retros, volquetes) a terceros y a obras propias.
3. **Insumos** — venta de materiales (arena, grava, cemento) a clientes.

**Áreas operativas** (quién opera y controla, transversales a las verticales):

1. **Administración** — presupuesto, autoriza compras, cuentas por cobrar/pagar, planilla.
2. **Bodega** — stock, entradas/salidas, requisiciones, despachos.
3. **Maquinaria** — control por unidad: horómetro, mantenimiento, asignación a obra.

La matriz: las 3 áreas operan sobre las 3 verticales.

|                    | Proyectos                          | Maquinaria (alquiler)        | Insumos (venta)            |
|--------------------|------------------------------------|------------------------------|----------------------------|
| **Administración** | presupuesto, autoriza, CxC/CxP, planilla | cobra alquiler, paga repuestos | factura ventas, paga proveedores |
| **Bodega**         | despacha material a obra           | —                            | stock, entrada/salida, despacho |
| **Maquinaria**     | asigna retro+operador a obra       | horómetro, mantenimiento     | —                          |

**Implicación arquitectónica:** el stock, los clientes, los proveedores y las cuentas
por pagar/cobrar son **compartidos** entre verticales. NO se construyen tres sistemas:
se construye un kernel compartido con tres modos de uso.

---

## 2. El kernel que ya existe (MVP) y de qué se cuelga lo nuevo

El MVP ya construyó la base sobre la que todo lo demás se monta. Lo nuevo **extiende**,
no rediseña:

| Tabla existente | Rol en el sistema completo |
|---|---|
| `items` (base de precios por zona) | Mismo catálogo es **stock en bodega** y lo que entra por **compra a proveedor**. Un solo maestro, tres usos. |
| `fichas` / `ficha_lineas` (APU) | Definen el costo planificado de cada actividad de obra. |
| `proyectos` / `proyecto_renglones` | El presupuesto aprobado es la línea base. Todo costo real se imputa aquí. |
| `clientes` | Compartido. Se le agrega condición crédito/contado y saldo (CxC). |
| `zonas`, `unidades_medida` | Catálogos transversales sin cambios. |

Lo que falta crear: `proveedores`, `bodegas`, `existencias`, `movimientos_inventario`,
`requisiciones`, `compras`, `maquinas`, `asignaciones_maquina`, `planilla`,
`cuentas` (por cobrar/pagar) + `abonos`.

---

## 3. La columna vertebral: requisiciones con trazabilidad

Es el flujo que **integra las tres áreas** y el corazón del sistema. Técnicamente es una
**máquina de estados con responsable registrado en cada transición**. La regla de oro
—"si se sabe de dónde salió el error, la mitad del problema está resuelto"— se logra
guardando quién ejecutó cada paso y comparando cantidad-pedida vs cantidad-confirmada
en cada eslabón.

### 3.1 Máquina de estados

```
                          ┌─────────────────────────────────────────────┐
                          │                                             │
  [obra]        [admin]   │  hay stock        [bodega]      [transporte]│   [obra]
SOLICITADA ──> AUTORIZADA ┤────────────> DESPACHADA ──> EN_TRANSITO ──> RECIBIDA ──> CERRADA
     │             │      │                                                 │
     │             │      │  NO hay stock                                   │ cantidad
     │         (rechaza)  └──> REQUISICION_COMPRA ──[admin autoriza]──>     │ recibida ≠
     │             │            COMPRA_AUTORIZADA ──> RECIBIDA_BODEGA ──┐   │ pedida
     ▼             ▼                                  (entra a stock)   │   ▼
  RECHAZADA    RECHAZADA                                               └─> DISCREPANCIA
```

### 3.2 Reglas

- Cada transición registra: **quién** (user), **cuándo**, **cantidad** confirmada en ese
  eslabón, y **nota** opcional (ej: "tengo 500, las otras llegan el viernes").
- Nadie escribe mensajes manuales: cada actor **aprueba o rechaza** en su bandeja y el
  sistema notifica al siguiente (Filament Notifications).
- Si bodega no tiene stock, la requisición **genera automáticamente** una requisición de
  compra hacia Administración (alerta a quien compra).
- `DISCREPANCIA` cuando cantidad recibida ≠ cantidad despachada: el sistema señala
  exactamente en qué transición y bajo qué responsable se perdió la diferencia.
- Toda la traza vive en `requisicion_movimientos` + `activitylog`.

### 3.3 Modelo de datos

- `requisiciones` — id, codigo (REQ-{AÑO}-#####), proyecto_id, estado, solicitante_id,
  fecha_necesaria, notas, timestamps, softDeletes.
- `requisicion_lineas` — requisicion_id, item_id, cantidad_solicitada, cantidad_autorizada,
  cantidad_despachada, cantidad_recibida.
- `requisicion_movimientos` — requisicion_id, estado_origen, estado_destino, user_id,
  cantidad, nota, created_at. (Bitácora inmutable de cada transición.)

---

## 4. Inventario multi-ubicación + costeo

Ver detalle y justificación en ADR-0002. Resumen:

- **Stock por ubicación**: la bodega física Y cada proyecto son ubicaciones de stock.
  Cuando llegan 100 bolsas a una obra que ya tenía 30, la existencia de esa obra es 130.
- **Consumo en obra**: el gasto día a día es un movimiento `consumo_obra` que baja la
  existencia de la obra. Distinto del `traslado` (mover entre ubicaciones).
- **Imputación de costo al proyecto**: el costo golpea el presupuesto del proyecto cuando
  el material **sale de la bodega central** hacia la obra (ahí ya se gastó). El stock en
  obra es control físico de lo no consumido, no plata pendiente.
- **Costeo: promedio ponderado móvil**. El costo unitario se recalcula SOLO al entrar
  mercadería; las salidas usan el promedio vigente sin alterarlo. Implementación:
  `existencia.cantidad` + `existencia.valor_total`; costo_promedio = valor_total / cantidad.

### Tablas

- `bodegas` — catálogo de bodegas físicas (auto-código BOD-#####).
- `existencias` — una fila por (item, ubicación). `bodega_id` XOR `proyecto_id`
  (CHECK exactly-one). Lleva cantidad + valor_total para WAC.
- `movimientos_inventario` — libro mayor inmutable de cada movimiento físico, con tipo,
  origen, destino, cantidad, costo_unitario, valor_total, motivo, referencia polimórfica.

---

## 5. Maquinaria por unidad

Ver ADR-0002. Resumen:

- `maquinas` — marca, modelo, año, horas acumuladas, estado (operativa / mantenimiento).
- `lecturas_horometro` — historial de horómetro por máquina.
- `asignaciones_maquina` — máquina + proyecto + operador + fecha + horas_programadas +
  horas_reales. **Se cobra a sí misma**: costo al proyecto = horas_reales × costo_hora.
- **Horas extra**: si horas_reales > horas_programadas, la diferencia exige **motivo
  obligatorio**. No se cierra la asignación con horas extra sin explicación.
- **Sustitución por avería**: una asignación puede reasignarse a otra máquina con motivo
  ("avería"); la máquina averiada pasa a estado `mantenimiento` (enlaza con repuestos).
  El intercambio queda registrado (qué entró, qué salió, por qué).
- **Combustible semanal**: tabla `precio_combustible` con vigencia por semana. El
  costo_hora incluye combustible = consumo_promedio (gal/h) × precio_galon de esa semana.
- `repuestos` / `mantenimientos` — costos imputados a la máquina.

---

## 6. Finanzas: saldos + abonos (sin partida doble)

Ver ADR-0002. Resumen:

- `proveedores` — compartido entre compras de bodega y repuestos de maquinaria.
  Condición crédito/contado, saldo.
- `compras` — a proveedor; alimentan la bodega (entrada de stock con costo de compra que
  define el WAC). Crédito o contado.
- `cuentas` (por cobrar y por pagar) — saldo por cliente/proveedor.
- `abonos` — bajan el saldo. Reportes salen de aquí.
- **Reporte ingresos vs egresos por proyecto/cliente** — todo costo real (material
  despachado, horas de retro, jornales, combustible) se imputa al proyecto; los ingresos
  son los abonos del cliente. La alerta al 80% del presupuesto compara
  costo_real_acumulado / presupuesto_aprobado.
- `planilla` — maestro, jornales, horas extra, precio acordado. Imputable a proyecto.

---

## 7. Orden de construcción (post-MVP)

Primero se termina el MVP (fichas + presupuestos + PDF) porque es lo que le vende la idea
al dueño. Después, por dependencia:

| Fase | Módulo | Depende de | Por qué este orden |
|---|---|---|---|
| **A** | Bodega + Inventario + Requisiciones | items, proyectos | Es la columna vertebral; todo cuelga aquí. |
| **B** | Proveedores + Compras + CxP | Fase A (compras alimentan stock) | El stock necesita una fuente de entrada con costo real. |
| **C** | Maquinaria + horómetro + combustible | proyectos | Imputa costo de máquina a obra. |
| **D** | Planilla + CxC + reportes ingresos/egresos | A, B, C (consolidan costos) | Necesita los costos reales corriendo. |
| **E** | Alertas / BPI (80% presupuesto, etc.) | D | Tienen sentido con costos reales acumulados. |

### Estado de implementación

- [x] **Fase A — núcleo de inventario (datos):** enums + migraciones (bodegas,
      existencias, movimientos_inventario) + modelos. *(2026-06-18)*
- [ ] Fase A — Service de costeo WAC + máquina de requisiciones.
- [ ] Fase A — Filament Resources + Policies + tests.
- [ ] Fase B — Proveedores + Compras + CxP.
- [ ] Fase C — Maquinaria.
- [ ] Fase D — Planilla + CxC + reportes.
- [ ] Fase E — Alertas / BPI.

---

## 8. Áreas como roles de acceso (Filament Shield)

Las 3 áreas operativas se mapean a roles. Cada usuario ve solo su bandeja:

- **Administración** — autoriza requisiciones/compras, ve finanzas, planilla, reportes.
- **Bodega** — recibe requisiciones autorizadas, despacha, registra entradas, ajustes.
- **Residente de obra** — solicita requisiciones, confirma recepción, registra consumo.
- **Maquinaria** — gestiona asignaciones, horómetro, mantenimiento.
- **super_admin** — todo (ya existe).

El navigationGroup de Filament agrupa por área para que la UI refleje el organigrama.
