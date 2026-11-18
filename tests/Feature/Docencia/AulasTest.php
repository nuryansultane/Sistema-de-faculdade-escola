<?php

namespace Tests\Feature\Docencia;

use App\Models\AnoLectivo;
use App\Models\Aula;
use App\Models\ContaPortal;
use App\Models\Docente;
use App\Models\Inscricao;
use App\Models\Leccionacao;
use App\Models\Presenca;
use App\Models\Turma;
use App\Models\User;
use App\Services\HorarioService;
use App\Services\InscricaoService;
use App\Services\MatriculaService;
use App\Services\TurmaService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Estudantes\Cenario;
use Tests\TestCase;

class AulasTest extends TestCase
{
    use Cenario, RefreshDatabase;

    private AnoLectivo $ano;

    private Turma $turma;

    private Leccionacao $prog1;

    private User $docente;

    /** @var array<string, Inscricao> */
    private array $inscricoes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-03-10 10:00'); // uma quarta-feira do 1.º semestre
        CarbonImmutable::setTestNow('2027-03-10 10:00');
        $this->montarCurso();
        $this->ano = $this->anoLectivo('2027');
        $turmas = app(TurmaService::class);
        $this->turma = $turmas->criar($this->ano, $this->ano->periodos[0], $this->curso, 1, 'A', null, 40);
        $this->docente = $this->utilizadorComPerfil('Docente');
        $this->prog1 = $this->turma->leccionacoes()->where('disciplina_id', $this->disciplinas['prog1']->id)->firstOrFail();
        $turmas->atribuirDocente($this->prog1, Docente::create(['nome' => 'Carlos Mondlane', 'user_id' => $this->docente->id]));

        foreach (['ana' => 'Ana Macuácua', 'bento' => 'Bento Chissano'] as $chave => $nome) {
            $matricula = app(MatriculaService::class)->matricular($this->estudante($nome), $this->curso, $this->ano, 1, 'laboral', $this->admin);
            app(InscricaoService::class)->inscrever($matricula, $this->ano->periodos[0], $this->ids('prog1', 'mat1'), $this->admin);
        }
        $turmas->distribuir($this->turma);
        foreach (Inscricao::with('estudante')->where('leccionacao_id', $this->prog1->id)->get() as $i) {
            $this->inscricoes[str_starts_with($i->estudante->nome, 'Ana') ? 'ana' : 'bento'] = $i;
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, string> $marcas */
    private function registar(string $data, array $marcas = [], string $inicio = '08:00', ?User $quem = null): TestResponse
    {
        $presencas = [];
        foreach ($marcas as $chave => $estado) {
            $presencas[$this->inscricoes[$chave]->id] = $estado;
        }

        return $this->actingAs($quem ?? $this->docente)->post(route('aulas.store', $this->prog1), [
            'data' => $data, 'inicio' => $inicio, 'fim' => '10:00', 'sumario' => 'Estruturas de repetição: for e while.', 'presencas' => $presencas,
        ]);
    }

    public function test_o_docente_regista_a_aula_com_o_sumario_e_as_faltas(): void
    {
        $this->registar('2027-03-09', ['bento' => 'falta'])->assertSessionHasNoErrors();
        $aula = Aula::firstOrFail();
        $this->assertSame('Estruturas de repetição: for e while.', $aula->sumario);
        $this->assertSame(Presenca::PRESENTE, Presenca::where('inscricao_id', $this->inscricoes['ana']->id)->value('estado'));
        $this->assertSame(Presenca::FALTA, Presenca::where('inscricao_id', $this->inscricoes['bento']->id)->value('estado'));

        $this->get(route('aulas.index', $this->prog1))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('docencia/aulas')
            ->where('podeRegistar', true)
            ->has('aulas', 1)
            ->where('aulas.0.faltas', 1)
            ->where('estudantes.0.nome', 'Ana Macuácua')
            ->where('estudantes.0.assiduidade.percentagem', 100)
            ->where('estudantes.1.assiduidade.percentagem', 0)
            ->where('estudantes.1.assiduidade.abaixo', true));

        // A mesma aula não se regista duas vezes; aulas futuras ou fora do semestre não entram
        $this->registar('2027-03-09')->assertSessionHasErrors('inicio');
        $this->registar('2027-03-11')->assertSessionHasErrors('data');
        $this->registar('2027-01-15')->assertSessionHasErrors('data');
        $this->actingAs($this->docente)->post(route('aulas.store', $this->prog1), [
            'data' => '2027-03-08', 'inicio' => '08:00', 'fim' => '10:00', 'sumario' => '',
        ])->assertSessionHasErrors('sumario');
        $this->registar('2027-03-08', ['ana' => 'ausente'])->assertSessionHasErrors('presencas');
        $this->assertSame(1, Aula::count());
    }

