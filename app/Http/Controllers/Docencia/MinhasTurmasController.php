<?php

namespace App\Http\Controllers\Docencia;

use App\Http\Controllers\Controller;
use App\Models\AnoLectivo;
use App\Models\Docente;
use App\Models\Horario;
use App\Models\Leccionacao;
use App\Models\User;
use App\Services\HorarioService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** O docente vê as disciplinas e turmas que lhe foram distribuídas (é aqui que vão entrar as pautas e as presenças). */
class MinhasTurmasController extends Controller
{
    public function __invoke(Request $request, HorarioService $horarios): Response
    {
        /** @var User $user */
        $user = $request->user();
        $docente = Docente::where('user_id', $user->id)->first();

        $leccionacoes = $docente === null ? collect() : $docente->leccionacoes()
            ->with(['disciplina', 'turma.curso', 'turma.periodo', 'turma.anoLectivo'])
            ->withCount('inscricoes')
            ->get()
            ->sortBy(fn (Leccionacao $l) => [-$l->turma->anoLectivo->inicio->timestamp, $l->turma->periodo->numero ?? 0, $l->disciplina->nome])
            ->values();

        // O horário da semana: as disciplinas do período em curso
        $ano = AnoLectivo::emCurso();
        $horario = $docente === null || $ano === null ? collect() : $horarios->doDocenteNoPeriodo($docente, $ano->id, $ano->periodoEmCurso()?->id);

        return Inertia::render('docencia/minhas-turmas', [
            'horario' => $horario->map(fn (Horario $h) => $h->paraGrelha())->values(),
            'docente' => $docente?->only(['id', 'nome']),
            'leccionacoes' => $leccionacoes->map(fn (Leccionacao $l) => [
                'id' => $l->id,
                'disciplina' => $l->disciplina->nome,
                'codigo' => $l->disciplina->codigo,
                'turma' => $l->turma->designacao(),
                'turma_id' => $l->turma_id,
                'curso' => $l->turma->curso->nome,
                'ano_lectivo' => $l->turma->anoLectivo->nome,
                'periodo' => $l->turma->periodo?->nome() ?? 'Ano lectivo',
                'estudantes' => (int) $l->inscricoes_count,
            ])->values(),
        ]);
    }
}
