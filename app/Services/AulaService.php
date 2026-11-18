<?php

namespace App\Services;

use App\Models\Aula;
use App\Models\Docente;
use App\Models\Horario;
use App\Models\Inscricao;
use App\Models\Leccionacao;
use App\Models\Presenca;
use App\Models\User;
use App\Support\Escala;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sumários e presenças (RF-60, RF-61). Só o docente da disciplina regista as suas aulas (como nas notas, RN-10).
 * Cada aula leva o sumário e a presença de todos os estudantes inscritos na disciplina dessa turma: quem não é
 * marcado fica presente. A secretaria (ou o director de curso) justifica faltas, com o motivo; a falta justificada
 * não conta para a assiduidade, que tem de chegar ao mínimo da Configuração (assiduidade_minima, em %).
 */
class AulaService
{
    public function podeRegistar(User $user, Leccionacao $leccionacao): bool
    {
        if ($user->isAdministrador()) {
            return true;
        }

        return $user->can('aulas.registar') && $leccionacao->docente_id !== null
            && Docente::where('user_id', $user->id)->where('id', $leccionacao->docente_id)->exists();
    }

    public function podeVer(User $user, Leccionacao $leccionacao): bool
    {
        return $user->can('turmas.ver') || $user->can('presencas.justificar') || $this->podeRegistar($user, $leccionacao);
    }

    /**
     * @param  array<int|string, string>  $estados  inscrição → presente, atraso ou falta (quem falta na lista está presente)
     */
    public function registar(Leccionacao $leccionacao, CarbonImmutable $data, string $inicio, string $fim, string $sumario, array $estados, User $autor): Aula
    {
        $leccionacao->loadMissing(['turma.periodo', 'turma.anoLectivo', 'disciplina']);
        $this->validar($leccionacao, $data, $inicio, $fim, $sumario);
        if (Aula::where('leccionacao_id', $leccionacao->id)->whereDate('data', $data->toDateString())->where('inicio', $inicio)->exists()) {
            throw ValidationException::withMessages(['inicio' => "Já há uma aula registada a {$data->format('d/m/Y')} às {$inicio}: altere essa."]);
        }

        return DB::transaction(function () use ($leccionacao, $data, $inicio, $fim, $sumario, $estados, $autor) {
            $aula = Aula::create([
                'leccionacao_id' => $leccionacao->id,
                'data' => $data->toDateString(),
                'inicio' => $inicio,
                'fim' => $fim,
                'sumario' => trim($sumario),
                'registada_por' => $autor->id,
            ]);
            $faltas = $this->marcar($aula, $leccionacao, $estados);
            activity('docencia')->performedOn($aula)
                ->log("Aula de {$leccionacao->disciplina->nome} ({$leccionacao->turma->designacao()}) a {$data->format('d/m/Y')}: {$faltas} ".($faltas === 1 ? 'falta' : 'faltas'));

            return $aula;
        });
    }

    /**
     * Corrige o sumário e as presenças. As faltas que já tinham sido justificadas continuam justificadas.
     *
     * @param  array<int|string, string>  $estados
     */
    public function alterar(Aula $aula, string $sumario, array $estados): void
    {
        if (mb_strlen(trim($sumario)) < 3) {
            throw ValidationException::withMessages(['sumario' => 'Escreva o sumário da aula.']);
        }
        DB::transaction(function () use ($aula, $sumario, $estados) {
            $aula->update(['sumario' => trim($sumario)]);
            $faltas = $this->marcar($aula, $aula->leccionacao, $estados);
            activity('docencia')->performedOn($aula)
                ->log("Aula de {$aula->data->format('d/m/Y')} alterada: {$faltas} ".($faltas === 1 ? 'falta' : 'faltas'));
        });
    }

    public function justificar(Presenca $presenca, string $motivo, User $autor): void
    {
        if ($presenca->estado !== Presenca::FALTA) {
            throw ValidationException::withMessages(['justificacao' => 'Só se justificam faltas.']);
        }
        if ($presenca->justificada) {
            throw ValidationException::withMessages(['justificacao' => 'Esta falta já está justificada.']);
        }
        $presenca->update(['justificada' => true, 'justificacao' => trim($motivo), 'justificada_por' => $autor->id, 'justificada_em' => now()]);
        $presenca->loadMissing(['inscricao.estudante', 'aula']);
        activity('docencia')->performedOn($presenca->aula)->withProperties(['motivo' => $motivo])
            ->log("Falta justificada: {$presenca->inscricao->estudante->nome} ({$presenca->inscricao->estudante->numero}), aula de {$presenca->aula->data->format('d/m/Y')}");
    }

