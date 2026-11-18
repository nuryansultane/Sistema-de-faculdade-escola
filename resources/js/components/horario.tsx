/**
 * O horário semanal numa grelha (segunda a sexta, e sábado se houver aulas): cada tempo é um bloco na altura das suas
 * horas. No telemóvel, a mesma informação vira uma lista por dia. Usado na turma, no docente e no portal do estudante.
 */
import { cn } from '@/lib/utils';
import { X } from 'lucide-react';

export interface Tempo {
    id: number;
    leccionacao_id: number;
    dia: number;
    inicio: string;
    fim: string;
    disciplina: string;
    codigo: string;
    turma: string;
    turma_id: number;
    docente: string | null;
    sala: string | null;
}

type Detalhe = 'turma' | 'docente' | 'sala';

export const DIAS = ['', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];

const CORES = [
    'bg-blue-50 border-blue-500 text-blue-950 dark:bg-blue-950/50 dark:text-blue-100',
    'bg-emerald-50 border-emerald-500 text-emerald-950 dark:bg-emerald-950/50 dark:text-emerald-100',
    'bg-amber-50 border-amber-500 text-amber-950 dark:bg-amber-950/50 dark:text-amber-100',
    'bg-violet-50 border-violet-500 text-violet-950 dark:bg-violet-950/50 dark:text-violet-100',
    'bg-rose-50 border-rose-500 text-rose-950 dark:bg-rose-950/50 dark:text-rose-100',
    'bg-cyan-50 border-cyan-500 text-cyan-950 dark:bg-cyan-950/50 dark:text-cyan-100',
    'bg-lime-50 border-lime-600 text-lime-950 dark:bg-lime-950/50 dark:text-lime-100',
    'bg-orange-50 border-orange-500 text-orange-950 dark:bg-orange-950/50 dark:text-orange-100',
];

const minutos = (h: string) => {
    const [a, b] = h.split(':').map(Number);
    return a * 60 + b;
};

const ALTURA_HORA = 52;

/** Os blocos que se sobrepõem no mesmo dia (só no portal, com disciplinas em atraso) dividem a largura. */
function faixas(tempos: Tempo[]): Map<number, { faixa: number; total: number }> {
    const r = new Map<number, { faixa: number; total: number }>();
    const ordenados = [...tempos].sort((a, b) => minutos(a.inicio) - minutos(b.inicio));
    let grupo: Tempo[] = [];
    let fimGrupo = -1;
    const fechar = () => {
        const fins: number[] = [];
        const faixaDe = new Map<number, number>();
        for (const t of grupo) {
            let f = fins.findIndex((fim) => fim <= minutos(t.inicio));
            if (f === -1) {
                f = fins.length;
                fins.push(0);
            }
            fins[f] = minutos(t.fim);
            faixaDe.set(t.id, f);
        }
        for (const t of grupo) r.set(t.id, { faixa: faixaDe.get(t.id) ?? 0, total: fins.length });
        grupo = [];
    };
    for (const t of ordenados) {
        if (grupo.length > 0 && minutos(t.inicio) >= fimGrupo) fechar();
        grupo.push(t);
        fimGrupo = Math.max(fimGrupo, minutos(t.fim));
    }
    if (grupo.length > 0) fechar();
    return r;
}

function linhaDetalhe(t: Tempo, detalhes: Detalhe[]) {
    return [
        detalhes.includes('turma') && t.turma,
        detalhes.includes('docente') && (t.docente ?? 'docente por atribuir'),
        detalhes.includes('sala') && t.sala && `sala ${t.sala}`,
    ]
        .filter(Boolean)
        .join(' · ');
}

