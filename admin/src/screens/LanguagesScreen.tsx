import { useQuery } from '@tanstack/react-query';
import { ArrowRight, Plus, Star } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router';

import { ApiError } from '@/api/errors';
import { isSupportedLocale } from '@/i18n';
import { cn } from '@/lib/cn';
import { useMediaQuery } from '@/lib/useMediaQuery';
import { languages as fetchLanguages, type AdminLanguage } from '@/screens/languages/api';
import { CoverageMeter } from '@/screens/languages/Coverage';
import { LanguageDetail } from '@/screens/languages/LanguageDetail';
import {
    overview as fetchOverview,
    type LanguageStanding,
    type Overview,
} from '@/screens/translations/api';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';
import { StatusBadge } from '@/ui/StatusBadge';

/** Nothing selected, an existing language, or the one being added. */
type Selection = { kind: 'none' } | { kind: 'language'; id: string } | { kind: 'new' };

/**
 * The languages the platform knows, how far each has been translated, and what to do
 * next with each.
 *
 * Three things are kept apart here on purpose, because conflating any two of them is
 * the mistake this screen exists to prevent:
 *
 * - **Exists** — a language in this list can be translated.
 * - **Served** — an *active* language is one clients can choose and the platform falls
 *   back through. A draft is not served, however far it has been translated (ADR 0048).
 * - **The console speaks it** — the Admin ships its own message catalogues, and adding
 *   a language cannot add one (ADR 0049).
 *
 * Coverage and AI state are read from the platform, from the same calculation the
 * workshop uses; nothing here counts anything.
 *
 * There is no delete, because the API has none: settings and notification translations
 * reference a language by code, and the platform's answer to "stop using this one" is
 * to deactivate it.
 */
