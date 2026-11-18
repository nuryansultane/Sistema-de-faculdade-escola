<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Horário semanal (RF-55): cada linha é um tempo de uma disciplina de uma turma (leccionação), num dia da semana, das
 * horas de início às de fim, numa sala. Os tempos de uma turma, de um docente e de uma sala não se sobrepõem no mesmo
 * período lectivo (App\Services\HorarioService). As horas guardam-se como "HH:MM", que se comparam como texto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('horarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leccionacao_id')->constrained('leccionacoes')->cascadeOnDelete();
            $table->unsignedTinyInteger('dia'); // 1 = segunda … 6 = sábado
            $table->string('inicio', 5); // 08:00
            $table->string('fim', 5); // 09:30
            $table->foreignId('sala_id')->nullable()->constrained('salas')->nullOnDelete();
            $table->timestamps();
            $table->index(['dia', 'inicio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('horarios');
    }
};