export function GradeHorario({
    tempos,
    detalhes = ['docente', 'sala'],
    remover,
    vazio = 'Ainda não há aulas no horário.',
}: {
    tempos: Tempo[];
    detalhes?: Detalhe[];
    remover?: (t: Tempo) => void;
    vazio?: string;
}) {
    if (tempos.length === 0) {
        return <p className="text-muted-foreground px-5 py-8 text-center text-[13.5px]">{vazio}</p>;
    }
    const codigos = [...new Set(tempos.map((t) => t.codigo))].sort();
    const cor = (t: Tempo) => CORES[codigos.indexOf(t.codigo) % CORES.length];
    const dias = tempos.some((t) => t.dia === 6) ? [1, 2, 3, 4, 5, 6] : [1, 2, 3, 4, 5];
    const primeira = Math.min(7, ...tempos.map((t) => Math.floor(minutos(t.inicio) / 60)));
    const ultima = Math.max(17, ...tempos.map((t) => Math.ceil(minutos(t.fim) / 60)));
    const horas = Array.from({ length: ultima - primeira }, (_, i) => primeira + i);
    const porDia = (d: number) => tempos.filter((t) => t.dia === d).sort((a, b) => a.inicio.localeCompare(b.inicio));

    return (
        <>
            {/* Telemóvel: lista por dia */}
            <div className="divide-y sm:hidden">
                {dias
                    .filter((d) => porDia(d).length > 0)
                    .map((d) => (
                        <div key={d} className="px-4 py-3">
                            <div className="text-muted-foreground mb-1.5 text-[12px] font-medium tracking-wide uppercase">{DIAS[d]}</div>
                            <ul className="flex flex-col gap-1.5">
                                {porDia(d).map((t) => (
                                    <li key={t.id} className={cn('flex items-start gap-3 rounded-md border-l-[3px] px-3 py-2', cor(t))}>
                                        <div className="w-[5.6rem] shrink-0 text-[12.5px] font-medium whitespace-nowrap tabular-nums">
                                            {t.inicio}–{t.fim}
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="text-[13.5px] font-medium">{t.disciplina}</div>
                                            <div className="text-[12px] opacity-75">{linhaDetalhe(t, detalhes)}</div>
                                        </div>
                                        {remover && (
                                            <button
                                                type="button"
                                                onClick={() => remover(t)}
                                                aria-label={`Retirar ${t.disciplina}`}
                                                className="opacity-60 hover:opacity-100"
                                            >
                                                <X className="size-4" />
                                            </button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
            </div>

            {/* Ecrã largo: grelha semanal */}
            <div className="hidden overflow-x-auto sm:block">
                <div className="grid min-w-[640px]" style={{ gridTemplateColumns: `3.25rem repeat(${dias.length}, minmax(0, 1fr))` }}>
                    <div className="border-b" />
                    {dias.map((d) => (
                        <div key={d} className="text-muted-foreground border-b border-l px-2 py-2 text-center text-[12.5px] font-medium">
                            {DIAS[d]}
                        </div>
                    ))}
                    <div className="relative" style={{ height: horas.length * ALTURA_HORA }}>
                        {horas.map((h, i) => (
                            <div
                                key={h}
                                className="text-muted-foreground absolute right-2 text-[11px] tabular-nums"
                                style={{ top: i * ALTURA_HORA - 7 }}
                            >
                                {i === 0 ? '' : `${String(h).padStart(2, '0')}:00`}
                            </div>
                        ))}
                    </div>
                    {dias.map((d) => {
                        const doDia = porDia(d);
                        const f = faixas(doDia);
                        return (
                            <div key={d} className="relative border-l" style={{ height: horas.length * ALTURA_HORA }}>
                                {horas.map((h, i) => (
                                    <div key={h} className="border-border/60 absolute inset-x-0 border-t" style={{ top: i * ALTURA_HORA }} />
                                ))}
                                {doDia.map((t) => {
                                    const { faixa, total } = f.get(t.id) ?? { faixa: 0, total: 1 };
                                    const topo = ((minutos(t.inicio) - primeira * 60) / 60) * ALTURA_HORA;
                                    const altura = ((minutos(t.fim) - minutos(t.inicio)) / 60) * ALTURA_HORA;
                                    return (
                                        <div
                                            key={t.id}
                                            className={cn(
                                                'group absolute overflow-hidden rounded-md border-l-[3px] px-2 py-1 text-[12px] leading-tight',
                                                cor(t),
                                            )}
                                            style={{
                                                top: topo + 1,
                                                height: altura - 2,
                                                left: `calc(${(faixa / total) * 100}% + 3px)`,
                                                width: `calc(${100 / total}% - 6px)`,
                                            }}
                                            title={`${t.disciplina} · ${t.inicio}–${t.fim}${t.sala ? ` · sala ${t.sala}` : ''}`}
                                        >
                                            <div className="truncate font-semibold">{t.disciplina}</div>
                                            <div className="tabular-nums opacity-75">
                                                {t.inicio}–{t.fim}
                                            </div>
                                            {altura >= 60 && <div className="truncate opacity-75">{linhaDetalhe(t, detalhes)}</div>}
                                            {remover && (
                                                <button
                                                    type="button"
                                                    onClick={() => remover(t)}
                                                    aria-label={`Retirar ${t.disciplina} de ${DIAS[t.dia]} às ${t.inicio}`}
                                                    className="bg-background/80 absolute top-1 right-1 hidden rounded p-0.5 group-hover:block focus:block"
                                                >
                                                    <X className="size-3.5" />
                                                </button>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        );
                    })}
                </div>
            </div>
        </>
    );
}