export function LanguagesScreen() {
    const { t } = useTranslation();
    const navigate = useNavigate();
    const [selection, setSelection] = useState<Selection>({ kind: 'none' });

    // A structural switch rather than a styling one: the table and the record list are
    // different markup, and rendering both would put every language in the
    // accessibility tree twice.
    const wide = useMediaQuery('(min-width: 1024px)');

    const list = useQuery({
        queryKey: ['admin-languages'],
        queryFn: ({ signal }) => fetchLanguages(signal),
    });

    const standing = useQuery({
        queryKey: ['translation-overview'],
        queryFn: ({ signal }) => fetchOverview(signal),
        // While suggestions are being generated the counts move on their own, so the
        // page follows them — and stops asking once nothing is waiting.
        refetchInterval: (query) =>
            (query.state.data?.languages ?? []).some((row) => row.suggestions.pending > 0)
                ? 3000
                : false,
    });

    if (list.isPending) {
        return (
            <Screen>
                <p className="text-(--text-muted)">{t('state.loading')}</p>
            </Screen>
        );
    }

    if (list.error !== null) {
        return (
            <Screen>
                <Alert tone="danger">
                    <p>{list.error instanceof ApiError ? list.error.message : t('state.error')}</p>
                    <Button
                        className="mt-2"
                        onClick={() => void list.refetch()}
                        size="sm"
                        variant="secondary"
                    >
                        {t('state.retry')}
                    </Button>
                </Alert>
            </Screen>
        );
    }

    const rows = list.data ?? [];
    const standings = new Map(
        (standing.data?.languages ?? []).map((row) => [row.code, row] as const),
    );
    const ai = standing.data?.ai;

    const selected =
        selection.kind === 'language'
            ? (rows.find((row) => row.id === selection.id) ?? null)
            : null;

    // A selection whose language is gone — renamed away under another session, say —
    // is not a selection. Falling back to the empty panel is better than rendering the
    // add form, which is what a null language otherwise means here.
    const showDetail = selection.kind === 'new' || selected !== null;

    const manage = (code: string): void => {
        void navigate(`/translations?target=${encodeURIComponent(code)}`);
    };

    return (
        <Screen
            action={
                <Button onClick={() => setSelection({ kind: 'new' })} variant="secondary">
                    <Plus aria-hidden className="size-3.5" />
                    {t('languages.add')}
                </Button>
            }
        >
            <p className="max-w-prose text-(--text-secondary)">{t('languages.intro')}</p>

            <div className="grid grid-cols-1 gap-(--section-gap) xl:grid-cols-[1fr_var(--panel-width-docked)]">
                <div className="min-w-0 border border-(--border-default) bg-(--surface-default)">
                    {wide ? (
                        <table className="w-full border-collapse">
                            <thead>
                                <tr className="border-b border-(--border-strong)">
                                    <Th>{t('languages.columns.language')}</Th>
                                    <Th>{t('languages.columns.status')}</Th>
                                    <Th>{t('languages.columns.coverage')}</Th>
                                    <Th>{t('languages.columns.ai')}</Th>
                                    <Th>{t('languages.columns.next')}</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row) => (
                                    <tr
                                        className={cn(
                                            'cursor-pointer border-b border-(--border-default) last:border-b-0',
                                            selected?.id === row.id
                                                ? 'bg-(--action-secondary)'
                                                : 'hover:bg-(--action-ghost-hover)',
                                        )}
                                        key={row.id}
                                        onClick={() =>
                                            setSelection({ kind: 'language', id: row.id })
                                        }
                                    >
                                        <Td>
                                            {/* A real control, not a clickable row. A
                                                table row is not focusable and has no
                                                keyboard activation, so the row handler
                                                alone would leave this table reachable by
                                                pointer only. */}
                                            <button
                                                className="relative flex w-full flex-col ps-3 text-start focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-(--focus-ring)"
                                                onClick={() =>
                                                    setSelection({ kind: 'language', id: row.id })
                                                }
                                                type="button"
                                            >
                                                <Rail language={row} />
                                                <Identity language={row} />
                                            </button>
                                        </Td>
                                        <Td>
                                            <Status language={row} />
                                        </Td>
                                        <Td>
                                            <Coverage standing={standings.get(row.code)} />
                                        </Td>
                                        <Td>
                                            <AiReadiness ai={ai} language={row} />
                                        </Td>
                                        <Td>
                                            <NextStep language={row} onManage={manage} />
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    ) : (
                        <ul className="divide-y divide-(--border-default)">
                            {rows.map((row) => (
                                <li
                                    className="relative flex flex-col gap-2 px-3 py-3 ps-4"
                                    key={row.id}
                                >
                                    <Rail language={row} />
                                    <button
                                        className="text-start focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-(--focus-ring)"
                                        onClick={() =>
                                            setSelection({ kind: 'language', id: row.id })
                                        }
                                        type="button"
                                    >
                                        <Identity language={row} />
                                    </button>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Status language={row} />
                                        <AiReadiness ai={ai} language={row} />
                                    </div>
                                    <Coverage standing={standings.get(row.code)} />
                                    <NextStep language={row} onManage={manage} />
                                </li>
                            ))}
                        </ul>
                    )}

                    {rows.length === 0 ? (
                        <p className="p-4 text-(--text-muted)">{t('languages.none')}</p>
                    ) : null}
                </div>

                <aside aria-label={t('languages.detail')} className="min-w-0">
                    {showDetail ? (
                        <LanguageDetail
                            // Keyed by what it is editing, so a draft never survives a
                            // change of selection and a refetch never resets one.
                            key={selected?.id ?? 'new'}
                            language={selected}
                            onClose={() => setSelection({ kind: 'none' })}
                            onCreated={(created) =>
                                setSelection({ kind: 'language', id: created.id })
                            }
                            {...(ai === undefined ? {} : { ai })}
                            {...(selected === null || standings.get(selected.code) === undefined
                                ? {}
                                : { standing: standings.get(selected.code) as LanguageStanding })}
                        />
                    ) : (
                        <p className="border border-(--border-default) bg-(--surface-default) p-4 text-(--text-muted)">
                            {t('languages.chooseLanguage')}
                        </p>
                    )}
                </aside>
            </div>
        </Screen>
    );
}

function Screen({ action, children }: { action?: React.ReactNode; children: React.ReactNode }) {
    const { t } = useTranslation();

    return (
        <div className="flex flex-col gap-(--section-gap)">
            <header className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p data-eyebrow>{t('languages.eyebrow')}</p>
                    <h1 className="text-(length:--text-2xl) text-(--text-primary)">
                        {t('modules.languages')}
                    </h1>
                </div>
                {action}
            </header>
            {children}
        </div>
    );
}

