# Guía de flujo operativo y pruebas — Constructora MAYAP

> **Para qué sirve este documento:** es el mapa para *probar el sistema como lo haría un usuario real*, en orden, módulo por módulo. Sirve para validar que todo funciona en pantalla, detectar mejoras, y llevar un registro de qué ya se revisó. Cada sección tiene un espacio de **Notas / mejoras** para que escribas lo que quieras cambiar.
>
> **Estado del sistema:** módulos A→E completos, **454 pruebas automáticas en verde**, demo cargada. Lo que falta es *tu validación visual* y feedback.

---

## 0. Cómo arrancar la prueba

1. Tener corriendo el entorno (Herd): `constructora-mayap.test`.
2. Cargar datos de demostración (una obra recorrida de punta a punta):
   ```
   php artisan db:seed --class=DemoOperativoSeeder
   ```
3. Entrar al panel con el usuario admin.
4. La obra de demo se llama **"PAVIMENTACIÓN CALLE PRINCIPAL BARRIO EL CALVARIO"**.

**Números esperados de la obra demo** (para verificar que cuadra):

| Fuente | Cálculo | Monto |
|---|---|---|
| Materiales | 300 cemento × 250 + 150 hierro × 320 | L. 123,000.00 |
| Maquinaria | 24 h × 1,800 + 120 L × 110 | L. 56,400.00 |
| Mano de obra | maestro 7,000 + albañil (6 × 500) | L. 10,000.00 |
| **Costo total** | | **L. 189,400.00** |
| Presupuesto | | L. 500,000.00 |
| **Margen** | | **L. 310,600.00 (62%) — Sano 🟢** |

---

## 1. Flujo completo — el recorrido del dinero y los materiales

Este es el orden lógico en que el sistema se usa en la vida real. Pruébalo en este orden.

### 1.1 Catálogos base (lo que se configura una vez)

**Dónde:** grupos *Catálogos*, *Inventario*, *Compras*, *Maquinaria*, *Planilla*.

- [ ] **Unidades de medida, Zonas, Base de precios (items)** — Catálogos.
- [ ] **Bodegas** — Inventario. Crear una bodega central.
- [ ] **Proveedores** — Compras. Crear uno a crédito (con días de crédito).
- [ ] **Máquinas** — Maquinaria. Crear con su tarifa por hora y horómetro inicial.
- [ ] **Empleados** — Planilla. Crear con su tipo de pago (jornal / salario / destajo).
- [ ] **Clientes y Proyectos (obras)** — Comercial. Crear la obra con su presupuesto.

> ✅ *Ya validado:* auto-código, mayúsculas automáticas, y los formularios adaptados (tarifa según tipo de pago, días de crédito solo si es crédito) tienen pruebas en verde.

**Notas / mejoras:**
- …

### 1.2 Compras → Inventario → Cuentas por pagar

**Dónde:** Compras → Compras.

- [ ] Crear una **compra** a un proveedor (al elegirlo, hereda solo la condición de pago).
- [ ] Agregar líneas (items + cantidad + costo).
- [ ] Botón **Confirmar**: el stock entra a la bodega con su costo promedio, y si es a crédito se crea la **Cuenta por Pagar**.
- [ ] Ir a **Existencias** (Inventario): verificar que el stock entró con su costo.
- [ ] Ir a **Cuentas por Pagar**: ver la cuenta generada; botón **Abonar** para registrar un pago parcial; revisar la bitácora de abonos.

> ✅ *Ya validado:* el costo promedio ponderado (WAC), la generación de la CxP a crédito con su vencimiento, y los abonos que bajan el saldo sin sobrepago.

**Notas / mejoras:**
- …

### 1.3 Requisiciones (pedir material a la obra)

**Dónde:** Inventario → Requisiciones.

- [ ] Crear una requisición de la obra.
- [ ] Recorrer el flujo con los botones: **Autorizar → Despachar → En tránsito → Recibir → Conciliar/Cerrar**.
- [ ] Verificar la **bitácora**: cada paso queda registrado con su responsable.
- [ ] Si no hay stock, la requisición pasa sola a "Requisición de compra".

> ✅ *Ya validado:* todo el flujo de estados, el descuento real de inventario al despachar (con trazabilidad a la requisición), y la detección de discrepancias.

**Notas / mejoras:**
- …

### 1.4 Maquinaria trabajando en la obra

**Dónde:** Maquinaria → Asignaciones.

- [ ] Botón **Asignar máquina**: elegir una máquina disponible + la obra + tarifa pactada.
- [ ] En la asignación, botón **Registrar parte**: por horómetro (lectura inicial/final) o manual. Las horas extra piden motivo.
- [ ] Botón **Registrar combustible**: litros × precio.
- [ ] Ver la asignación: secciones de **Partes de trabajo** y **Combustible** con su total.
- [ ] Si una máquina se avería: en el catálogo de Máquinas, botón **Enviar a mantenimiento** (con sustituta opcional). Ver el registro en **Mantenimientos** y botón **Finalizar**.

