import { Bloco, CabecalhoFicha, Estado, LinhaForm, LinhaVazia } from '@/components/erp';
import InputError from '@/components/input-error';
import { NavDocencia } from '@/components/seccoes';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { usePode } from '@/hooks/use-pode';
import AppLayout from '@/layouts/app-layout';
import { formatData } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Head, router, useForm } from '@inertiajs/react';
import { ChevronDown, ChevronRight, Pencil, Plus } from 'lucide-react';
import { Fragment, useState, type FormEvent } from 'react';

type EstadoPresenca = 'presente' | 'atraso' | 'falta';

interface Assiduidade {
    aulas: number;
    presencas: number;
    atrasos: number;
    faltas: number;
    justificadas: number;
    percentagem: number | null;
    abaixo: boolean;
}

interface Estudante {
    inscricao_id: number;
    numero: string;
    nome: string;
    em_atraso: boolean;
    assiduidade: Assiduidade | null;
}

interface Aula {
    id: number;
    data: string;
    inicio: string;
    fim: string;
    sumario: string;
    registada_por: string | null;
    faltas: number;
    presencas: Record<string, { id: number; estado: EstadoPresenca; justificada: boolean; justificacao: string | null }>;
}

interface Props {
    leccionacao: {
        id: number;
        disciplina: string;
        codigo: string;
        turma: string;
        turma_id: number;
        curso: string;
        periodo: string;
        ano_lectivo: string;
        docente: string | null;
    };
    estudantes: Estudante[];
    aulas: Aula[];
    sugestao: { data: string; inicio: string; fim: string };
    minima: number;
    podeRegistar: boolean;
    podeJustificar: boolean;
}

const OPCOES: { valor: EstadoPresenca; letra: string; rotulo: string; activo: string }[] = [
    { valor: 'presente', letra: 'P', rotulo: 'Presente', activo: 'bg-green-600 text-white border-green-600' },
    { valor: 'atraso', letra: 'A', rotulo: 'Atrasado', activo: 'bg-amber-500 text-white border-amber-500' },
    { valor: 'falta', letra: 'F', rotulo: 'Falta', activo: 'bg-red-600 text-white border-red-600' },
];

