<?php

namespace App\Http\Controllers\Docencia;

use App\Http\Controllers\Controller;
use App\Models\Aula;
use App\Models\Inscricao;
use App\Models\Leccionacao;
use App\Models\Presenca;
use App\Models\User;
use App\Services\AulaService;
use App\Support\Escala;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Sumários e presenças de uma disciplina de uma turma: o docente regista as aulas, a secretaria justifica faltas. */
class AulaController extends Controller
{
    public function __construct(private readonly AulaService $servico) {}

    public function index(Request $request, Leccionacao $leccionacao): Response
    {
        $user = $this->utilizador($request);
        abort_unless($this->servico->podeVer($user, $leccionacao), 403);
        $leccionacao->load(['disciplina', 'docente', 'turma.curso', 'turma.periodo', 'turma.anoLectivo']);
        $inscricoes = $leccionacao->inscricoes()->with('estudante')->get()
            ->sortBy(fn (Inscricao $i) => mb_strtolower($i->estudante->nome))->values();
        $aulas = $leccionacao->aulas()->with(['presencas', 'registadaPor'])->get();
        $assiduidade = $this->servico->assiduidade($leccionacao);
        $hoje = CarbonImmutable::today();
        $sugestao = $this->servico->horasDoDia($leccionacao, $hoje);

        return Inertia::render('docencia/aulas', [
            'leccionacao' => [
                'id' => $leccionacao->id,
                'disciplina' => $leccionacao->disciplina->nome,
                'codigo' => $leccionacao->disciplina->codigo,
                'turma' => $leccionacao->turma->designacao(),
                'turma_id' => $leccionacao->turma_id,
                'curso' => $leccionacao->turma->curso->nome,
                'periodo' => $leccionacao->turma->periodo?->nome() ?? 'Ano lectivo',
                'ano_lectivo' => $leccionacao->turma->anoLectivo->nome,
                'docente' => $leccionacao->docente?->nome,
            ],
            'estudantes' => $inscricoes->map(fn (Inscricao $i) => [
                'inscricao_id' => $i->id,
                'numero' => $i->estudante->numero,
                'nome' => $i->estudante->nome,
                'em_atraso' => $i->em_atraso,
                'assiduidade' => $assiduidade->get($i->id),
            ])->values(),
            'aulas' => $aulas->map(fn (Aula $a) => [
                'id' => $a->id,
                'data' => $a->data->toDateString(),
                'inicio' => $a->inicio,
                'fim' => $a->fim,
                'sumario' => $a->sumario,
                'registada_por' => $a->registadaPor?->name,
                'faltas' => $a->presencas->where('estado', Presenca::FALTA)->count(),
                'presencas' => $a->presencas->mapWithKeys(fn (Presenca $p) => [(string) $p->inscricao_id => [
                    'id' => $p->id,
                    'estado' => $p->estado,
                    'justificada' => $p->justificada,
                    'justificacao' => $p->justificacao,
                ]])->all(),
            ])->values(),
            'sugestao' => [
                'data' => $hoje->toDateString(),
                'inicio' => $sugestao->inicio ?? '',
                'fim' => $sugestao->fim ?? '',
            ],
            'minima' => (int) Escala::parametro('assiduidade_minima'),
            'podeRegistar' => $this->servico->podeRegistar($user, $leccionacao),
            'podeJustificar' => $user->can('presencas.justificar'),
        ]);
    }

    public function store(Request $request, Leccionacao $leccionacao): RedirectResponse
    {
        $user = $this->utilizador($request);
        abort_unless($this->servico->podeRegistar($user, $leccionacao), 403);
        $v = $this->validar($request, true);
        $aula = $this->servico->registar(
            $leccionacao,
            CarbonImmutable::parse((string) $v['data']),
            substr((string) $v['inicio'], 0, 5),
            substr((string) $v['fim'], 0, 5),
            (string) $v['sumario'],
            $this->estados($v),
            $user,
        );

        return back()->with('sucesso', "Aula de {$aula->data->format('d/m/Y')} registada.");
    }

    public function update(Request $request, Aula $aula): RedirectResponse
    {
        abort_unless($this->servico->podeRegistar($this->utilizador($request), $aula->leccionacao), 403);
        $v = $this->validar($request, false);
        $this->servico->alterar($aula, (string) $v['sumario'], $this->estados($v));

        return back()->with('sucesso', "Aula de {$aula->data->format('d/m/Y')} alterada.");
    }

    public function justificar(Request $request, Presenca $presenca): RedirectResponse
    {
        $v = $request->validate(['justificacao' => ['required', 'string', 'max:300']], ['justificacao.required' => 'Escreva o motivo (atestado médico, serviço, …).']);
        $this->servico->justificar($presenca, (string) $v['justificacao'], $this->utilizador($request));

        return back()->with('sucesso', 'Falta justificada.');
    }

    /** @return array<string, mixed> */
    private function validar(Request $request, bool $nova): array
    {
        return $request->validate([
            ...($nova ? [
                'data' => ['required', 'date'],
                'inicio' => ['required', 'string'],
                'fim' => ['required', 'string'],
            ] : []),
            'sumario' => ['required', 'string', 'max:2000'],
            'presencas' => ['nullable', 'array'],
            'presencas.*' => ['string'],
        ], ['sumario.required' => 'Escreva o sumário da aula.'], ['sumario' => 'sumário', 'inicio' => 'início']);
    }

    /**
     * @param  array<string, mixed>  $v
     * @return array<int|string, string>
     */
    private function estados(array $v): array
    {
        $estados = [];
        foreach ((array) ($v['presencas'] ?? []) as $inscricao => $estado) {
            $estados[(int) $inscricao] = (string) $estado;
        }

        return $estados;
    }

    private function utilizador(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
