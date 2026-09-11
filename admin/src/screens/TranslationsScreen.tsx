import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Search, Sparkles } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router';

import { ApiError } from '@/api/errors';
import { useCurrentUser } from '@/auth/AuthProvider';
import {
    acceptSuggestion,
    dismissSuggestion,
    requestSuggestions,
    suggestions as fetchSuggestions,
    type Suggestion,
} from '@/screens/ai/api';
import { CoverageMeter } from '@/screens/languages/Coverage';
import { TranslationEntryRow } from '@/screens/translations/TranslationEntryRow';
import {
    overview as fetchOverview,
    workshop as fetchWorkshop,
    writeTranslation,
    WORKSHOP_STATES,
    type TranslationEntry,
    type TranslationSource,
    type WorkshopQuery,
    type WorkshopState,
} from '@/screens/translations/api';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';
import { Input } from '@/ui/Input';
import { SegmentedControl } from '@/ui/SegmentedControl';
import { StateRail } from '@/ui/StateRail';

const PER_PAGE = 25;

function isWorkshopState(value: string | null): value is WorkshopState {
    return value !== null && (WORKSHOP_STATES as string[]).includes(value);
}

/**
 * What the platform says, in every language it says it in.
 *
 * The languages workspace manages *which* languages exist. This is what is written in
 * them, and the two were never the same thing: adding Arabic to the language list did
 * nothing to the roles, the settings copy or the notification wording, and no screen
 * anywhere reported that.
 *
 * Queried, not downloaded (ADR 0048 §4). The target, the filter, the search and the
 * page live in the address, so the Languages screen can link straight to "what is
 * missing in French" and a reload lands where the operator was. Every one of them is
 * answered by the server; the browser holds one page.
 *
 * Permissions are per body of content, not per screen (ADR 0043 §3). The API decides
 * what is visible and what is writable; this reflects the decision rather than making
 * one.
 */
