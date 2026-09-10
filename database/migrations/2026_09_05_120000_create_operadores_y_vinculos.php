<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CATÁLOGO DE OPERADORES (Mauricio, 2026-09-05).
 *
 * "A veces el operador sí es empleado pero otras veces es externo, así
 * que ¿cómo deberíamos manejar eso?" — un operador es una PERSONA que
 * maneja máquinas, y que esté o no en planilla es un dato suyo, no dos
 * mundos distintos. Por eso vive en su propia tabla con un vínculo
 * OPCIONAL a empleados: el de planilla queda ligado (y hereda su
 * identidad y su cargo), el externo existe igual.
 *
 * La máquina guarda su operador habitual — casi siempre es el mismo
 * señor en la misma máquina — y el parte del día lo trae puesto.
 *
 * El texto libre `operador` NO se borra: queda como el nombre tal cual
 * se escribió ese día. Así, renombrar a una persona no reescribe la
 * historia, y los partes viejos siguen diciendo lo que decían.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operadores', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo', 20)->unique();
            $table->string('nombre', 150);
            // Vínculo OPCIONAL con planilla: null = operador externo.
            $table->foreignId('empleado_id')->nullable()->constrained('empleados')->nullOnDelete();
            $table->string('telefono', 30)->nullable();
            $table->string('licencia', 50)->nullable();
            $table->text('notas')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('nombre');
            $table->index(['activo', 'nombre']);
        });

        // Un empleado no puede estar dos veces en el catálogo.
        DB::statement('CREATE UNIQUE INDEX operadores_empleado_unico ON operadores (empleado_id) WHERE empleado_id IS NOT NULL AND deleted_at IS NULL');

        // El mismo nombre tampoco se repite entre los vivos.
        DB::statement('CREATE UNIQUE INDEX operadores_nombre_unico ON operadores (nombre) WHERE deleted_at IS NULL');

        Schema::table('maquinas', function (Blueprint $table): void {
            $table->foreignId('operador_habitual_id')->nullable()->after('modalidad_trabajo')
                ->constrained('operadores')->nullOnDelete();
        });

        Schema::table('partes_trabajo', function (Blueprint $table): void {
            $table->foreignId('operador_id')->nullable()->after('operador')
                ->constrained('operadores')->nullOnDelete();
        });

        Schema::table('consumos_combustible', function (Blueprint $table): void {
            $table->foreignId('operador_id')->nullable()->after('operador')
                ->constrained('operadores')->nullOnDelete();
        });

        $this->rescatarNombresExistentes();
    }

    /**
     * Los nombres que ya están escritos a mano en partes y consumos se
     * convierten en operadores del catálogo, y se ligan al empleado si
     * el nombre coincide exactamente. Sin esto, el catálogo arrancaría
     * vacío y la historia quedaría desconectada.
     */
    private function rescatarNombresExistentes(): void
    {
        $nombres = DB::table('partes_trabajo')
            ->whereNotNull('operador')
            ->distinct()
            ->pluck('operador')
            ->merge(
                DB::table('consumos_combustible')
                    ->whereNotNull('operador')
                    ->distinct()
                    ->pluck('operador')
            )
            ->map(fn ($n): string => trim((string) $n))
            ->filter(fn (string $n): bool => $n !== '')
            ->unique()
            ->values();

        if ($nombres->isEmpty()) {
            return;
        }

        $consecutivo = 0;

        foreach ($nombres as $nombre) {
            $consecutivo++;

            $empleadoId = DB::table('empleados')
                ->whereRaw('UPPER(nombre) = ?', [mb_strtoupper($nombre)])
                ->value('id');

            $id = DB::table('operadores')->insertGetId([
                'codigo'      => 'OPE-'.str_pad((string) $consecutivo, 5, '0', STR_PAD_LEFT),
                'nombre'      => mb_strtoupper($nombre),
                'empleado_id' => $empleadoId,
                'activo'      => true,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            DB::table('partes_trabajo')->whereRaw('TRIM(operador) = ?', [$nombre])->update(['operador_id' => $id]);
            DB::table('consumos_combustible')->whereRaw('TRIM(operador) = ?', [$nombre])->update(['operador_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('consumos_combustible', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('operador_id');
        });

        Schema::table('partes_trabajo', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('operador_id');
        });

        Schema::table('maquinas', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('operador_habitual_id');
        });

        Schema::dropIfExists('operadores');
    }
};
