<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sumários e presenças (RF-60, RF-61): cada aula dada de uma disciplina de uma turma, com o sumário, e a presença de
 * cada estudante inscrito nessa aula (presente, atrasado ou falta). Uma falta justifica-se com o motivo, sem apagar
 * o registo do docente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aulas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leccionacao_id')->constrained('leccionacoes')->cascadeOnDelete();
            $table->date('data');
            $table->string('inicio', 5);
            $table->string('fim', 5);
            $table->text('sumario');
            $table->foreignId('registada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['leccionacao_id', 'data', 'inicio']);
        });

        Schema::create('presencas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aula_id')->constrained('aulas')->cascadeOnDelete();
            $table->foreignId('inscricao_id')->constrained('inscricoes')->cascadeOnDelete();
            $table->string('estado', 12)->default('presente'); // presente, atraso, falta
            $table->boolean('justificada')->default(false);
            $table->string('justificacao', 300)->nullable();
            $table->foreignId('justificada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('justificada_em')->nullable();
            $table->timestamps();
            $table->unique(['aula_id', 'inscricao_id']);
            $table->index(['inscricao_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presencas');
        Schema::dropIfExists('aulas');
    }
};
