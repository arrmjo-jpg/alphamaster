import { useQuery } from '@tanstack/react-query';
import { Plus, Star } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/errors';
import { isSupportedLocale } from '@/i18n';
import { cn } from '@/lib/cn';
import { useMediaQuery } from '@/lib/useMediaQuery';
import { languages as fetchLanguages, type AdminLanguage } from '@/screens/languages/api';
import { LanguageDetail } from '@/screens/languages/LanguageDetail';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';
import { StatusBadge } from '@/ui/StatusBadge';

/** Nothing selected, an existing language, or the one being added. */
type Selection = { kind: 'none' } | { kind: 'language'; id: string } | { kind: 'new' };

/**
 * The languages the platform serves, and which one it falls back to.
 *
 * Two things are kept apart here on purpose, because conflating them is the mistake
 * this screen exists to prevent. A language in this list is one the platform will
 * accept content and translations in; a language this console can be *read* in is one
 * the Admin ships a message catalogue for. Adding Kurdish makes the first true and
 * cannot make the second, and the interface says so at the point of adding rather than
 * leaving an administrator to discover it from a half-English screen.
 *
 * There is no delete, because the API has none: settings and notification translations
 * reference a language by code, and the platform's answer to "stop using this one" is
 * to deactivate it.
 *
 * These endpoints sit behind the administrative perimeter and behind no permission of
 * their own — there is no `languages.*` entry in the catalogue — so every administrator
 * who can reach the Admin can reach them. That is stated rather than papered over with
 * a gate this client would be inventing.
 */
export function LanguagesScreen() {
    const { t } = useTranslation();
    const [selection, setSelection] = useState<Selection>({ kind: 'none' });

    // A structural switch rather than a styling one: the table and the record list are
    // different markup, and rendering both would put every language in the
    // accessibility tree twice.
    const wide = useMediaQuery('(min-width: 768px)');

    const list = useQuery({
        queryKey: ['admin-languages'],
        queryFn: ({ signal }) => fetchLanguages(signal),
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
    const selected =
        selection.kind === 'language'
            ? (rows.find((row) => row.id === selection.id) ?? null)
            : null;

    // A selection whose language is gone — renamed away under another session, say —
    // is not a selection. Falling back to the empty panel is better than rendering the
    // add form, which is what a null language otherwise means here.
    const showDetail = selection.kind === 'new' || selected !== null;

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
                                    <Th>{t('languages.columns.direction')}</Th>
                                    <Th>{t('languages.columns.status')}</Th>
                                    <Th>{t('languages.columns.interface')}</Th>
                                    <Th>{t('languages.columns.order')}</Th>
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
                                                pointer only. Selecting twice is a no-op,
                                                so both paths can coexist. */}
                                            <button
                                                className="relative flex w-full flex-col ps-3 text-start focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-(--focus-ring)"
                                                onClick={() =>
                                                    setSelection({ kind: 'language', id: row.id })
                                                }
                                                type="button"
                                            >
                                                <Rail language={row} />
                                                <span className="flex flex-wrap items-baseline gap-x-2">
                                                    <span className="font-medium text-(--text-primary)">
                                                        {row.name}
                                                    </span>
                                                    <span
                                                        className="text-(--text-secondary)"
                                                        lang={row.code}
                                                    >
                                                        {row.native_name}
                                                    </span>
                                                </span>
                                                <span
                                                    className="text-(length:--text-xs) text-(--text-muted)"
                                                    data-technical
                                                >
                                                    {row.code}
                                                </span>
                                            </button>
                                        </Td>
                                        <Td>{t(`languages.direction.${row.direction}`)}</Td>
                                        <Td>
                                            <span className="flex flex-wrap gap-1">
                                                <StatusBadge
                                                    tone={row.is_active ? 'success' : 'neutral'}
                                                >
                                                    {row.is_active
                                                        ? t('languages.active')
                                                        : t('languages.inactive')}
                                                </StatusBadge>
                                                {row.is_default ? (
                                                    <StatusBadge
                                                        icon={<Star className="size-3" />}
                                                        tone="info"
                                                    >
                                                        {t('languages.default')}
                                                    </StatusBadge>
                                                ) : null}
                                            </span>
                                        </Td>
                                        <Td>
                                            <InterfaceFlag code={row.code} />
                                        </Td>
                                        <Td>
                                            <span data-technical>{row.sort_order}</span>
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    ) : (
                        <ul className="divide-y divide-(--border-default)">
                            {rows.map((row) => (
                                <li key={row.id}>
                                    <button
                                        className="relative w-full px-3 py-2.5 ps-4 text-start hover:bg-(--action-ghost-hover)"
                                        onClick={() =>
                                            setSelection({ kind: 'language', id: row.id })
                                        }
                                        type="button"
                                    >
                                        <Rail language={row} />
                                        <span className="flex flex-wrap items-baseline gap-x-2">
                                            <span className="font-medium text-(--text-primary)">
                                                {row.name}
                                            </span>
                                            <span
                                                className="text-(--text-secondary)"
                                                lang={row.code}
                                            >
                                                {row.native_name}
                                            </span>
                                        </span>
                                        <span
                                            className="block text-(length:--text-xs) text-(--text-muted)"
                                            data-technical
                                        >
                                            {row.code} · {t(`languages.direction.${row.direction}`)}{' '}
                                            · {t('languages.orderShort', { order: row.sort_order })}
                                        </span>
                                        <span className="mt-1 flex flex-wrap gap-1">
                                            <StatusBadge
                                                tone={row.is_active ? 'success' : 'neutral'}
                                            >
                                                {row.is_active
                                                    ? t('languages.active')
                                                    : t('languages.inactive')}
                                            </StatusBadge>
                                            {row.is_default ? (
                                                <StatusBadge
                                                    icon={<Star className="size-3" />}
                                                    tone="info"
                                                >
                                                    {t('languages.default')}
                                                </StatusBadge>
                                            ) : null}
                                            <InterfaceFlag code={row.code} />
                                        </span>
                                    </button>
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

/**
 * Whether this console can be read in the language, as opposed to whether the platform
 * serves it.
 *
 * The catalogues are shipped with the bundle, so this is a fact about the build rather
 * than anything the API could answer — which is exactly why it is worth showing beside
 * the rows the API did answer.
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
                      : 'bg-(--state-neutral-rail)',
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