> ✅ *Ya validado:* el cobro por horas, el horómetro que no retrocede, la sustitución por avería que libera la obra y asigna la máquina nueva, y la reparación que devuelve la máquina a disponible.

**Notas / mejoras:**
- …

### 1.5 Planilla (mano de obra)

**Dónde:** Planilla → Planillas.

- [ ] Crear una **planilla** (semanal / quincenal / mensual).
- [ ] Agregar líneas por empleado (cada uno hereda su tipo de pago; cargar cada uno a la obra).
- [ ] Botón **Cerrar**: calcula los montos y la planilla empieza a contar en el costo de la obra.

> ✅ *Ya validado:* el cálculo por jornal (días × tarifa), salario fijo y destajo, y que solo las planillas **cerradas** entran al costo.

**Notas / mejoras:**
- …

### 1.6 El reporte de costo por obra (el corazón para el dueño)

**Dónde:** Comercial → Proyectos → botón **Costos**.

- [ ] Ver el desglose: materiales + maquinaria + mano de obra vs presupuesto, con margen y nivel.
- [ ] Botón **Descargar PDF**: el estado de costo con membrete, listo para imprimir/enviar.
- [ ] En el **listado de Proyectos**: la columna de margen y la insignia de presupuesto (verde/amarillo/rojo).
- [ ] En el **dashboard**: el widget con el conteo de obras sanas / en riesgo / sobregiradas.

> ✅ *Ya validado:* el cálculo de las tres fuentes de costo, el margen, los niveles de alerta (80% / sobregiro) y el armado del PDF.

**Notas / mejoras:**
- …

### 1.7 Cuentas por cobrar (lo que el cliente debe)

**Dónde:** Comercial → Cuentas por Cobrar.

- [ ] Botón **Nueva cuenta por cobrar**: cliente + monto + vencimiento (opcionalmente ligada a una obra).
- [ ] Botón **Registrar cobro**: baja el saldo; ver la bitácora de cobros.

> ✅ *Ya validado:* la creación con saldo inicial, los cobros que bajan el saldo sin sobrecobro, y los estados (pendiente/parcial/pagada).

**Notas / mejoras:**
- …

---

## 2. Resumen de qué se probó

| Área | Pruebas automáticas | Validación visual tuya |
|---|---|---|
| Catálogos (unidades, zonas, items, bodegas, proveedores, máquinas, empleados, clientes, obras) | ✅ Verde | ⬜ Pendiente |
| Compras + Inventario WAC + Cuentas por Pagar | ✅ Verde | ⬜ Pendiente |
| Requisiciones (flujo + bitácora) | ✅ Verde | ⬜ Pendiente |
| Maquinaria (asignación, partes, combustible, mantenimiento) | ✅ Verde | ⬜ Pendiente |
| Planilla (jornal/salario/destajo) | ✅ Verde | ⬜ Pendiente |
| Reporte de costo por obra + PDF + alertas | ✅ Verde | ⬜ Pendiente |
| Cuentas por Cobrar + cobros | ✅ Verde | ⬜ Pendiente |

> **"Pruebas automáticas en verde"** = el comportamiento está garantizado por código (454 tests). **"Validación visual"** = que tú confirmes que en pantalla se ve y se usa bien.

---

## 3. Mejoras candidatas (fuera del plan original, para evaluar)

Ideas para los próximos chats, ordenadas por valor/esfuerzo según mi criterio:

1. **Más PDFs** reutilizando el patrón ya hecho: estado de cuenta de un cliente (CxC) y de un proveedor (CxP).
2. **Facturación / ventas** que genere las Cuentas por Cobrar automáticamente (hoy se crean a mano), igual que las compras generan las CxP.
3. **Deducciones de ley en planilla** (IHSS, RAP) y pago neto, si se necesita planilla formal.
4. **Optimización**: cachear el cálculo de costo por obra (hoy se recalcula al abrir cada reporte).
5. **Roles y permisos finos** por área (Bodega, Administración, Maquinaria) para producción.
6. **Exportes a Excel** (Maatwebsite) de listados que el contador necesite.

**Lo que yo recomiendo:** primero **enseñarle la demo al dueño** y dejar que su reacción priorice. Construir más sin su feedback es adivinar.

---

## 4. Cómo trabajamos las mejoras (el método)

Para cada mejora que quieras, en el próximo chat:

1. Me dices **qué viste** y **qué quieres cambiar** (de las notas de arriba).
2. Yo analizo, te propongo el enfoque y, si lo apruebas, lo construyo con sus pruebas.
3. Tú corres los comandos (`pint` → `phpstan` → `test`) y me mandas el resultado.
4. Lo pruebas en pantalla y me dices si quedó o seguimos afinando.
5. Commit cuando esté verde.

Este documento se actualiza marcando los ⬜ a ✅ a medida que validas cada área.
