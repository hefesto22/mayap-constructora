<?php

declare(strict_types=1);

use App\Jobs\MarcarProyectosVencidosJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Commands
|--------------------------------------------------------------------------
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Tareas Programadas
|--------------------------------------------------------------------------
| Para que estas tareas corran en producción, agrega al crontab del servidor:
|
|   * * * * * cd /var/www/proyectos/<nombre> && php artisan schedule:run >> /dev/null 2>&1
|
| (En Herd local NO necesita cron — el built-in scheduler de Herd
| corre `schedule:run` cada minuto automáticamente.)
*/

// ─── Backups ───────────────────────────────────────────────────────────
// Backup completo (DB + files) diario a las 02:00 a.m. hora Honduras.
Schedule::command('backup:run')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground()
    ->name('backup-diario');

// Limpieza de backups antiguos según política de retención (config/backup.php).
Schedule::command('backup:clean')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->name('backup-cleanup');

// Monitor de salud de backups — verifica que existen y son recientes.
Schedule::command('backup:monitor')
    ->dailyAt('04:00')
    ->onOneServer()
    ->name('backup-monitor');

// ─── Health checks ─────────────────────────────────────────────────────
// Ejecuta los checks definidos en HealthServiceProvider y los persiste
// si tienes result_stores activados en config/health.php.
Schedule::command('health:check')
    ->everyMinute()
    ->onOneServer()
    ->name('health-check');

// Notifica si algún check falló (envía mail/slack según config/health.php).
Schedule::command('health:schedule-check-heartbeat')
    ->everyMinute()
    ->onOneServer()
    ->name('health-heartbeat');

// ─── Horizon ───────────────────────────────────────────────────────────
// Snapshot de métricas para el dashboard de Horizon.
Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->onOneServer()
    ->name('horizon-snapshot');

// ─── Maquinaria: aviso de llegada ──────────────────────────────────────
// Campanita a los encargados cuando su máquina agendada llega dentro de
// la PRÓXIMA hora ("prepara el acceso"). Idempotente: cada agendado avisa
// UNA vez (marca aviso_llegada_at). En Herd corre solo; en prod, el cron.
Schedule::command('maquinaria:avisar-llegadas')
    ->everyTenMinutes()
    ->onOneServer()
    ->name('maquinaria-aviso-llegadas');

// ─── Mantenimiento de modelos ──────────────────────────────────────────
// Borra registros soft-deleted que cumplan la política de retención
// (cada modelo define su prunable() — opcional).
Schedule::command('model:prune')
    ->dailyAt('05:00')
    ->onOneServer()
    ->name('model-prune');

// ─── Logs ──────────────────────────────────────────────────────────────
// Limpieza de jobs fallidos de hace más de 7 días.
Schedule::command('queue:prune-failed --hours=168')
    ->dailyAt('05:30')
    ->onOneServer()
    ->name('queue-prune-failed');

// Limpieza de batches viejos.
Schedule::command('queue:prune-batches --hours=168')
    ->dailyAt('05:45')
    ->onOneServer()
    ->name('queue-prune-batches');

// ─── Proyectos / Cotizaciones ──────────────────────────────────────────
// Marca como `vencidas` las cotizaciones enviadas cuya fecha_validez ya
// pasó. Idempotente: correr dos veces el mismo día es seguro.
Schedule::job(new MarcarProyectosVencidosJob)
    ->dailyAt('01:00')
    ->onOneServer()
    ->name('proyectos-marcar-vencidos');

// ─── Cobranza: avisos de vencimiento ───────────────────────────────────
// Campanita a gerencia/recepción con las cuentas por cobrar que vencen
// en 7 días, 3 días, HOY o que ya vencieron (escalones idempotentes:
// ultimo_aviso_dias). Una pasada diaria en horario de oficina.
Schedule::command('cobranza:avisar-vencimientos')
    ->dailyAt('07:30')
    ->onOneServer()
    ->name('cobranza-avisos-vencimiento');

// ─── Pagos: avisos de vencimiento a proveedores ────────────────────────
// El espejo de la cobranza, pero de lo que DEBEMOS: campanita a
// gerencia/recepción con las cuentas por pagar que vencen en 7 días,
// 3 días, HOY o que ya vencieron impagas (escalones idempotentes).
Schedule::command('pagos:avisar-vencimientos')
    ->dailyAt('07:15')
    ->onOneServer()
    ->name('pagos-avisos-vencimiento');

// ─── Maquinaria: mantenimiento preventivo ──────────────────────────────
// Campanita a gerencia/maquinaria con los planes de mantenimiento
// (aceite, puntas, cuchillas...) PRÓXIMOS (90% del intervalo de horas /
// km / días) o VENCIDOS. Idempotente: ultimo_aviso_estado solo escala
// próximo → vencido; registrar el cambio rearma el ciclo.
Schedule::command('maquinaria:avisar-mantenimientos')
    ->dailyAt('07:00')
    ->onOneServer()
    ->name('maquinaria-avisos-mantenimiento');

// ─── Maquinaria: repuestos por llegar ──────────────────────────────────
// Campanita a gerencia/maquinaria/recepción cuando la fecha estimada de
// recepción de repuestos de un mantenimiento en proceso llegó (o pasó).
// Idempotente: aviso_repuestos_at avisa UNA vez; cambiar la fecha
// estimada lo reinicia y rearma la campanita.
Schedule::command('maquinaria:avisar-repuestos')
    ->dailyAt('07:05')
    ->onOneServer()
    ->name('maquinaria-avisos-repuestos');

// ─── Compras: pedidos por llegar ───────────────────────────────────────
// Campanita a recepción/gerencia cuando la fecha estimada de llegada de
// un pedido "por recibir" se alcanzó (típico: repuestos del taller).
// Idempotente: aviso_llegada_at avisa UNA vez; cambiar la fecha lo rearma.
Schedule::command('compras:avisar-llegadas')
    ->dailyAt('07:20')
    ->onOneServer()
    ->name('compras-avisos-llegadas');

// ─── Compras: ciclo fiscal mensual ─────────────────────────────────────
// Diario e idempotente: si al mes anterior le falta alguno de sus dos
// reportes (facturas de compras / pagos a proveedores) lo genera y
// avisa; después purga las fotos de los reportes que ya cumplieron el
// colchón de 7 días (solo con PDF sano). Los PDF quedan permanentes.
Schedule::command('compras:ciclo-fiscal-mensual')
    ->dailyAt('06:30')
    ->onOneServer()
    ->name('compras-ciclo-fiscal-mensual');

// ─── Activity Log ──────────────────────────────────────────────────────
// Limpieza periódica del log de Spatie ActivityLog. Por defecto el comando
// borra registros con más de 365 días (configurable en config/activitylog.php
// → delete_records_older_than_days). Sin esto la tabla crece indefinidamente
// y se vuelve un problema de performance + espacio en disco con el tiempo.
Schedule::command('activitylog:clean')
    ->dailyAt('06:00')
    ->onOneServer()
    ->name('activitylog-clean');

// ─── Telescope (si se activa) ──────────────────────────────────────────
// Schedule::command('telescope:prune --hours=48')->daily();

// ─── Sesiones expiradas ────────────────────────────────────────────────
// Solo si SESSION_DRIVER=database. En Redis no hace falta (TTL automático).
// Schedule::command('session:prune-expired')->daily();
