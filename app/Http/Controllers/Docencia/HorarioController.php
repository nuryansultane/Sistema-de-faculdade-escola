<?php

namespace App\Http\Controllers\Docencia;

use App\Http\Controllers\Controller;
use App\Models\Horario;
use App\Models\Leccionacao;
use App\Models\Sala;
use App\Models\Turma;
use App\Services\HorarioService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Os tempos do horário semanal de uma turma (montado na ficha da turma). */
class HorarioController extends Controller
{
    public function __construct(private readonly HorarioService $servico) {}

    public function store(Request $request, Turma $turma): RedirectResponse
    {
        $v = $request->validate([
            'leccionacao_id' => ['required', 'integer', Rule::exists('leccionacoes', 'id')->where('turma_id', $turma->id)],
            'dia' => ['required', 'integer', 'between:1,6'],
            'inicio' => ['required', 'string'],
            'fim' => ['required', 'string'],
            'sala_id' => ['nullable', 'integer', 'exists:salas,id'],
        ], ['leccionacao_id.required' => 'Escolha a disciplina.'], ['leccionacao_id' => 'disciplina', 'inicio' => 'início', 'sala_id' => 'sala']);

        $horario = $this->servico->acrescentar(
            Leccionacao::findOrFail((int) $v['leccionacao_id']),
            (int) $v['dia'],
            substr((string) $v['inicio'], 0, 5),
            substr((string) $v['fim'], 0, 5),
            isset($v['sala_id']) ? Sala::find((int) $v['sala_id']) : null,
        );

        return back()->with('sucesso', "{$horario->leccionacao->disciplina->nome} à {$horario->quando()}.");
    }

    public function destroy(Horario $horario): RedirectResponse
    {
        $this->servico->remover($horario);

        return back()->with('sucesso', 'Tempo retirado do horário.');
    }
}
