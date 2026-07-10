# Módulo de Ejecución de Obra (Proyectos)

Extiende el módulo de Proyectos más allá de la cotización: cubre el ciclo
completo de la obra desde la aprobación del cliente hasta el cierre, con
anticipo, plazo, avance físico y control de estados con motivos.

## Ciclo de vida completo

```
Borrador → Enviada → Aprobada → En ejecución ⇄ Pausada → Finalizada
                  ↘ Rechazada              ↘ (cualquiera) → Cancelada
                  ↘ Vencida
```

Estados (`App\Enums\EstadoProyecto`):

| Estado        | Fase       | Notas |
|---------------|------------|-------|
| Borrador      | Comercial  | Único editable en renglones. |
| Enviada       | Comercial  | Cotización enviada al cliente. |
| Aprobada      | Comercial  | Cliente aceptó. Puede iniciar o cancelarse. |
| Rechazada     | Comercial  | Terminal. |
| Vencida       | Comercial  | Terminal. Marca automática por Job. |
| En ejecución  | Ejecución  | Obra en curso, el plazo corre. |
| Pausada       | Ejecución  | Detenida con motivo, reanudable. |
| Finalizada    | Ejecución  | Terminal. Fija fecha de fin real. |
| Cancelada     | Ejecución  | Terminal con motivo. |

Reglas clave:
- Solo **Borrador** permite editar renglones (composición).
- **Pausar** y **Cancelar** exigen motivo (se guarda y queda en activitylog).
- **En ejecución / Pausada / Finalizada** exigen `fecha_inicio` (CHECK constraint).

## Datos nuevos en `proyectos`

- Anticipo: `anticipo_monto`, `anticipo_fecha`, `anticipo_recibido`.
- Plazo: `modo_plazo` (calendario | habiles), `plazo_dias`, `fecha_inicio`,
  `fecha_fin_estimada`, `fecha_fin_real`.
- Motivos: `motivo_pausa`, `motivo_cancelacion`.
- Avance: `avance_fisico_cache` (% derivado de las actividades).

CHECK constraints garantizan coherencia (estado válido, inicio requerido en
ejecución, fechas de fin ≥ inicio, plazo > 0, avance 0–100).

## Actividades (avance físico)

Tabla `proyecto_actividades`: checklist de hitos de la obra. Cada actividad
tiene `nombre`, `peso` (ponderación opcional) y `completada`.

`CalcularAvanceProyectoService` calcula el % de avance:
- Peso vacío → cuenta como 1 (todas valen igual).
- Con peso → `Σ peso(completadas) / Σ peso(todas) × 100`.

El modelo `ProyectoActividad` recalcula el cache del proyecto automáticamente
al guardar/borrar actividades, y mantiene coherente `fecha_completada` con el
toggle `completada`.

## Cálculo del plazo

`App\Support\CalculadorPlazo::calcularFechaFin($inicio, $dias, $modo)`:
- **Calendario**: `inicio + N` días corridos.
- **Hábiles**: avanza N días saltando sábados y domingos.

> Deuda explícita: el modo hábiles aún no descuenta feriados nacionales de
> Honduras (pendiente: lista en `config/honduras.php`). Solo fin de semana.

## Services de dominio

| Service | Responsabilidad |
|---------|-----------------|
| `IniciarProyectoService` | Aprobada → En ejecución. Fija inicio/plazo/modo, calcula fin estimada. |
| `CambiarEstadoEjecucionService` | pausar / reactivar / finalizar / cancelar (con lock y validación de transición). |
| `RegistrarAnticipoService` | Registra anticipo (monto/fecha/recibido). |
| `CalcularAvanceProyectoService` | Calcula y cachea el % de avance físico. |

Todos validan la máquina de estados (`EstadoProyecto::puedeTransicionarA`) y
corren en transacción con `lockForUpdate`. Excepciones tipadas:
`TransicionEstadoInvalidaException`, `DatosEjecucionInvalidosException`.

## UI (Filament)

- **Composición**: `Repeater::table()` — grid tipo hoja de cálculo (Capítulo ·
  Ficha APU · Cantidad · P. Unitario · Subtotal · Notas). El precio se carga
  solo al elegir la ficha.
- **"Agregar varias fichas"**: acción en editar (solo Borrador) para cargar
  varias fichas de golpe (cantidad 1, ajustables después).
- **Pestaña Ejecución**: panel con fechas, plazo, barra de avance de tiempo,
  barra de avance físico, alerta de atraso y resumen del anticipo.
- **Pestaña Actividades**: checklist con vista previa en vivo del % de obra.
- **Acciones**: Iniciar, Registrar anticipo, Pausar, Reactivar, Finalizar,
  Cancelar — visibles según el estado, cada una con su modal/motivo.
- **Listado**: columnas Avance obra (%) y Plazo (días restantes / atraso);
  tabs por estado para todos los estados nuevos.

## Helpers de obra en el modelo `Proyecto`

`diasTranscurridos()`, `diasRestantes()`, `porcentajeTiempo()`,
`estaAtrasado()` — todos null-safe cuando la obra no ha arrancado.

## Comandos

```bash
php artisan migrate        # aplica las 2 migraciones del módulo
php artisan test           # suite completa verde
```
