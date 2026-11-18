<?php

namespace App\Services;

use App\Models\Docente;
use App\Models\Horario;
use App\Models\Leccionacao;
use App\Models\Sala;
use App\Models\Turma;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Horário semanal sem sobreposições (RF-55): no mesmo período lectivo, uma turma não tem dois tempos ao mesmo tempo,
 * um docente não está em duas turmas ao mesmo tempo e uma sala não recebe duas aulas ao mesmo tempo. Dois tempos
 * sobrepõem-se quando são no mesmo dia e um começa antes de o outro acabar (08:00–10:00 e 10:00–12:00 não se
 * sobrepõem). As horas são "HH:MM", entre as 06:00 e as 23:00, com pelo menos 30 minutos.
 */
class HorarioService
{
    public const PRIMEIRA_HORA = '06:00';

    public const ULTIMA_HORA = '23:00';

    public function acrescentar(Leccionacao $leccionacao, int $dia, string $inicio, string $fim, ?Sala $sala): Horario
    {
        $this->validar($dia, $inicio, $fim, $sala);
        $leccionacao->loadMissing(['turma', 'disciplina', 'docente']);
        $this->exigirLivre($leccionacao, $leccionacao->docente, $dia, $inicio, $fim, $sala);

        $horario = Horario::create([
            'leccionacao_id' => $leccionacao->id, 'dia' => $dia, 'inicio' => $inicio, 'fim' => $fim, 'sala_id' => $sala?->id,
        ]);
        activity('docencia')->performedOn($leccionacao->turma)
            ->log("Horário da turma {$leccionacao->turma->designacao()}: {$leccionacao->disciplina->nome} à {$horario->quando()}"
                .($sala !== null ? " na sala {$sala->codigo}" : ''));

        return $horario;
    }

    public function remover(Horario $horario): void
    {
        $horario->loadMissing(['leccionacao.turma', 'leccionacao.disciplina']);
        $l = $horario->leccionacao;
        $horario->delete();
        activity('docencia')->performedOn($l->turma)
            ->log("Horário da turma {$l->turma->designacao()}: retirado {$l->disciplina->nome} de {$horario->quando()}");
    }

    /**
     * Antes de dar a disciplina a outro docente: os tempos dela não podem chocar com os que ele já tem.
     */
    public function exigirDocenteLivre(Leccionacao $leccionacao, Docente $docente): void
    {
        foreach ($leccionacao->horarios()->with('sala')->get() as $h) {
            $choque = $this->doDocente($leccionacao, $docente, $h->dia, $h->inicio, $h->fim)->first();
            if ($choque !== null) {
                throw ValidationException::withMessages(['docente_id' => "{$docente->nome} já dá "
                    .$this->descrever($choque)." e esta disciplina é à {$h->quando()}."]);
            }
        }
    }

    /**
     * O horário de um docente no período: todos os tempos das disciplinas que lhe foram distribuídas.
     *
     * @return Collection<int, Horario>
     */
    public function doDocenteNoPeriodo(Docente $docente, int $anoLectivoId, ?int $periodoId): Collection
    {
        return Horario::with(['leccionacao.disciplina', 'leccionacao.turma', 'leccionacao.docente', 'sala'])
            ->whereHas('leccionacao', fn (Builder $l) => $l->where('docente_id', $docente->id)
                ->whereHas('turma', fn (Builder $t) => $this->doPeriodo($t, $anoLectivoId, $periodoId)))
            ->orderBy('dia')->orderBy('inicio')->get();
    }

