<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Permisos PERSONALIZADOS (los que Shield no genera desde Resources) —
 * ÚNICA fuente de nombres. Los consumen:
 *
 *  - config/filament-shield.php ('custom_permissions') → los muestra la
 *    pestaña "Personalizados" de la pantalla de Roles (todo administrable
 *    desde el panel gráfico, nunca solo por código).
 *  - App\Filament\Resources\Shield\RoleResource → los agrupa por módulo
 *    con título de sección en esa pestaña.
 *  - RolesInventarioSeeder → los crea (findOrCreate) y asigna defaults.
 *  - Las acciones/queries que los chequean (AccionesEjecucion, scoping).
 *
 * Convención: `{Accion}:{Modelo}` en PascalCase (igual que Shield genera
 * los de Resources). NUNCA snake_case: la pestaña de personalizados pasa
 * los nombres por Str::studly y un nombre snake quedaría desalineado con
 * el permiso real en la base de datos.
 */
final class Permisos
{
    // ── Ejecución de obra (botón "Ejecución" en Proyectos) ──────────────
    public const string INICIAR_PROYECTO = 'Iniciar:Proyecto';

    public const string PAUSAR_PROYECTO = 'Pausar:Proyecto';

    public const string REACTIVAR_PROYECTO = 'Reactivar:Proyecto';

    public const string FINALIZAR_PROYECTO = 'Finalizar:Proyecto';

    public const string CANCELAR_PROYECTO = 'Cancelar:Proyecto';

    public const string REGISTRAR_ANTICIPO_PROYECTO = 'RegistrarAnticipo:Proyecto';

    public const string AJUSTAR_PLAZO_PROYECTO = 'AjustarPlazo:Proyecto';

    // ── Visibilidad por estado en Proyectos ─────────────────────────────
    // Las obras VIVAS (En ejecución / Pausada) son la base de todo rol con
    // acceso a Proyectos; el resto de estados se otorga UNO POR UNO desde
    // la pestaña Personalizados de la pantalla de Roles.
    public const string VER_BORRADORES_PROYECTO = 'VerBorradores:Proyecto';

    public const string VER_ENVIADAS_PROYECTO = 'VerEnviadas:Proyecto';

    public const string VER_APROBADAS_PROYECTO = 'VerAprobadas:Proyecto';

    public const string VER_VENCIDAS_PROYECTO = 'VerVencidas:Proyecto';

    public const string VER_RECHAZADAS_PROYECTO = 'VerRechazadas:Proyecto';

    public const string VER_FINALIZADAS_PROYECTO = 'VerFinalizadas:Proyecto';

    public const string VER_CANCELADAS_PROYECTO = 'VerCanceladas:Proyecto';

    // ── Reportes PDF de Proyectos ───────────────────────────────────────
    /** PDF de costos: contiene MARGEN y costo real — dato sensible. */
    public const string DESCARGAR_PDF_COSTOS_PROYECTO = 'DescargarPdfCostos:Proyecto';

    /** PDF de composición: presupuesto completo (renglones, precios, ISV). */
    public const string DESCARGAR_PDF_COMPOSICION_PROYECTO = 'DescargarPdfComposicion:Proyecto';

    // ── Compras — operaciones sensibles ─────────────────────────────────
    /** Anular una compra confirmada (revierte inventario y CxP). */
    public const string ANULAR_COMPRA = 'Anular:Compra';

    /** Verificar la recepción de una compra (contar lo llegado, G2). */
    public const string VERIFICAR_RECEPCION_COMPRA = 'VerificarRecepcion:Compra';

    /** Comprar a obra material NO presupuestado (imprevistos autorizados). */
    public const string COMPRAR_FUERA_DE_PRESUPUESTO = 'ComprarFueraDePresupuesto:Compra';

    /**
     * Corregir el conteo de una recepción YA CONFIRMADA ("dije 40 y eran
     * 60") — mueve inventario (ajuste ±), por eso es operación sensible.
     * Mientras la compra sigue Por recibir, corregir solo pide el permiso
     * de verificar (el stock aún no entró).
     */
    public const string CORREGIR_RECEPCION_COMPRA = 'CorregirRecepcion:Compra';

    /**
     * Completar (sellar) una compra cuadrada: cierre definitivo tras la
     * ventana de corrección — ya no se corrige, ni anula, ni edita.
     */
    public const string COMPLETAR_COMPRA = 'Completar:Compra';

    // ── Requisiciones — flujo operativo ─────────────────────────────────
    /** Autorizar (o ajustar cantidades de) una requisición solicitada. */
    public const string AUTORIZAR_REQUISICION = 'Autorizar:Requisicion';

    /** Lado bodega: despachar, marcar en tránsito y conciliar el cierre. */
    public const string DESPACHAR_REQUISICION = 'Despachar:Requisicion';

    /** Confirmar en OBRA lo que llegó (con alcance: solo SUS obras). */
    public const string RECIBIR_REQUISICION = 'RecibirEnObra:Requisicion';

    /** Rechazar una requisición en estado temprano. */
    public const string RECHAZAR_REQUISICION = 'Rechazar:Requisicion';

    /** Realizar la compra de una requisición SIN stock (atajo prellenado). */
    public const string REALIZAR_COMPRA_REQUISICION = 'RealizarCompra:Requisicion';

    // ── Inventario ──────────────────────────────────────────────────────
    /** Ver inventario de TODAS las bodegas (bypass de bodegas asignadas). */
    public const string VER_TODAS_LAS_BODEGAS = 'VerTodasLasBodegas:Bodega';