/** Name, native name, code and direction: who the language is. */
function Identity({ language }: { language: AdminLanguage }) {
    const { t } = useTranslation();

    return (
        <span className="flex min-w-0 flex-col">
            <span className="flex flex-wrap items-baseline gap-x-2">
                <span className="font-medium text-(--text-primary)">{language.name}</span>
                <span
                    className="text-(--text-secondary)"
                    dir={language.direction}
                    lang={language.code}
                >
                    {language.native_name}
                </span>
            </span>
            <span className="text-(length:--text-xs) text-(--text-muted)" data-technical>
                {language.code} · {t(`languages.direction.${language.direction}`)}
            </span>
            <span className="mt-1">
                <InterfaceFlag code={language.code} />
            </span>
        </span>
    );
}

/** Served or a draft, and whether it is the one everything falls back to. */
function Status({ language }: { language: AdminLanguage }) {
    const { t } = useTranslation();

    return (
        <span className="flex flex-wrap gap-1">
            {language.is_active ? (
                <StatusBadge tone="success">{t('languages.active')}</StatusBadge>
            ) : (
                <StatusBadge tone="pending">
                    {t('languages.draft')} · {t('languages.draftHint')}
                </StatusBadge>
            )}
            {language.is_default ? (
                <StatusBadge icon={<Star className="size-3" />} tone="info">
                    {t('languages.default')}
                </StatusBadge>
            ) : null}
        </span>
    );
}

function Coverage({ standing }: { standing: LanguageStanding | undefined }) {
    if (standing === undefined) {
        return <span className="text-(--text-muted)">—</span>;
    }

    return <CoverageMeter className="min-w-32" compact counts={standing.coverage} />;
}

/**
 * Whether AI can help with this language, for this operator.
 *
 * The source language has nothing to be translated into, so it shows its role instead
 * of a readiness it does not need.
 */
function AiReadiness({
    ai,
    language,
}: {
    ai: Overview['ai'] | undefined;
    language: AdminLanguage;
}) {
    const { t } = useTranslation();

    if (language.is_default) {
        return <StatusBadge tone="neutral">{t('languages.sourceLanguage')}</StatusBadge>;
    }

    if (ai === undefined) {
        return <span className="text-(--text-muted)">—</span>;
    }

    if (!ai.available) {
        return <StatusBadge tone="neutral">{t('languages.aiNotConfigured')}</StatusBadge>;
    }

    return ai.may_use ? (
        <StatusBadge tone="success">{t('languages.aiReady')}</StatusBadge>
    ) : (
        <StatusBadge tone="warning">{t('languages.aiNotPermitted')}</StatusBadge>
    );
}

function NextStep({
    language,
    onManage,
}: {
    language: AdminLanguage;
    onManage: (code: string) => void;
}) {
    const { t } = useTranslation();

    if (language.is_default) {
        return null;
    }

    return (
        <Button
            onClick={(event) => {
                // Inside a selectable row: acting on the language is not selecting it.
                event.stopPropagation();
                onManage(language.code);
            }}
            size="sm"
            variant="secondary"
        >
            {t('languages.manageTranslations')}
            <ArrowRight aria-hidden className="size-3.5 rtl:-scale-x-100" />
        </Button>
    );
}

/**
 * Whether this console can be read in the language, as opposed to whether the platform
 * serves it.
 *
 * The catalogues are shipped with the bundle, so this is a fact about the build rather
 * than anything the API could answer — which is exactly why it is worth showing beside
 * the rows the API did answer (ADR 0049).
 */
function InterfaceFlag({ code }: { code: string }) {
    const { t } = useTranslation();
    const translated = isSupportedLocale(code);

    return (
        <StatusBadge tone={translated ? 'success' : 'neutral'}>
            {translated ? t('languages.interfaceYes') : t('languages.interfaceNo')}
        </StatusBadge>
    );
}

function Rail({ language }: { language: AdminLanguage }) {
    return (
        <span
            aria-hidden
            className={cn(
                'absolute inset-y-0 start-0 w-(--rail-width)',
                language.is_default
                    ? 'bg-(--state-info-rail)'
                    : language.is_active
                      ? 'bg-(--state-success-rail)'
                      : 'bg-(--state-pending-rail)',
            )}
        />
    );
}

function Th({ children }: { children: React.ReactNode }) {
    return (
        <th className="px-3 py-2 text-start align-bottom" scope="col">
            <span data-eyebrow>{children}</span>
        </th>
    );
}

function Td({ children }: { children: React.ReactNode }) {
    return (
        <td className="px-3 py-(--table-cell-padding-block) align-middle text-(length:--text-sm)">
            {children}
        </td>
    );
}