    private function validar(int $dia, string $inicio, string $fim, ?Sala $sala): void
    {
        if (! array_key_exists($dia, Horario::DIAS)) {
            throw ValidationException::withMessages(['dia' => 'Escolha o dia da semana.']);
        }
        foreach (['inicio' => $inicio, 'fim' => $fim] as $campo => $hora) {
            if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora) !== 1) {
                throw ValidationException::withMessages([$campo => 'Escreva a hora assim: 08:00.']);
            }
        }
        if ($inicio < self::PRIMEIRA_HORA || $fim > self::ULTIMA_HORA) {
            throw ValidationException::withMessages(['inicio' => 'As aulas são entre as '.self::PRIMEIRA_HORA.' e as '.self::ULTIMA_HORA.'.']);
        }
        if ($this->minutos($fim) - $this->minutos($inicio) < 30) {
            throw ValidationException::withMessages(['fim' => 'A aula acaba pelo menos 30 minutos depois de começar.']);
        }
        if ($sala !== null && $sala->activo === false) {
            throw ValidationException::withMessages(['sala_id' => "A sala {$sala->codigo} está desactivada."]);
        }
    }

    private function exigirLivre(Leccionacao $leccionacao, ?Docente $docente, int $dia, string $inicio, string $fim, ?Sala $sala): void
    {
        $turma = $leccionacao->turma;
        $daTurma = $this->sobrepostos($turma, $dia, $inicio, $fim)
            ->whereHas('leccionacao', fn (Builder $l) => $l->where('turma_id', $turma->id))->first();
        if ($daTurma !== null) {
            throw ValidationException::withMessages(['inicio' => "A turma {$turma->designacao()} já tem "
                .$daTurma->leccionacao->disciplina->nome." à {$daTurma->quando()}."]);
        }
        if ($docente !== null) {
            $doDocente = $this->doDocente($leccionacao, $docente, $dia, $inicio, $fim)->first();
            if ($doDocente !== null) {
                throw ValidationException::withMessages(['inicio' => "{$docente->nome} já dá ".$this->descrever($doDocente).'.']);
            }
        }
        if ($sala !== null) {
            $naSala = $this->sobrepostos($turma, $dia, $inicio, $fim)->where('sala_id', $sala->id)->first();
            if ($naSala !== null) {
                throw ValidationException::withMessages(['sala_id' => "A sala {$sala->codigo} está ocupada: ".$this->descrever($naSala).'.']);
            }
        }
    }

    /** @return Builder<Horario> */
    private function doDocente(Leccionacao $leccionacao, Docente $docente, int $dia, string $inicio, string $fim): Builder
    {
        return $this->sobrepostos($leccionacao->turma, $dia, $inicio, $fim)
            ->whereHas('leccionacao', fn (Builder $l) => $l->where('docente_id', $docente->id)->where('id', '!=', $leccionacao->id));
    }

    /**
     * Os tempos do mesmo período lectivo da turma que se sobrepõem às horas dadas.
     *
     * @return Builder<Horario>
     */
    private function sobrepostos(Turma $turma, int $dia, string $inicio, string $fim): Builder
    {
        return Horario::query()
            ->with(['leccionacao.disciplina', 'leccionacao.turma', 'sala'])
            ->where('dia', $dia)
            ->where('inicio', '<', $fim)
            ->where('fim', '>', $inicio)
            ->whereHas('leccionacao.turma', fn (Builder $t) => $this->doPeriodo($t, $turma->ano_lectivo_id, $turma->periodo_id));
    }

    /**
     * No ensino geral a turma é do ano todo (sem período): choca com as do ano todo e com as de qualquer período.
     *
     * @param  Builder<Turma>  $t
     * @return Builder<Turma>
     */
    private function doPeriodo(Builder $t, int $anoLectivoId, ?int $periodoId): Builder
    {
        return $t->where('ano_lectivo_id', $anoLectivoId)
            ->when($periodoId !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('periodo_id', $periodoId)->orWhereNull('periodo_id')));
    }

    /** "Matemática I à turma 1.º A, segunda, das 08:00 às 10:00" */
    private function descrever(Horario $h): string
    {
        return "{$h->leccionacao->disciplina->nome} à turma {$h->leccionacao->turma->designacao()}, {$h->quando()}";
    }

    private function minutos(string $hora): int
    {
        [$h, $m] = array_map('intval', explode(':', $hora));

        return $h * 60 + $m;
    }
}
