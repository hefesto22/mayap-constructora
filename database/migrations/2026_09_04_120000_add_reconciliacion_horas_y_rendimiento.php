<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RECONCILIACIÓN DE HORAS — el horómetro y las horas cobradas NO son lo mismo.
 *
 * El horómetro mide MOTOR ENCENDIDO; las horas del parte son horas
 * PRODUCTIVAS. La diferencia es tiempo muerto: calentamiento, esperas,
 * traslados dentro de la obra, el almuerzo con el motor prendido. En
 * construcción eso promedia ~30% del tiempo de motor, o sea que un
 * horómetro que corrió 12 con 8 horas cobradas es un día NORMAL.
 *
 * Hasta hoy el sistema obligaba a elegir: por horómetro cobraba el delta
 * completo (12), y manual cobraba 8 pero dejaba el horómetro de la máquina
 * congelado para siempre. Ninguna de las dos decía la verdad.
 *
 * Lo que se guarda ahora en cada parte:
 *  - horas_motor: el delta del horómetro (lo que corrió el motor).
 *  - horas: lo que se cobra a la obra (ya existía).
 *  - horas_muertas: la diferencia, que es un dato de gestión, no un error.
 *  - motivo_idle: por qué tanto tiempo muerto, cuando supera el tolerado.
 *  - salto_horometro: horas que aparecieron en el horómetro y NADIE reportó
 *    — la lectura inicial de hoy no empalma con la final del último parte.
 *    Es el detector de uso no declarado (fines de semana, trabajos por fuera).
 *
 * Y en la máquina, litros_por_hora: el rendimiento nominal. Sirve para
 * prellenar el consumo estimado del día y para comparar contra el real
 * acumulado — un rendimiento disparado es fuga, falla mecánica o robo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partes_trabajo', function (Blueprint $table): void {
            $table->decimal('horas_motor', 8, 2)->nullable()->after('horas');
            $table->decimal('horas_muertas', 8, 2)->default(0)->after('horas_motor');
            $table->text('motivo_idle')->nullable()->after('motivo_horas_extra');
            $table->decimal('salto_horometro', 8, 2)->default(0)->after('lectura_final');

            $table->index('salto_horometro');
        });

        DB::statement(
            'ALTER TABLE partes_trabajo ADD CONSTRAINT partes_horas_motor_coherentes
             CHECK (
                 horas_motor IS NULL
                 OR (horas_motor >= 0 AND horas <= horas_motor)
             )'
        );

        DB::statement(
            'ALTER TABLE partes_trabajo ADD CONSTRAINT partes_horas_muertas_no_negativas
             CHECK (horas_muertas >= 0 AND salto_horometro >= 0)'
        );

        Schema::table('maquinas', function (Blueprint $table): void {
            $table->decimal('litros_por_hora', 6, 2)->nullable()->after('jornada_horas');
        });

        DB::statement(
            'ALTER TABLE maquinas ADD CONSTRAINT maquinas_litros_por_hora_valido
             CHECK (litros_por_hora IS NULL OR litros_por_hora >= 0)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE maquinas DROP CONSTRAINT IF EXISTS maquinas_litros_por_hora_valido');
        DB::statement('ALTER TABLE partes_trabajo DROP CONSTRAINT IF EXISTS partes_horas_motor_coherentes');
        DB::statement('ALTER TABLE partes_trabajo DROP CONSTRAINT IF EXISTS partes_horas_muertas_no_negativas');

        Schema::table('maquinas', function (Blueprint $table): void {
            $table->dropColumn('litros_por_hora');
        });

        Schema::table('partes_trabajo', function (Blueprint $table): void {
            $table->dropColumn(['horas_motor', 'horas_muertas', 'motivo_idle', 'salto_horometro']);
        });
    }
};
