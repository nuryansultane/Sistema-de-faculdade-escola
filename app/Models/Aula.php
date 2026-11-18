<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Uma aula dada: a data, as horas, o sumário e as presenças dos estudantes. Só por App\Services\AulaService.
 *
 * @property int $id
 * @property int $leccionacao_id
 * @property Carbon $data
 * @property string $inicio
 * @property string $fim
 * @property string $sumario
 * @property int|null $registada_por
 * @property-read Leccionacao $leccionacao
 * @property-read User|null $registadaPor
 */
class Aula extends Model
{
    protected $fillable = ['leccionacao_id', 'data', 'inicio', 'fim', 'sumario', 'registada_por'];

    protected function casts(): array
    {
        return ['data' => 'date'];
    }

    /** @return BelongsTo<Leccionacao, $this> */
    public function leccionacao(): BelongsTo
    {
        return $this->belongsTo(Leccionacao::class, 'leccionacao_id');
    }

    /** @return BelongsTo<User, $this> */
    public function registadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registada_por');
    }

    /** @return HasMany<Presenca, $this> */
    public function presencas(): HasMany
    {
        return $this->hasMany(Presenca::class, 'aula_id');
    }
}