/** Registar (ou corrigir) uma aula: horas, sumário e a presença de cada estudante; quem não é marcado fica presente. */
function FormAula({
    leccionacaoId,
    estudantes,
    aula,
    sugestao,
    fechar,
}: {
    leccionacaoId: number;
    estudantes: Estudante[];
    aula?: Aula;
    sugestao: Props['sugestao'];
    fechar: () => void;
}) {
    const inicial: Record<string, EstadoPresenca> = Object.fromEntries(
        estudantes.map((e) => [String(e.inscricao_id), aula?.presencas[String(e.inscricao_id)]?.estado ?? 'presente']),
    );
    const { data, setData, post, put, processing, errors } = useForm({
        data: aula?.data ?? sugestao.data,
        inicio: aula?.inicio ?? sugestao.inicio,
        fim: aula?.fim ?? sugestao.fim,
        sumario: aula?.sumario ?? '',
        presencas: inicial,
    });
    const marcar = (inscricao: number, estado: EstadoPresenca) => setData('presencas', { ...data.presencas, [String(inscricao)]: estado });
    const faltas = Object.values(data.presencas).filter((e) => e === 'falta').length;
    const guardar = (e: FormEvent) => {
        e.preventDefault();
        const opcoes = { preserveScroll: true, onSuccess: fechar };
        if (aula) put(route('aulas.update', aula.id), opcoes);
        else post(route('aulas.store', leccionacaoId), opcoes);
    };

    return (
        <form onSubmit={guardar}>
            {!aula && (
                <LinhaForm rotulo="Quando" htmlFor="a_data" obrigatorio ajuda={sugestao.inicio ? 'As horas vêm do horário da turma.' : undefined}>
                    <div className="flex flex-wrap items-center gap-2">
                        <Input id="a_data" type="date" value={data.data} onChange={(e) => setData('data', e.target.value)} className="w-44" />
                        <Input
                            type="time"
                            value={data.inicio}
                            onChange={(e) => setData('inicio', e.target.value)}
                            className="w-32"
                            aria-label="Início"
                            step={300}
                        />
                        <span className="text-muted-foreground text-[13px]">às</span>
                        <Input
                            type="time"
                            value={data.fim}
                            onChange={(e) => setData('fim', e.target.value)}
                            className="w-32"
                            aria-label="Fim"
                            step={300}
                        />
                    </div>
                    <InputError message={errors.data ?? errors.inicio ?? errors.fim} className="mt-1" />
                </LinhaForm>
            )}
            <LinhaForm rotulo="Sumário" htmlFor="a_sumario" obrigatorio>
                <Textarea
                    id="a_sumario"
                    value={data.sumario}
                    onChange={(e) => setData('sumario', e.target.value)}
                    rows={3}
                    placeholder="Ex.: Estruturas de repetição: for e while. Exercícios 1 a 5."
                />
                <InputError message={errors.sumario} className="mt-1" />
            </LinhaForm>
            <div className="border-b px-5 py-3">
                <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <div className="text-[13.5px] font-medium">
                        Presenças{' '}
                        <span className="text-muted-foreground font-normal">
                            · {faltas === 0 ? 'todos presentes' : `${faltas} ${faltas === 1 ? 'falta' : 'faltas'}`}
                        </span>
                    </div>
                    <div className="text-muted-foreground text-[12px]">P presente · A atrasado · F falta</div>
                </div>
                <InputError message={errors.presencas} className="mb-2" />
                <ul className="grid gap-x-6 sm:grid-cols-2">
                    {estudantes.map((e) => {
                        const estado = data.presencas[String(e.inscricao_id)];
                        return (
                            <li
                                key={e.inscricao_id}
                                className="flex items-center justify-between gap-3 border-b py-1.5 last:border-0 sm:[&:nth-last-child(2)]:border-0"
                            >
                                <div className="min-w-0">
                                    <div className="truncate text-[13.5px]">{e.nome}</div>
                                    <div className="text-muted-foreground font-mono text-[11px]">{e.numero}</div>
                                </div>
                                <div className="flex shrink-0 gap-1" role="radiogroup" aria-label={`Presença de ${e.nome}`}>
                                    {OPCOES.map((o) => (
                                        <button
                                            key={o.valor}
                                            type="button"
                                            role="radio"
                                            aria-checked={estado === o.valor}
                                            aria-label={`${o.rotulo}: ${e.nome}`}
                                            onClick={() => marcar(e.inscricao_id, o.valor)}
                                            className={cn(
                                                'size-8 rounded-md border text-[12.5px] font-semibold transition-colors',
                                                estado === o.valor ? o.activo : 'text-muted-foreground hover:bg-muted',
                                            )}
                                        >
                                            {o.letra}
                                        </button>
                                    ))}
                                </div>
                            </li>
                        );
                    })}
                </ul>
            </div>
            <div className="flex justify-end gap-2 px-5 py-3">
                <Button type="button" variant="outline" onClick={fechar}>
                    Cancelar
                </Button>
                <Button type="submit" disabled={processing}>
                    {aula ? 'Guardar alterações' : 'Registar a aula'}
                </Button>
            </div>
        </form>
    );
}

function Justificar({ presencaId }: { presencaId: number }) {
    const [aberto, setAberto] = useState(false);
    const [motivo, setMotivo] = useState('');
    if (!aberto) {
        return (
            <Button size="sm" variant="ghost" onClick={() => setAberto(true)}>
                Justificar
            </Button>
        );
    }
    return (
        <div className="flex min-w-64 gap-1.5">
            <Input
                value={motivo}
                onChange={(e) => setMotivo(e.target.value)}
                placeholder="Motivo (atestado, serviço…)"
                aria-label="Motivo"
                autoFocus
            />
            <Button
                size="sm"
                disabled={motivo.trim() === ''}
                onClick={() => router.post(route('presencas.justificar', presencaId), { justificacao: motivo }, { preserveScroll: true })}
            >
                Guardar
            </Button>
        </div>
    );
}

