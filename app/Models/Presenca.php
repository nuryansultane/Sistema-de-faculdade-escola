<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A presença de um estudante (a sua inscrição na disciplina) numa aula. A falta justificada continua a ser falta,
 * com o motivo e quem a justificou; não conta para o limite de faltas.
 *
 * @property int $id
 * @property int $aula_id
 * @property int $inscricao_id
 * @property string $estado
 * @property bool $justificada
 * @property string|null $justificacao
 * @property int|null $justificada_por
 * @property Carbon|null $justificada_em
 * @property-read Aula $aula
 * @property-read Inscricao $inscricao
 */
class Presenca extends Model
{
    public const PRESENTE = 'presente';

    public const ATRASO = 'atraso';

    public const FALTA = 'falta';

    public const ESTADOS = [self::PRESENTE => 'Presente', self::ATRASO => 'Atrasado', self::FALTA => 'Falta'];

    protected $fillable = ['aula_id', 'inscricao_id', 'estado', 'justificada', 'justificacao', 'justificada_por', 'justificada_em'];

    protected $attributes = ['estado' => self::PRESENTE, 'justificada' => false];

    protected function casts(): array
    {
        return ['justificada' => 'boolean', 'justificada_em' => 'datetime'];
    }

    /** @return BelongsTo<Aula, $this> */
    public function aula(): BelongsTo
    {
        return $this->belongsTo(Aula::class, 'aula_id');
    }

    /** @return BelongsTo<Inscricao, $this> */
    public function inscricao(): BelongsTo
    {
        return $this->belongsTo(Inscricao::class, 'inscricao_id');
    }
}
