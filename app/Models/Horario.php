<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um tempo do horário semanal: a disciplina de uma turma (leccionação), o dia, as horas e a sala.
 * Só por App\Services\HorarioService, que não deixa sobrepor turma, docente nem sala.
 *
 * @property int $id
 * @property int $leccionacao_id
 * @property int $dia
 * @property string $inicio
 * @property string $fim
 * @property int|null $sala_id
 * @property-read Leccionacao $leccionacao
 * @property-read Sala|null $sala
 */
class Horario extends Model
{
    public const DIAS = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado'];

    protected $fillable = ['leccionacao_id', 'dia', 'inicio', 'fim', 'sala_id'];

    protected function casts(): array
    {
        return ['dia' => 'integer'];
    }

    /** @return BelongsTo<Leccionacao, $this> */
    public function leccionacao(): BelongsTo
    {
        return $this->belongsTo(Leccionacao::class, 'leccionacao_id');
    }

    /** @return BelongsTo<Sala, $this> */
    public function sala(): BelongsTo
    {
        return $this->belongsTo(Sala::class, 'sala_id');
    }

    /** "segunda, das 08:00 às 09:30" */
    public function quando(): string
    {
        return mb_strtolower(self::DIAS[$this->dia] ?? (string) $this->dia).", das {$this->inicio} às {$this->fim}";
    }

    /**
     * Para o ecrã: o tempo com a disciplina, a turma, o docente e a sala.
     *
     * @return array<string, mixed>
     */
    public function paraGrelha(): array
    {
        $l = $this->leccionacao;

        return [
            'id' => $this->id,
            'leccionacao_id' => $this->leccionacao_id,
            'dia' => $this->dia,
            'inicio' => $this->inicio,
            'fim' => $this->fim,
            'disciplina' => $l->disciplina->nome,
            'codigo' => $l->disciplina->codigo,
            'turma' => $l->turma->designacao(),
            'turma_id' => $l->turma_id,
            'docente' => $l->docente?->nome,
            'sala' => $this->sala?->codigo,
        ];
    }
}
