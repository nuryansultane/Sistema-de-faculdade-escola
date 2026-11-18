<?php

namespace Tests\Feature\Docencia;

use App\Models\AnoLectivo;
use App\Models\ContaPortal;
use App\Models\Docente;
use App\Models\Horario;
use App\Models\Leccionacao;
use App\Models\Sala;
use App\Models\Turma;
use App\Models\User;
use App\Services\InscricaoService;
use App\Services\MatriculaService;
use App\Services\TurmaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Estudantes\Cenario;
use Tests\TestCase;

class HorariosTest extends TestCase
{
    use Cenario, RefreshDatabase;

    private AnoLectivo $ano;

    private Turma $a;

    private Turma $b;

    private Docente $carlos;

    private User $secretaria;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCurso();
        $this->ano = $this->anoLectivo('2027');
        $turmas = app(TurmaService::class);
        $this->a = $turmas->criar($this->ano, $this->ano->periodos[0], $this->curso, 1, 'A', null, 40);
        $this->b = $turmas->criar($this->ano, $this->ano->periodos[0], $this->curso, 1, 'B', null, 40);
        $this->carlos = Docente::create(['nome' => 'Carlos Mondlane', 'user_id' => $this->utilizadorComPerfil('Docente')->id]);
        $turmas->atribuirDocente($this->disciplina($this->a, 'prog1'), $this->carlos);
        $turmas->atribuirDocente($this->disciplina($this->b, 'prog1'), $this->carlos);
        Sala::create(['codigo' => '101']);
        Sala::create(['codigo' => '102']);
        $this->secretaria = $this->utilizadorComPerfil('Secretaria académica');
    }

    private function disciplina(Turma $turma, string $chave): Leccionacao
    {
        return $turma->leccionacoes()->where('disciplina_id', $this->disciplinas[$chave]->id)->firstOrFail();
    }

    private function sala(string $codigo): int
    {
        return Sala::where('codigo', $codigo)->firstOrFail()->id;
    }

    private function tempo(Turma $turma, string $chave, int $dia, string $inicio, string $fim, ?string $sala = null): TestResponse
    {
        return $this->actingAs($this->secretaria)->post(route('horarios.store', $turma), [
            'leccionacao_id' => $this->disciplina($turma, $chave)->id,
            'dia' => $dia, 'inicio' => $inicio, 'fim' => $fim,
            'sala_id' => $sala !== null ? $this->sala($sala) : null,
        ]);
    }

    public function test_a_turma_nao_tem_duas_aulas_ao_mesmo_tempo(): void
    {
        $this->tempo($this->a, 'prog1', 1, '08:00', '10:00', '101')->assertSessionHasNoErrors();
        $this->tempo($this->a, 'mat1', 1, '09:00', '11:00')
            ->assertSessionHasErrors(['inicio' => 'A turma 1.º A já tem Programação I à segunda, das 08:00 às 10:00.']);

        // Encostadas não se sobrepõem; noutro dia também não
        $this->tempo($this->a, 'mat1', 1, '10:00', '12:00')->assertSessionHasNoErrors();
        $this->tempo($this->a, 'mat1', 3, '08:00', '10:00')->assertSessionHasNoErrors();
        $this->assertSame(3, Horario::count());
        $this->assertDatabaseHas('activity_log', ['log_name' => 'docencia', 'description' => 'Horário da turma 1.º A: Programação I à segunda, das 08:00 às 10:00 na sala 101']);

        $this->get(route('turmas.show', $this->a))->assertInertia(fn (Assert $page) => $page
            ->has('horario', 3)
            ->where('horario.0.disciplina', 'Programação I')
            ->where('horario.0.docente', 'Carlos Mondlane')
            ->where('horario.0.sala', '101'));
    }

    public function test_o_docente_e_a_sala_nao_estao_em_dois_sitios(): void
    {
        $this->tempo($this->a, 'prog1', 1, '08:00', '10:00', '101')->assertSessionHasNoErrors();

        // Carlos dá Programação I nas duas turmas
        $this->tempo($this->b, 'prog1', 1, '09:30', '11:00')
            ->assertSessionHasErrors(['inicio' => 'Carlos Mondlane já dá Programação I à turma 1.º A, segunda, das 08:00 às 10:00.']);
        // A sala 101 está ocupada à mesma hora
        $this->tempo($this->b, 'mat1', 1, '08:00', '10:00', '101')
            ->assertSessionHasErrors(['sala_id' => 'A sala 101 está ocupada: Programação I à turma 1.º A, segunda, das 08:00 às 10:00.']);
        $this->tempo($this->b, 'mat1', 1, '08:00', '10:00', '102')->assertSessionHasNoErrors();

        // No 2.º semestre, a mesma hora está livre
        $segundo = app(TurmaService::class)->criar($this->ano, $this->ano->periodos[1], $this->curso, 1, 'A', null, 40);
        $this->tempo($segundo, 'prog2', 1, '08:00', '10:00', '101')->assertSessionHasNoErrors();
    }

    public function test_dar_a_disciplina_a_um_docente_ocupado_a_essa_hora_nao_entra(): void
    {
        $this->tempo($this->a, 'prog1', 2, '14:00', '16:00')->assertSessionHasNoErrors();
        $this->tempo($this->b, 'mat1', 2, '15:00', '17:00')->assertSessionHasNoErrors();

        $this->actingAs($this->administrador())
            ->put(route('leccionacoes.docente', $this->disciplina($this->b, 'mat1')), ['docente_id' => $this->carlos->id])
            ->assertSessionHasErrors(['docente_id' => 'Carlos Mondlane já dá Programação I à turma 1.º A, terça, das 14:00 às 16:00 e esta disciplina é à terça, das 15:00 às 17:00.']);
        $this->assertNull($this->disciplina($this->b, 'mat1')->docente_id);
    }

    public function test_horas_validas_e_disciplina_da_propria_turma(): void
    {
        $this->tempo($this->a, 'prog1', 1, '10:00', '09:00')->assertSessionHasErrors('fim');
        $this->tempo($this->a, 'prog1', 1, '10:00', '10:15')->assertSessionHasErrors('fim');
        $this->tempo($this->a, 'prog1', 1, '05:00', '07:00')->assertSessionHasErrors('inicio');
        $this->tempo($this->a, 'prog1', 1, '8h', '10:00')->assertSessionHasErrors('inicio');
        $this->tempo($this->a, 'prog1', 7, '08:00', '10:00')->assertSessionHasErrors('dia');
        $this->actingAs($this->secretaria)->post(route('horarios.store', $this->a), [
            'leccionacao_id' => $this->disciplina($this->b, 'prog1')->id, 'dia' => 1, 'inicio' => '08:00', 'fim' => '10:00',
        ])->assertSessionHasErrors('leccionacao_id');
        $this->assertSame(0, Horario::count());
    }

    public function test_o_docente_e_o_estudante_veem_o_seu_horario(): void
    {
        Carbon::setTestNow('2027-03-10 10:00');
        $this->tempo($this->a, 'prog1', 1, '08:00', '10:00', '101');
        $this->tempo($this->a, 'mat1', 2, '08:00', '10:00', '102');
        $this->tempo($this->b, 'prog1', 4, '10:00', '12:00', '101');

        // Só quem monta horários mexe neles
        $docente = User::findOrFail($this->carlos->user_id);
        $this->actingAs($docente)->post(route('horarios.store', $this->a), [
            'leccionacao_id' => $this->disciplina($this->a, 'ingles')->id, 'dia' => 5, 'inicio' => '08:00', 'fim' => '10:00',
        ])->assertForbidden();
        $this->actingAs($docente)->delete(route('horarios.destroy', Horario::firstOrFail()))->assertForbidden();

        // O docente vê as suas aulas nas duas turmas
        $this->actingAs($docente)->get('/docencia/minhas-turmas')->assertInertia(fn (Assert $page) => $page
            ->has('horario', 2)
            ->where('horario.0.turma', '1.º A')
            ->where('horario.1.turma', '1.º B'));

        // A estudante da turma A vê as aulas das suas disciplinas
        $matricula = app(MatriculaService::class)->matricular($this->estudante('Ana Macuácua'), $this->curso, $this->ano, 1, 'laboral', $this->admin);
        app(InscricaoService::class)->inscrever($matricula, $this->ano->periodos[0], $this->ids('prog1', 'mat1'), $this->admin);
        app(TurmaService::class)->distribuir($this->a);
        $conta = ContaPortal::create(['estudante_id' => $matricula->estudante_id, 'password' => 'senha-de-teste', 'estado' => 'activa']);
        $this->actingAs($conta, 'estudante')->get('/portal')->assertInertia(fn (Assert $page) => $page
            ->has('horario', 2)
            ->where('horario.0.disciplina', 'Programação I')
            ->where('horario.1.sala', '102'));

        // Retirar um tempo
        $this->actingAs($this->secretaria, 'web')->delete(route('horarios.destroy', Horario::firstOrFail()))->assertSessionHasNoErrors();
        $this->assertSame(2, Horario::count());
        Carbon::setTestNow();
    }
}