    public function test_so_o_docente_da_disciplina_regista_e_quem_ve_as_turmas_consulta(): void
    {
        $outro = $this->utilizadorComPerfil('Docente');
        Docente::create(['nome' => 'Rosa Bila', 'user_id' => $outro->id]);
        $this->registar('2027-03-09', [], '08:00', $outro)->assertForbidden();
        $this->actingAs($outro)->get(route('aulas.index', $this->prog1))->assertForbidden();

        $secretaria = $this->utilizadorComPerfil('Secretaria académica');
        $this->actingAs($secretaria)->get(route('aulas.index', $this->prog1))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('podeRegistar', false)->where('podeJustificar', true));
        $this->registar('2027-03-09', [], '08:00', $secretaria)->assertForbidden();

        $this->actingAs($this->utilizadorComPerfil('Tesouraria'))->get(route('aulas.index', $this->prog1))->assertForbidden();
    }

    public function test_a_secretaria_justifica_a_falta_e_deixa_de_contar(): void
    {
        $this->registar('2027-03-09', ['bento' => 'falta']);
        $falta = Presenca::where('inscricao_id', $this->inscricoes['bento']->id)->firstOrFail();

        // O docente regista, mas não justifica
        $this->actingAs($this->docente)->post(route('presencas.justificar', $falta), ['justificacao' => 'Doente'])->assertForbidden();

        $secretaria = $this->utilizadorComPerfil('Secretaria académica');
        $this->actingAs($secretaria)->post(route('presencas.justificar', $falta), ['justificacao' => ''])->assertSessionHasErrors('justificacao');
        $this->post(route('presencas.justificar', $falta), ['justificacao' => 'Atestado médico de 09/03'])->assertSessionHasNoErrors();
        $this->assertTrue($falta->fresh()?->justificada);
        $this->post(route('presencas.justificar', $falta), ['justificacao' => 'outra vez'])->assertSessionHasErrors('justificacao');

        $this->get(route('aulas.index', $this->prog1))->assertInertia(fn (Assert $page) => $page
            ->where('estudantes.1.assiduidade.faltas', 1)
            ->where('estudantes.1.assiduidade.justificadas', 1)
            ->where('estudantes.1.assiduidade.percentagem', 100));

        // O docente corrige a aula: o Bento afinal estava, a Ana chegou atrasada
        $aula = Aula::firstOrFail();
        $this->actingAs($this->docente)->put(route('aulas.update', $aula), [
            'sumario' => 'Estruturas de repetição: for, while e do-while.',
            'presencas' => [$this->inscricoes['bento']->id => 'presente', $this->inscricoes['ana']->id => 'atraso'],
        ])->assertSessionHasNoErrors();
        $falta->refresh();
        $this->assertSame(Presenca::PRESENTE, $falta->estado);
        $this->assertFalse($falta->justificada);
        $this->assertNull($falta->justificacao);
        $this->get(route('aulas.index', $this->prog1))->assertInertia(fn (Assert $page) => $page
            ->where('aulas.0.sumario', 'Estruturas de repetição: for, while e do-while.')
            ->where('estudantes.0.assiduidade.atrasos', 1)
            ->where('estudantes.0.assiduidade.percentagem', 100));
    }

    public function test_as_horas_vem_do_horario_e_o_estudante_ve_as_faltas_no_portal(): void
    {
        app(HorarioService::class)->acrescentar($this->prog1, 3, '14:00', '16:00', null); // quarta-feira
        $this->actingAs($this->docente)->get(route('aulas.index', $this->prog1))->assertInertia(fn (Assert $page) => $page
            ->where('sugestao.data', '2027-03-10')
            ->where('sugestao.inicio', '14:00')
            ->where('sugestao.fim', '16:00'));

        $this->registar('2027-03-09', ['bento' => 'falta']);
        $conta = ContaPortal::create(['estudante_id' => $this->inscricoes['bento']->estudante_id, 'password' => 'senha-de-teste', 'estado' => 'activa']);
        $this->actingAs($conta, 'estudante')->get('/portal')->assertInertia(fn (Assert $page) => $page
            ->where('matriculas.0.inscricoes.0.disciplinas.1.nome', 'Programação I')
            ->where('matriculas.0.inscricoes.0.disciplinas.1.faltas', 1)
            ->where('matriculas.0.inscricoes.0.disciplinas.0.faltas', 0));
    }
}