export function TranslationsScreen() {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [params, setParams] = useSearchParams();

    const viewer = useCurrentUser();
    const mayUseAi = viewer.permissions.includes('ai.use');

    const requestedTarget = params.get('target') ?? '';
    const stateParam = params.get('state');
    const state: WorkshopState = isWorkshopState(stateParam) ? stateParam : 'all';
    const search = params.get('search') ?? '';
    const page = Math.max(1, Number.parseInt(params.get('page') ?? '1', 10) || 1);

    const [searchDraft, setSearchDraft] = useState(search);

    const update = (changes: Record<string, string | null>) => {
        const next = new URLSearchParams(params);

        for (const [key, value] of Object.entries(changes)) {
            if (value === null || value === '') {
                next.delete(key);
            } else {
                next.set(key, value);
            }
        }

        setParams(next, { replace: true });
    };

    const query: WorkshopQuery = {
        ...(requestedTarget === '' ? {} : { target: requestedTarget }),
        ...(state === 'all' ? {} : { state }),
        ...(search === '' ? {} : { search }),
        page,
        per_page: PER_PAGE,
    };

    const view = useQuery({
        queryKey: ['translations', query],
        queryFn: ({ signal }) => fetchWorkshop(query, signal),
        // The previous page stays on screen while the next loads, so paging and
        // filtering do not flash an empty workshop between answers.
        placeholderData: keepPreviousData,
    });

    const targetCode = view.data?.target ?? null;

    // Suggestions arrive asynchronously — a generation takes seconds and runs on a
    // queue (ADR 0044 §4) — so the client polls instead of waiting, and stops as soon
    // as nothing is outstanding.
    const proposals = useQuery({
        queryKey: ['translation-suggestions', targetCode],
        queryFn: ({ signal }) => fetchSuggestions(targetCode ?? '', signal),
        enabled: targetCode !== null,
        refetchInterval: (current) =>
            (current.state.data ?? []).some((row: Suggestion) => row.status === 'pending')
                ? 3000
                : false,
    });

    // Whether AI is there at all, read from the platform rather than assumed, so the
    // action explains its own absence instead of being a button that always fails.
    const standing = useQuery({
        queryKey: ['translation-overview'],
        queryFn: ({ signal }) => fetchOverview(signal),
        enabled: mayUseAi,
    });

    const refreshAll = async () => {
        await queryClient.invalidateQueries({ queryKey: ['translations'] });
        await queryClient.invalidateQueries({ queryKey: ['translation-suggestions'] });
        await queryClient.invalidateQueries({ queryKey: ['translation-overview'] });
    };

    const ask = useMutation({
        mutationFn: (locale: string) => requestSuggestions({ locale }),
        onSuccess: refreshAll,
    });

    const decide = useMutation({
        mutationFn: async (decision: { id: string; text?: string }) => {
            if (decision.text === undefined) {
                await dismissSuggestion(decision.id);

                return;
            }

            await acceptSuggestion(decision.id, decision.text);
        },
        // Accepting writes a translation, so the workshop's own view of the content
        // and the coverage are stale as well as the suggestion list.
        onSuccess: refreshAll,
    });

    const save = useMutation({
        mutationFn: ({
            source,
            id,
            locale,
            values,
        }: {
            source: string;
            id: string;
            locale: string;
            values: Record<string, string>;
        }) => writeTranslation(source, id, { locale, values }),
        onSuccess: refreshAll,
    });

    if (view.isPending) {
        return <StateRail tone="info">{t('state.loading')}</StateRail>;
    }

    if (view.error !== null) {
        return (
            <div className="flex min-w-0 flex-col gap-(--section-gap)">
                <Header />
                <Alert tone="danger">
                    {view.error instanceof ApiError ? view.error.message : t('state.error')}
                </Alert>
            </div>
        );
    }

    const data = view.data;
    const source = data.locales.find((language) => language.code === data.source_locale);
    const target = data.locales.find((language) => language.code === data.target);

    if (source === undefined || target === undefined) {
        return (
            <div className="flex min-w-0 flex-col gap-(--section-gap)">
                <Header />
                <Alert tone="info">{t('translations.oneLanguage')}</Alert>
            </div>
        );
    }

    const targets = data.locales.filter((language) => language.code !== source.code);
    const aiAvailable = standing.data?.ai.available;
    const groups = groupBySource(data.entries, data.sources);

    return (
        <div className="flex min-w-0 flex-col gap-(--section-gap)">
            <Header />

            <section className="flex flex-col gap-3 border border-(--border-default) bg-(--surface-raised) p-3">
                <div className="flex flex-wrap items-end gap-4">
                    <div className="flex flex-col gap-1">
                        <span className="text-(length:--text-xs) text-(--text-muted)">
                            {t('translations.from')}
                        </span>
                        {/* Not a control. There is one default language and it is the
                            one everything falls back to; offering a choice here would
                            imply the platform could be read from somewhere else. */}
                        <span className="text-(length:--text-sm) text-(--text-primary)">
                            {source.native_name}
                        </span>
                    </div>

                    <div className="flex flex-col gap-1">
                        <label
                            className="text-(length:--text-xs) text-(--text-muted)"
                            htmlFor="translation-target"
                        >
                            {t('translations.into')}
                        </label>
                        <select
                            className="h-(--field-height) border border-(--border-strong) bg-(--surface-default) px-2 text-(length:--text-sm) text-(--text-primary) focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-(--focus-ring)"
                            id="translation-target"
                            onChange={(event) => update({ target: event.target.value, page: null })}
                            value={target.code}
                        >
                            {targets.map((language) => (
                                <option key={language.code} value={language.code}>
                                    {language.is_active
                                        ? language.native_name
                                        : t('translations.notServed', {
                                              language: language.native_name,
                                          })}
                                </option>
                            ))}
                        </select>
                    </div>

                    <CoverageMeter
                        className="w-full max-w-72 min-w-48 flex-1"
                        counts={data.coverage}
                    />

                    {mayUseAi ? (
                        <div className="flex flex-col gap-1">
                            <Button
                                disabled={aiAvailable === false}
                                loading={ask.isPending}
                                onClick={() => ask.mutate(target.code)}
                                size="sm"
                                variant="secondary"
                            >
                                <Sparkles aria-hidden className="size-3.5" />
                                {t('translations.suggestion.askFor', {
                                    language: target.native_name,
                                })}
                            </Button>
                            {/* Said before it is pressed: the alternative is an operator
                                discovering it after a bill, or after a refusal. */}
                            <span className="max-w-72 text-(length:--text-2xs) text-(--text-muted)">
                                {aiAvailable === false
                                    ? t('languages.aiUnavailableNotConfigured')
                                    : t('translations.suggestion.askNote')}
                            </span>
                        </div>
                    ) : null}
                </div>

                {target.is_active ? null : (
                    <Alert tone="info">
                        {t('translations.draftTarget', { language: target.native_name })}
                    </Alert>
                )}

                <div className="flex flex-wrap items-end justify-between gap-3 border-t border-(--border-default) pt-3">
                    <div className="flex flex-col gap-1">
                        <span className="text-(length:--text-xs) text-(--text-muted)">
                            {t('translations.filter')}
                        </span>
                        <SegmentedControl<WorkshopState>
                            label={t('translations.filter')}
                            onChange={(value) =>
                                update({ state: value === 'all' ? null : value, page: null })
                            }
                            options={WORKSHOP_STATES.map((value) => ({
                                value,
                                label: t(`translations.filters.${value}`),
                            }))}
                            value={state}
                        />
                    </div>

                    <form
                        className="flex items-end gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            update({ search: searchDraft.trim(), page: null });
                        }}
                        role="search"
                    >
                        <div className="flex flex-col gap-1">
                            <label
                                className="text-(length:--text-xs) text-(--text-muted)"
                                htmlFor="translation-search"
                            >
                                {t('translations.search')}
                            </label>
                            <Input
                                id="translation-search"
                                onChange={(event) => setSearchDraft(event.target.value)}
                                placeholder={t('translations.searchPlaceholder')}
                                type="search"
                                value={searchDraft}
                            />
                        </div>
                        <Button size="sm" type="submit" variant="secondary">
                            <Search aria-hidden className="size-3.5" />
                            {t('translations.search')}
                        </Button>
                    </form>
                </div>
            </section>

            {ask.error !== null ? (
                <Alert tone="danger">
                    {ask.error instanceof ApiError ? ask.error.message : t('state.error')}
                </Alert>
            ) : null}

            {ask.data !== undefined ? (
                <Alert tone="info">
                    {t('translations.suggestion.asked', {
                        queued: ask.data.queued,
                        skipped: ask.data.skipped,
                    })}
                </Alert>
            ) : null}

            {data.sources.length === 0 ? (
                <Alert tone="info">{t('translations.nothingReadable')}</Alert>
            ) : null}

            <p className="text-(length:--text-sm) text-(--text-secondary)" role="status">
                {t('translations.results', { count: data.pagination.total })}
            </p>

            {data.sources.length > 0 && data.entries.length === 0 ? (
                <p className="border border-(--border-default) bg-(--surface-raised) p-4 text-(length:--text-sm) text-(--text-muted)">
                    {state === 'missing' && search === ''
                        ? t('translations.noneOutstanding')
                        : t('translations.noMatches')}
                </p>
            ) : null}

            {groups.map((group, index) => (
                <section
                    className="flex min-w-0 flex-col gap-2 border border-(--border-default) bg-(--surface-raised) p-4"
                    key={`${group.source.key}-${index}`}
                >
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 className="text-(length:--text-md) font-medium text-(--text-primary)">
                            {group.source.label}
                        </h2>
                        {/* Fields, not items. A template with an Arabic subject over an
                            English body is not half translated in any sense a recipient
                            would recognise, and the number says so. */}
                        <p className="text-(length:--text-sm) text-(--text-secondary)">
                            {group.source.completeness.total ===
                            group.source.completeness.translated
                                ? t('translations.complete', { language: target.native_name })
                                : t('translations.outstanding', {
                                      count:
                                          group.source.completeness.total -
                                          group.source.completeness.translated,
                                      total: group.source.completeness.total,
                                  })}
                        </p>
                    </div>

                    {group.source.may_write ? null : (
                        <p className="text-(length:--text-xs) text-(--text-muted)">
                            {t('translations.readOnly')}
                        </p>
                    )}

                    {group.entries.map((entry) => (
                        <TranslationEntryRow
                            entry={entry}
                            key={`${entry.source}:${entry.id}`}
                            mayWrite={group.source.may_write}
                            onAcceptSuggestion={(id, text) => decide.mutateAsync({ id, text })}
                            onDismissSuggestion={(id) => decide.mutateAsync({ id })}
                            onSave={(values) =>
                                save.mutateAsync({
                                    source: entry.source,
                                    id: entry.id,
                                    locale: target.code,
                                    values,
                                })
                            }
                            source={source}
                            suggestions={forEntry(proposals.data ?? [], entry.source, entry.id)}
                            target={target}
                        />
                    ))}
                </section>
            ))}

            {data.pagination.last_page > 1 ? (
                <nav
                    aria-label={t('translations.page', {
                        page: data.pagination.page,
                        last: data.pagination.last_page,
                    })}
                    className="flex items-center justify-between gap-3"
                >
                    <Button
                        disabled={data.pagination.page <= 1}
                        onClick={() => update({ page: String(data.pagination.page - 1) })}
                        size="sm"
                        variant="secondary"
                    >
                        {t('translations.previous')}
                    </Button>
                    <span
                        className="text-(length:--text-sm) text-(--text-secondary)"
                        data-technical
                    >
                        {t('translations.page', {
                            page: data.pagination.page,
                            last: data.pagination.last_page,
                        })}
                    </span>
                    <Button
                        disabled={data.pagination.page >= data.pagination.last_page}
                        onClick={() => update({ page: String(data.pagination.page + 1) })}
                        size="sm"
                        variant="secondary"
                    >
                        {t('translations.next')}
                    </Button>
                </nav>
            ) : null}
        </div>
    );
}