/** Sumários e presenças de uma disciplina numa turma, com a assiduidade de cada estudante. */
export default function Aulas({ leccionacao, estudantes, aulas, sugestao, minima, podeRegistar, podeJustificar }: Props) {
    const pode = usePode();
    const [nova, setNova] = useState(false);
    const [editar, setEditar] = useState<number | null>(null);
    const [aberta, setAberta] = useState<number | null>(null);
    const abaixo = estudantes.filter((e) => e.assiduidade?.abaixo);
    const nome = Object.fromEntries(estudantes.map((e) => [String(e.inscricao_id), e]));

    return (
        <AppLayout>
            <Head title={`Aulas · ${leccionacao.disciplina}`} />
            <NavDocencia />
            <CabecalhoFicha
                voltar={
                    pode('aulas.registar')
                        ? { href: route('docencia.minhas'), label: 'As minhas turmas' }
                        : { href: route('turmas.show', leccionacao.turma_id), label: `Turma ${leccionacao.turma}` }
                }
                referencia={leccionacao.disciplina}
                titulo={`Turma ${leccionacao.turma} · ${leccionacao.curso}`}
                subtitulo={[leccionacao.periodo, `Ano lectivo ${leccionacao.ano_lectivo}`, leccionacao.docente ?? 'docente por atribuir'].join(' · ')}
            />

            <div className="mb-5 grid gap-3 sm:grid-cols-3">
                <div className="painel px-4 py-3">
                    <div className="text-muted-foreground text-[12.5px]">Aulas dadas</div>
                    <div className="text-[20px] font-semibold tabular-nums">{aulas.length}</div>
                </div>
                <div className="painel px-4 py-3">
                    <div className="text-muted-foreground text-[12.5px]">Estudantes</div>
                    <div className="text-[20px] font-semibold tabular-nums">{estudantes.length}</div>
                </div>
                <div className="painel px-4 py-3">
                    <div className="text-muted-foreground text-[12.5px]">Abaixo de {minima} % de assiduidade</div>
                    <div className={cn('text-[20px] font-semibold tabular-nums', abaixo.length > 0 && 'text-red-700 dark:text-red-400')}>
                        {abaixo.length}
                    </div>
                </div>
            </div>

            <div className="flex flex-col gap-5">
                <Bloco
                    titulo="Aulas"
                    accoes={
                        podeRegistar &&
                        !nova &&
                        estudantes.length > 0 && (
                            <Button size="sm" onClick={() => setNova(true)}>
                                <Plus /> Registar aula
                            </Button>
                        )
                    }
                >
                    {nova && (
                        <div className="border-b">
                            <FormAula leccionacaoId={leccionacao.id} estudantes={estudantes} sugestao={sugestao} fechar={() => setNova(false)} />
                        </div>
                    )}
                    <table className="tabela">
                        <thead>
                            <tr>
                                <th className="w-8" />
                                <th>Data</th>
                                <th>Sumário</th>
                                <th className="text-right">Faltas</th>
                                <th className="w-px" />
                            </tr>
                        </thead>
                        <tbody>
                            {aulas.length === 0 && <LinhaVazia colunas={5} texto="Ainda não há aulas registadas." />}
                            {aulas.map((a) => {
                                const ausentes = Object.entries(a.presencas).filter(([, p]) => p.estado !== 'presente');
                                return (
                                    <Fragment key={a.id}>
                                        <tr>
                                            <td>
                                                {ausentes.length > 0 && (
                                                    <button
                                                        type="button"
                                                        onClick={() => setAberta(aberta === a.id ? null : a.id)}
                                                        aria-label={aberta === a.id ? 'Esconder faltas e atrasos' : 'Ver faltas e atrasos'}
                                                        aria-expanded={aberta === a.id}
                                                        className="text-muted-foreground hover:text-foreground"
                                                    >
                                                        {aberta === a.id ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
                                                    </button>
                                                )}
                                            </td>
                                            <td className="whitespace-nowrap tabular-nums">
                                                {formatData(a.data)}
                                                <div className="text-muted-foreground text-[12px]">
                                                    {a.inicio}–{a.fim}
                                                </div>
                                            </td>
                                            <td className="max-w-[28rem]">
                                                <div className="line-clamp-2">{a.sumario}</div>
                                            </td>
                                            <td className={cn('text-right tabular-nums', a.faltas > 0 && 'text-red-700 dark:text-red-400')}>
                                                {a.faltas}
                                            </td>
                                            <td>
                                                {podeRegistar && (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() => setEditar(editar === a.id ? null : a.id)}
                                                        aria-label="Corrigir a aula"
                                                    >
                                                        <Pencil />
                                                    </Button>
                                                )}
                                            </td>
                                        </tr>
                                        {editar === a.id && (
                                            <tr>
                                                <td colSpan={5} className="bg-muted/30 p-0">
                                                    <FormAula
                                                        leccionacaoId={leccionacao.id}
                                                        estudantes={estudantes}
                                                        aula={a}
                                                        sugestao={sugestao}
                                                        fechar={() => setEditar(null)}
                                                    />
                                                </td>
                                            </tr>
                                        )}
                                        {aberta === a.id &&
                                            ausentes.map(([inscricao, p]) => (
                                                <tr key={inscricao} className="bg-muted/30">
                                                    <td />
                                                    <td colSpan={2}>
                                                        {nome[inscricao]?.nome}{' '}
                                                        <span className="text-muted-foreground font-mono text-[11.5px]">
                                                            {nome[inscricao]?.numero}
                                                        </span>
                                                        {p.justificada && p.justificacao && (
                                                            <div className="text-muted-foreground text-[12px]">Justificada: {p.justificacao}</div>
                                                        )}
                                                    </td>
                                                    <td className="text-right">
                                                        {p.estado === 'atraso' ? (
                                                            <Estado cor="laranja">Atrasado</Estado>
                                                        ) : p.justificada ? (
                                                            <Estado cor="cinza">Falta justificada</Estado>
                                                        ) : (
                                                            <Estado cor="vermelho">Falta</Estado>
                                                        )}
                                                    </td>
                                                    <td>
                                                        {podeJustificar && p.estado === 'falta' && !p.justificada && <Justificar presencaId={p.id} />}
                                                    </td>
                                                </tr>
                                            ))}
                                    </Fragment>
                                );
                            })}
                        </tbody>
                    </table>
                </Bloco>

                <Bloco titulo={`Assiduidade (mínimo ${minima} %)`}>
                    <table className="tabela">
                        <thead>
                            <tr>
                                <th>Estudante</th>
                                <th className="text-right">Faltas</th>
                                <th className="text-right">Justificadas</th>
                                <th className="text-right">Atrasos</th>
                                <th className="text-right">Assiduidade</th>
                            </tr>
                        </thead>
                        <tbody>
                            {estudantes.length === 0 && <LinhaVazia colunas={5} texto="Ainda não há estudantes nesta disciplina." />}
                            {estudantes.map((e) => (
                                <tr key={e.inscricao_id}>
                                    <td>
                                        {e.nome}
                                        <div className="text-muted-foreground font-mono text-[11.5px]">
                                            {e.numero}
                                            {e.em_atraso && ' · em atraso'}
                                        </div>
                                    </td>
                                    <td className="text-right tabular-nums">{e.assiduidade?.faltas ?? 0}</td>
                                    <td className="text-right tabular-nums">{e.assiduidade?.justificadas ?? 0}</td>
                                    <td className="text-right tabular-nums">{e.assiduidade?.atrasos ?? 0}</td>
                                    <td className="text-right tabular-nums">
                                        {e.assiduidade?.percentagem === null || e.assiduidade === null ? (
                                            <span className="text-muted-foreground">—</span>
                                        ) : e.assiduidade.abaixo ? (
                                            <Estado cor="vermelho">{e.assiduidade.percentagem} %</Estado>
                                        ) : (
                                            `${e.assiduidade.percentagem} %`
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Bloco>
            </div>
        </AppLayout>
    );
}
