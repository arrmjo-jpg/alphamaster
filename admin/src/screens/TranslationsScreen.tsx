import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { TFunction } from 'i18next';
import { CheckCheck, Search, Sparkles } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router';

import { ApiError } from '@/api/errors';
import { useCurrentUser } from '@/auth/AuthProvider';
import { CoverageMeter } from '@/screens/languages/Coverage';
import { TranslationEntryRow } from '@/screens/translations/TranslationEntryRow';
import {
    acceptAllReady,
    acceptTranslation,
    dismissTranslation,
    ITEM_STATUSES,
    overview as fetchOverview,
    translateWithAi,
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

/** Items that "translate all missing" would start: not translated, incomplete, or failed. */
function outstanding(source: TranslationSource): number {
    return source.statuses.not_translated + source.statuses.incomplete + source.statuses.failed;
}

/**
 * What the platform says, in every language it says it in.
 *
 * The languages workspace manages *which* languages exist. This is what is written in them —
 * and a language added there is here at once, with every item in it not translated, because
 * nothing has to be set up per language (ADR 0056).
 *
 * The item is the unit. Each has one status in the target language and a count of the fields
 * written ("3 / 5"). An operator translates a whole language, a source or one item with AI in
 * one action, reviews an item once, and accepts it once — or accepts every ready item at once,
 * item by item. Nothing is ever asked per field, and nothing is saved until it is accepted.
 *
 * Queried, not downloaded (ADR 0048 §4). The target, the filter, the search and the page live in
 * the address, so the Languages screen can link straight to "what is ready in French" and a
 * reload lands where the operator was.
 *
 * Permissions are per body of content, not per screen (ADR 0043 §3). The API decides what is
 * visible and what is writable; this reflects the decision rather than making one.
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
        // The previous page stays on screen while the next loads.
        placeholderData: keepPreviousData,
        // A translation arrives when it arrives — generation runs on a queue (ADR 0044 §4) —
        // so the workshop follows while any item is being translated, and stops after.
        refetchInterval: (current) =>
            (current.state.data?.sources ?? []).some((source) => source.statuses.pending > 0)
                ? 3000
                : false,
    });

    // Whether AI is there at all, read from the platform rather than assumed, so the action
    // explains its own absence instead of being a button that always fails.
    const standing = useQuery({
        queryKey: ['translation-overview'],
        queryFn: ({ signal }) => fetchOverview(signal),
        enabled: mayUseAi,
    });

    const refreshAll = async () => {
        await queryClient.invalidateQueries({ queryKey: ['translations'] });
        await queryClient.invalidateQueries({ queryKey: ['translation-overview'] });
        // An accepted interface translation changes the console's own wording (ADR 0049).
        await queryClient.invalidateQueries({ queryKey: ['interface-catalogue'] });
    };

    const translateMissing = useMutation({
        mutationFn: (scope: { locale: string; source?: string }) => translateWithAi(scope),
        onSuccess: refreshAll,
    });

    const acceptReady = useMutation({
        mutationFn: (locale: string) => acceptAllReady({ locale }),
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

    const writable = data.sources.filter((candidate) => candidate.may_write);
    const readyCount = writable.reduce((sum, candidate) => sum + candidate.statuses.ready, 0);
    const missingCount = writable.reduce((sum, candidate) => sum + outstanding(candidate), 0);

    const titleOf = (sourceKey: string, itemId: string): string =>
        data.entries.find((entry) => entry.source === sourceKey && entry.id === itemId)?.title ??
        itemId;

    const refusals = (acceptReady.data?.results ?? []).filter((row) => row.status === 'failed');

    return (
        <div className="flex min-w-0 flex-col gap-(--section-gap)">
            <Header />

            <section className="flex flex-col gap-3 border border-(--border-default) bg-(--surface-raised) p-3">
                <div className="flex flex-wrap items-end gap-4">
                    <div className="flex flex-col gap-1">
                        <span className="text-(length:--text-xs) text-(--text-muted)">
                            {t('translations.from')}
                        </span>
                        {/* Not a control. There is one default language and it is the one
                            everything is translated from. */}
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

                    <div className="flex flex-wrap items-end gap-2">
                        {mayUseAi ? (
                            <div className="flex flex-col gap-1">
                                <Button
                                    disabled={aiAvailable === false || missingCount === 0}
                                    loading={
                                        translateMissing.isPending &&
                                        translateMissing.variables.source === undefined
                                    }
                                    onClick={() => translateMissing.mutate({ locale: target.code })}
                                    size="sm"
                                    variant="secondary"
                                >
                                    <Sparkles aria-hidden className="size-3.5" />
                                    {t('translations.batch.translateAll', {
                                        language: target.native_name,
                                    })}
                                </Button>
                                {/* Said before it is pressed: the alternative is an operator
                                    discovering it after a bill, or after a refusal. */}
                                <span className="max-w-72 text-(length:--text-2xs) text-(--text-muted)">
                                    {aiAvailable === false
                                        ? t('languages.aiUnavailableNotConfigured')
                                        : t('translations.batch.translateAllNote')}
                                </span>
                            </div>
                        ) : null}

                        {readyCount > 0 ? (
                            <Button
                                loading={acceptReady.isPending}
                                onClick={() => acceptReady.mutate(target.code)}
                                size="sm"
                                variant="primary"
                            >
                                <CheckCheck aria-hidden className="size-3.5" />
                                {t('translations.batch.acceptAllReady', { ready: readyCount })}
                            </Button>
                        ) : null}
                    </div>
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

            {translateMissing.error !== null ? (
                <Alert tone="danger">
                    {translateMissing.error instanceof ApiError
                        ? translateMissing.error.message
                        : t('state.error')}
                </Alert>
            ) : null}

            {translateMissing.data !== undefined ? (
                <Alert tone="info">
                    {t('translations.batch.started', {
                        queued: translateMissing.data.queued,
                        existing: translateMissing.data.existing,
                        skipped: translateMissing.data.skipped,
                    })}
                </Alert>
            ) : null}

            {acceptReady.error !== null ? (
                <Alert tone="danger">
                    {acceptReady.error instanceof ApiError
                        ? acceptReady.error.message
                        : t('state.error')}
                </Alert>
            ) : null}

            {acceptReady.data !== undefined ? (
                <Alert tone={acceptReady.data.failed > 0 ? 'warning' : 'success'}>
                    <p>
                        {t('translations.batch.acceptedSummary', {
                            accepted: acceptReady.data.accepted,
                            failed: acceptReady.data.failed,
                        })}
                    </p>
                    {refusals.length > 0 ? (
                        <ul className="mt-1 list-disc ps-4 text-(length:--text-xs)">
                            {refusals.map((row) => (
                                <li key={row.batch}>
                                    {t('translations.batch.refusedItem', {
                                        item: titleOf(row.source, row.item_id),
                                        reason: row.message ?? row.error_code ?? '',
                                    })}
                                </li>
                            ))}
                        </ul>
                    ) : null}
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
                    {state === 'not_translated' && search === ''
                        ? t('translations.noneOutstanding')
                        : t('translations.noMatches')}
                </p>
            ) : null}

            {groups.map((group, index) => (
                <section
                    className="flex min-w-0 flex-col gap-2 border border-(--border-default) bg-(--surface-raised) p-4"
                    key={`${group.source.key}-${index}`}
                >
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="flex min-w-0 flex-col gap-0.5">
                            <h2 className="text-(length:--text-md) font-medium text-(--text-primary)">
                                {group.source.label}
                            </h2>
                            {/* Items, not fields: the counts are the server's, for the whole
                                source rather than this page. */}
                            <p className="text-(length:--text-sm) text-(--text-secondary)">
                                {sourceSummary(group.source, t)}
                            </p>
                        </div>

                        {mayUseAi && group.source.may_write && outstanding(group.source) > 0 ? (
                            <Button
                                disabled={aiAvailable === false}
                                loading={
                                    translateMissing.isPending &&
                                    translateMissing.variables.source === group.source.key
                                }
                                onClick={() =>
                                    translateMissing.mutate({
                                        locale: target.code,
                                        source: group.source.key,
                                    })
                                }
                                size="sm"
                                variant="ghost"
                            >
                                <Sparkles aria-hidden className="size-3.5" />
                                {t('translations.batch.translateSource')}
                            </Button>
                        ) : null}
                    </div>

                    {group.source.may_write ? null : (
                        <p className="text-(length:--text-xs) text-(--text-muted)">
                            {t('translations.readOnly')}
                        </p>
                    )}

                    {group.entries.map((entry) => (
                        <TranslationEntryRow
                            ai={{ mayUse: mayUseAi, available: aiAvailable }}
                            entry={entry}
                            key={`${entry.source}:${entry.id}`}
                            mayWrite={group.source.may_write}
                            onAccept={async (batchId, values) => {
                                await acceptTranslation(batchId, values);
                                await refreshAll();
                            }}
                            onDismiss={async (batchId) => {
                                await dismissTranslation(batchId);
                                await refreshAll();
                            }}
                            onSave={async (values) => {
                                await writeTranslation(entry.source, entry.id, {
                                    locale: target.code,
                                    values,
                                });
                                await refreshAll();
                            }}
                            onTranslate={async () => {
                                await translateWithAi({
                                    locale: target.code,
                                    source: entry.source,
                                    item: entry.id,
                                });
                                await refreshAll();
                            }}
                            // Each source's own language: the interface is translated from
                            // its catalogue's, content from the default (ADR 0049).
                            source={
                                data.locales.find(
                                    (language) => language.code === group.source.source_locale,
                                ) ?? source
                            }
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

/** "Items: 25 · Translated: 5 · Incomplete: 8 · Not translated: 12" — only what is there. */
function sourceSummary(source: TranslationSource, t: TFunction): string {
    const total = ITEM_STATUSES.reduce((sum, status) => sum + source.statuses[status], 0);
    const parts = [t('translations.source.items', { total })];

    for (const status of ITEM_STATUSES) {
        if (source.statuses[status] > 0) {
            parts.push(
                t('translations.source.status', {
                    status: t(`translations.filters.${status}`),
                    number: source.statuses[status],
                }),
            );
        }
    }

    return parts.join(' · ');
}

/**
 * The page's entries, gathered under the body of content each belongs to.
 *
 * The server returns one ordered list, source by source, so consecutive entries share a
 * heading; the heading's counts are the server's, for the whole source rather than this page.
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