function Header() {
    const { t } = useTranslation();

    return (
        <header>
            <p data-eyebrow>{t('translations.eyebrow')}</p>
            <h1 className="text-(length:--text-2xl) text-(--text-primary)">
                {t('modules.translations')}
            </h1>
            <p className="mt-1 max-w-prose text-(length:--text-sm) text-(--text-secondary)">
                {t('translations.description')}
            </p>
        </header>
    );
}

/**
 * The page's entries, gathered under the body of content each belongs to.
 *
 * The server returns one ordered list, source by source, so consecutive entries share
 * a heading; the heading's counts are the server's, for the whole source rather than
 * this page.
 */
function groupBySource(
    entries: TranslationEntry[],
    sources: TranslationSource[],
): { source: TranslationSource; entries: TranslationEntry[] }[] {
    const groups: { source: TranslationSource; entries: TranslationEntry[] }[] = [];

    for (const entry of entries) {
        const last = groups[groups.length - 1];

        if (last !== undefined && last.source.key === entry.source) {
            last.entries.push(entry);
            continue;
        }

        const source = sources.find((candidate) => candidate.key === entry.source);

        if (source !== undefined) {
            groups.push({ source, entries: [entry] });
        }
    }

    return groups;
}

/**
 * The suggestions belonging to one item, keyed by field.
 *
 * The list arrives flat because it is addressed the way the workshop addresses
 * anything — source, item, field, locale (ADR 0043) — and grouping it here keeps that
 * address the API's rather than a shape the client invented.
 */
function forEntry(
    suggestions: Suggestion[],
    sourceKey: string,
    itemId: string,
): Record<string, Suggestion> {
    const mine: Record<string, Suggestion> = {};

    for (const row of suggestions) {
        if (row.source === sourceKey && row.item_id === itemId) {
            mine[row.field] = row;
        }
    }

    return mine;
}