    /**
     * Estado de proyecto (valor del enum EstadoProyecto) → permiso que lo
     * hace visible. Consumido por ProyectoResource::estadosVisibles() para
     * el listado Y las tabs (misma fuente, nunca desincronizados).
     *
     * @var array<string, string>
     */
    public const array VER_ESTADO_PROYECTO = [
        'borrador'   => self::VER_BORRADORES_PROYECTO,
        'enviada'    => self::VER_ENVIADAS_PROYECTO,
        'aprobada'   => self::VER_APROBADAS_PROYECTO,
        'vencida'    => self::VER_VENCIDAS_PROYECTO,
        'rechazada'  => self::VER_RECHAZADAS_PROYECTO,
        'finalizada' => self::VER_FINALIZADAS_PROYECTO,
        'cancelada'  => self::VER_CANCELADAS_PROYECTO,
    ];

    /**
     * Los 7 de ejecución de obra, juntos (seeder y docs).
     *
     * @var list<string>
     */
    public const array EJECUCION_PROYECTO = [
        self::INICIAR_PROYECTO,
        self::PAUSAR_PROYECTO,
        self::REACTIVAR_PROYECTO,
        self::FINALIZAR_PROYECTO,
        self::CANCELAR_PROYECTO,
        self::REGISTRAR_ANTICIPO_PROYECTO,
        self::AJUSTAR_PLAZO_PROYECTO,
    ];

    /**
     * Personalizados AGRUPADOS por módulo (título de sección en la pestaña
     * Personalizados de la pantalla de Roles). Agregar un permiso custom
     * nuevo = agregarlo a su grupo AQUÍ (aparece solo en el panel) +
     * crearlo/asignar default en RolesInventarioSeeder.
     *
     * @var array<string, array<string, string>>
     */
    public const array PERSONALIZADOS_POR_MODULO = [
        'Proyectos — Ejecución de obra' => [
            self::INICIAR_PROYECTO            => 'Iniciar proyecto',
            self::PAUSAR_PROYECTO             => 'Pausar proyecto',
            self::REACTIVAR_PROYECTO          => 'Reactivar proyecto',
            self::FINALIZAR_PROYECTO          => 'Finalizar proyecto',
            self::CANCELAR_PROYECTO           => 'Cancelar proyecto',
            self::REGISTRAR_ANTICIPO_PROYECTO => 'Registrar anticipo del cliente',
            self::AJUSTAR_PLAZO_PROYECTO      => 'Ajustar plazo de la obra',
        ],
        'Proyectos — Visibilidad por estado' => [
            self::VER_BORRADORES_PROYECTO  => 'Ver proyectos en borrador',
            self::VER_ENVIADAS_PROYECTO    => 'Ver proyectos enviados al cliente',
            self::VER_APROBADAS_PROYECTO   => 'Ver proyectos aprobados',
            self::VER_VENCIDAS_PROYECTO    => 'Ver proyectos vencidos',
            self::VER_RECHAZADAS_PROYECTO  => 'Ver proyectos rechazados',
            self::VER_FINALIZADAS_PROYECTO => 'Ver proyectos finalizados',
            self::VER_CANCELADAS_PROYECTO  => 'Ver proyectos cancelados',
        ],
        'Proyectos — Reportes' => [
            self::DESCARGAR_PDF_COSTOS_PROYECTO      => 'Descargar PDF de costos y margen',
            self::DESCARGAR_PDF_COMPOSICION_PROYECTO => 'Descargar PDF de composición del proyecto',
        ],
        'Compras — Operaciones sensibles' => [
            self::ANULAR_COMPRA                => 'Anular compra confirmada',
            self::VERIFICAR_RECEPCION_COMPRA   => 'Verificar recepción de compras',
            self::CORREGIR_RECEPCION_COMPRA    => 'Corregir recepción ya confirmada (ajusta stock)',
            self::COMPLETAR_COMPRA             => 'Completar compra cuadrada (cierre definitivo)',
            self::COMPRAR_FUERA_DE_PRESUPUESTO => 'Comprar fuera de presupuesto (imprevistos)',
        ],
        'Requisiciones — Flujo' => [
            self::AUTORIZAR_REQUISICION       => 'Autorizar requisiciones',
            self::DESPACHAR_REQUISICION       => 'Despachar, marcar en tránsito y conciliar (lado bodega)',
            self::RECIBIR_REQUISICION         => 'Recibir material en obra (solo sus obras)',
            self::RECHAZAR_REQUISICION        => 'Rechazar requisiciones',
            self::REALIZAR_COMPRA_REQUISICION => 'Realizar la compra de una requisición sin stock',
        ],
        'Inventario — Bodegas' => [
            self::VER_TODAS_LAS_BODEGAS => 'Ver todas las bodegas',
        ],
    ];

    /**
     * TODOS los personalizados en plano (permiso → etiqueta) — derivado de
     * los grupos, para config de Shield y seeders.
     *
     * @var array<string, string>
     */
    public const array PERSONALIZADOS = self::PERSONALIZADOS_POR_MODULO['Proyectos — Ejecución de obra']
        + self::PERSONALIZADOS_POR_MODULO['Proyectos — Visibilidad por estado']
        + self::PERSONALIZADOS_POR_MODULO['Proyectos — Reportes']
        + self::PERSONALIZADOS_POR_MODULO['Compras — Operaciones sensibles']
        + self::PERSONALIZADOS_POR_MODULO['Requisiciones — Flujo']
        + self::PERSONALIZADOS_POR_MODULO['Inventario — Bodegas'];
}