    /**
     * A assiduidade de cada estudante da disciplina: aulas, presenças, atrasos, faltas (e quantas justificadas) e a
     * percentagem, que conta como presença o atraso e a falta justificada.
     *
     * @return Collection<int, array{aulas: int, presencas: int, atrasos: int, faltas: int, justificadas: int, percentagem: int|null, abaixo: bool}>
     */
    public function assiduidade(Leccionacao $leccionacao): Collection
    {
        $aulas = Aula::where('leccionacao_id', $leccionacao->id)->count();
        $minima = (int) Escala::parametro('assiduidade_minima');
        $contagens = Presenca::query()
            ->whereHas('aula', fn ($q) => $q->where('leccionacao_id', $leccionacao->id))
            ->get()->groupBy('inscricao_id');

        return $leccionacao->inscricoes()->get()->mapWithKeys(function (Inscricao $i) use ($contagens, $aulas, $minima) {
            /** @var Collection<int, Presenca> $minhas */
            $minhas = $contagens->get($i->id) ?? collect();
            $faltas = $minhas->where('estado', Presenca::FALTA);
            $injustificadas = $faltas->where('justificada', false)->count();
            $percentagem = $aulas === 0 ? null : (int) floor(($aulas - $injustificadas) * 100 / $aulas);

            return [$i->id => [
                'aulas' => $aulas,
                'presencas' => $minhas->where('estado', Presenca::PRESENTE)->count(),
                'atrasos' => $minhas->where('estado', Presenca::ATRASO)->count(),
                'faltas' => $faltas->count(),
                'justificadas' => $faltas->count() - $injustificadas,
                'percentagem' => $percentagem,
                'abaixo' => $percentagem !== null && $percentagem < $minima,
            ]];
        });
    }

    /** As horas do horário dessa disciplina nesse dia da semana, para sugerir ao registar a aula. */
    public function horasDoDia(Leccionacao $leccionacao, CarbonImmutable $data): ?Horario
    {
        return Horario::where('leccionacao_id', $leccionacao->id)->where('dia', $data->dayOfWeekIso)->orderBy('inicio')->first();
    }

    /** @param  array<int|string, string>  $estados */
    private function marcar(Aula $aula, Leccionacao $leccionacao, array $estados): int
    {
        $faltas = 0;
        foreach ($leccionacao->inscricoes()->get() as $inscricao) {
            $estado = $estados[$inscricao->id] ?? Presenca::PRESENTE;
            if (! array_key_exists($estado, Presenca::ESTADOS)) {
                throw ValidationException::withMessages(['presencas' => 'Marque cada estudante como presente, atrasado ou falta.']);
            }
            $presenca = Presenca::firstOrNew(['aula_id' => $aula->id, 'inscricao_id' => $inscricao->id]);
            if ($presenca->estado !== $estado) {
                // Deixa de ser falta: a justificação deixa de fazer sentido
                $presenca->fill(['estado' => $estado] + ($estado !== Presenca::FALTA ? ['justificada' => false, 'justificacao' => null, 'justificada_por' => null, 'justificada_em' => null] : []));
            }
            $presenca->save();
            $faltas += $estado === Presenca::FALTA ? 1 : 0;
        }

        return $faltas;
    }

    private function validar(Leccionacao $leccionacao, CarbonImmutable $data, string $inicio, string $fim, string $sumario): void
    {
        if ($data->isAfter(now()->endOfDay())) {
            throw ValidationException::withMessages(['data' => 'Só se registam aulas já dadas: a data não pode ser no futuro.']);
        }
        $turma = $leccionacao->turma;
        $de = $turma->periodo->inicio ?? $turma->anoLectivo->inicio;
        $ate = $turma->periodo->fim ?? $turma->anoLectivo->fim;
        if ($data->isBefore($de->copy()->startOfDay()) || $data->isAfter($ate->copy()->endOfDay())) {
            throw ValidationException::withMessages(['data' => 'A data tem de ser dentro do '.($turma->periodo?->nome() ?? 'ano lectivo')
                ." da turma ({$de->format('d/m/Y')} a {$ate->format('d/m/Y')})."]);
        }
        foreach (['inicio' => $inicio, 'fim' => $fim] as $campo => $hora) {
            if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora) !== 1) {
                throw ValidationException::withMessages([$campo => 'Escreva a hora assim: 08:00.']);
            }
        }
        if ($fim <= $inicio) {
            throw ValidationException::withMessages(['fim' => 'A aula acaba depois de começar.']);
        }
        if (mb_strlen(trim($sumario)) < 3) {
            throw ValidationException::withMessages(['sumario' => 'Escreva o sumário da aula.']);
        }
    }
}
