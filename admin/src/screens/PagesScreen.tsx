import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/errors';
import { useCurrentUser } from '@/auth/AuthProvider';
import { initialContentLanguage } from '@/lib/contentLanguages';
import { ContentTextArea } from '@/screens/content/ContentFields';
import { seoChanged, seoDraftFor, seoWrite, type SeoDraft } from '@/screens/content/seo';
import { SeoFieldsEditor } from '@/screens/content/SeoFieldsEditor';
import { languageNames, textOrNull } from '@/screens/content/text';
import { languages as fetchLanguages, type AdminLanguage } from '@/screens/languages/api';
import {
    archivePage,
    createPage,
    deletePage,
    pages as fetchPages,
    publishPage,
    unpublishPage,
    writePageTranslation,
    type AdminPage,
    type PageTranslationWrite,
} from '@/screens/pages/api';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';
import { ContentLanguageSelector } from '@/ui/ContentLanguageSelector';
import { Field } from '@/ui/Field';
import { Input } from '@/ui/Input';
import type { StateTone } from '@/ui/state';
import { StateRail } from '@/ui/StateRail';
import { StatusBadge } from '@/ui/StatusBadge';
import { TranslationStatusList } from '@/ui/TranslationStatusList';

const PAGE_STATUS_TONE: Record<string, StateTone> = {
    published: 'success',
    draft: 'pending',
    archived: 'neutral',
};

/**
 * Static pages (ADR 0055).
 *
 * A page is one record, written in every language Language Management knows. The editor names
 * the content language it is writing — a selector that lists every known language, the default
 * first, and each language's status beside it — and shows only what has been written in that
 * language. The default language's text is never placed in another language's fields.
 *
 * Publishing is separate from translating. A page needs a complete default-language
 * translation to be published; any other language may be missing, and is public only where it
 * is complete.
 */
export function PagesScreen() {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const viewer = useCurrentUser();
    const [selected, setSelected] = useState<string | null>(null);

    const list = useQuery({
        queryKey: ['admin-pages'],
        queryFn: ({ signal }) => fetchPages(signal),
    });
    const known = useQuery({
        queryKey: ['admin-languages'],
        queryFn: ({ signal }) => fetchLanguages(signal),
    });

    const create = useMutation({
        mutationFn: () => createPage({ sort_order: list.data?.length ?? 0 }),
        onSuccess: async (page) => {
            await queryClient.invalidateQueries({ queryKey: ['admin-pages'] });
            setSelected(page.id);
        },
    });

    if (list.isPending || known.isPending) {
        return <StateRail tone="info">{t('state.loading')}</StateRail>;
    }

    if (list.error !== null || known.error !== null) {
        return (
            <div className="flex min-w-0 flex-col gap-(--section-gap)">
                <Header />
                <Alert tone="danger">{t('pages.unavailable')}</Alert>
            </div>
        );
    }

    const page = list.data.find((candidate) => candidate.id === selected) ?? null;

    return (
        <div className="flex min-w-0 flex-col gap-(--section-gap)">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <Header />
                {viewer.permissions.includes('pages.create') ? (
                    <Button
                        loading={create.isPending}
                        onClick={() => create.mutate()}
                        size="sm"
                        variant="primary"
                    >
                        <Plus aria-hidden className="size-3.5" />
                        {t('pages.new')}
                    </Button>
                ) : null}
            </div>

            {create.error instanceof ApiError ? (
                <Alert tone="danger">{create.error.message}</Alert>
            ) : null}

            <div className="grid min-w-0 gap-4 lg:grid-cols-[minmax(14rem,20rem)_1fr]">
                <nav
                    aria-label={t('modules.pages')}
                    className="flex flex-col border border-(--border-default) bg-(--surface-raised)"
                >
                    {list.data.length === 0 ? (
                        <p className="p-4 text-(length:--text-sm) text-(--text-muted)">
                            {t('pages.empty')}
                        </p>
                    ) : (
                        <ul className="flex flex-col">
                            {list.data.map((item) => (
                                <li key={item.id}>
                                    <button
                                        aria-current={item.id === selected ? 'true' : undefined}
                                        className="flex w-full items-center gap-2 border-s-(length:--rail-width) border-transparent px-3 py-2 text-start text-(length:--text-sm) hover:bg-(--surface-subtle) aria-[current=true]:border-(--action-primary) aria-[current=true]:bg-(--surface-subtle)"
                                        onClick={() => setSelected(item.id)}
                                        type="button"
                                    >
                                        <span className="min-w-0 flex-1 truncate text-(--text-primary)">
                                            {item.title ?? t('pages.untitled')}
                                        </span>
                                        <StatusBadge
                                            tone={PAGE_STATUS_TONE[item.status] ?? 'neutral'}
                                        >
                                            {item.status_label}
                                        </StatusBadge>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </nav>

                {page === null ? (
                    <p className="border border-(--border-default) bg-(--surface-raised) p-4 text-(length:--text-sm) text-(--text-muted)">
                        {t('pages.choose')}
                    </p>
                ) : (
                    <PageEditor
                        key={page.id}
                        languages={known.data}
                        onDeleted={() => setSelected(null)}
                        page={page}
                        permissions={viewer.permissions}
                    />
                )}
            </div>
        </div>
    );
}

function Header() {
    const { t } = useTranslation();

    return (
        <header>
            <p data-eyebrow>{t('pages.eyebrow')}</p>
            <h1 className="text-(length:--text-2xl) text-(--text-primary)">{t('modules.pages')}</h1>
            <p className="mt-1 max-w-prose text-(length:--text-sm) text-(--text-secondary)">
                {t('pages.description')}
            </p>
        </header>
    );
}

interface PageDraft {
    title: string;
    slug: string;
    summary: string;
    body: string;
    seo: SeoDraft;
}

const CONTENT_FIELDS = ['title', 'slug', 'summary', 'body'] as const;

/** What is written in one language — and nothing, never another language's text, where it is not. */
function draftFor(page: AdminPage, locale: string): PageDraft {
    const written = page.translations[locale];

    return {
        title: written?.title ?? '',
        slug: written?.slug ?? '',
        summary: written?.summary ?? '',
        body: written?.body ?? '',
        seo: seoDraftFor(page.seo[locale]),
    };
}

function PageEditor({
    page,
    languages,
    permissions,
    onDeleted,
}: {
    page: AdminPage;
    languages: AdminLanguage[];
    permissions: string[];
    onDeleted: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();

    const mayUpdate = permissions.includes('pages.update');
    const mayPublish = permissions.includes('pages.publish');
    const mayDelete = permissions.includes('pages.delete');

    const [locale, setLocale] = useState<string>(
        () => initialContentLanguage(languages) ?? page.default_locale,
    );
    const [draft, setDraft] = useState<PageDraft>(() => draftFor(page, locale));
    const [confirmingDelete, setConfirmingDelete] = useState(false);

    const refresh = () => queryClient.invalidateQueries({ queryKey: ['admin-pages'] });

    const base = draftFor(page, locale);
    const changes: PageTranslationWrite = {};

    for (const field of CONTENT_FIELDS) {
        if (draft[field] !== base[field]) {
            changes[field] = textOrNull(draft[field]);
        }
    }

    // A language's SEO is replaced as a whole, and every field is in the editor.
    if (seoChanged(draft.seo, base.seo)) {
        changes.seo = seoWrite(draft.seo);
    }

    const dirty = Object.keys(changes).length > 0;

    const save = useMutation({
        mutationFn: () => writePageTranslation(page.id, locale, changes),
        onSuccess: async (next) => {
            setDraft(draftFor(next, locale));
            await refresh();
        },
    });

    const transition = useMutation({
        mutationFn: (action: 'publish' | 'unpublish' | 'archive') =>
            action === 'publish'
                ? publishPage(page.id)
                : action === 'unpublish'
                  ? unpublishPage(page.id)
                  : archivePage(page.id),
        onSuccess: refresh,
    });

    const remove = useMutation({
        mutationFn: () => deletePage(page.id),
        onSuccess: async () => {
            await refresh();
            onDeleted();
        },
    });

    const switchTo = (code: string): void => {
        setLocale(code);
        setDraft(draftFor(page, code));
        save.reset();
    };

    const set = (patch: Partial<PageDraft>): void => {
        save.reset();
        setDraft((held) => ({ ...held, ...patch }));
    };

    const target = languages.find((language) => language.code === locale);
    const fallbackName = languages.find((language) => language.is_default)?.native_name;
    const text = { dir: target?.direction ?? 'ltr', lang: locale };

    return (
        <section
            aria-label={page.title ?? t('pages.untitled')}
            className="flex min-w-0 flex-col gap-4 border border-(--border-default) bg-(--surface-raised) p-4"
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex flex-wrap items-center gap-2">
                    <h2 className="text-(length:--text-lg) text-(--text-primary)">
                        {page.title ?? t('pages.untitled')}
                    </h2>
                    <StatusBadge tone={PAGE_STATUS_TONE[page.status] ?? 'neutral'}>
                        {page.status_label}
                    </StatusBadge>
                </div>

                <div className="flex flex-wrap gap-2">
                    {mayPublish && page.status !== 'published' ? (
                        <Button
                            disabled={!page.publishable}
                            loading={transition.isPending && transition.variables === 'publish'}
                            onClick={() => transition.mutate('publish')}
                            size="sm"
                            variant="primary"
                        >
                            {t('pages.publish')}
                        </Button>
                    ) : null}
                    {mayPublish && page.status === 'published' ? (
                        <Button
                            loading={transition.isPending && transition.variables === 'unpublish'}
                            onClick={() => transition.mutate('unpublish')}
                            size="sm"
                            variant="secondary"
                        >
                            {t('pages.unpublish')}
                        </Button>
                    ) : null}
                    {mayPublish && page.status !== 'archived' ? (
                        <Button
                            loading={transition.isPending && transition.variables === 'archive'}
                            onClick={() => transition.mutate('archive')}
                            size="sm"
                            variant="ghost"
                        >
                            {t('pages.archive')}
                        </Button>
                    ) : null}
                    {mayDelete && confirmingDelete ? (
                        <>
                            <Button
                                loading={remove.isPending}
                                onClick={() => remove.mutate()}
                                size="sm"
                                variant="danger"
                            >
                                {t('pages.confirmDelete')}
                            </Button>
                            <Button
                                onClick={() => setConfirmingDelete(false)}
                                size="sm"
                                variant="ghost"
                            >
                                {t('pages.cancel')}
                            </Button>
                        </>
                    ) : null}
                    {mayDelete && !confirmingDelete ? (
                        <Button onClick={() => setConfirmingDelete(true)} size="sm" variant="ghost">
                            <Trash2 aria-hidden className="size-3.5" />
                            {t('pages.delete')}
                        </Button>
                    ) : null}
                </div>
            </div>

            {mayPublish && page.status !== 'published' && !page.publishable ? (
                <p className="text-(length:--text-xs) text-(--text-muted)">
                    {t('pages.publishNeeds', { language: fallbackName ?? page.default_locale })}
                </p>
            ) : null}

            <p className="text-(length:--text-xs) text-(--text-secondary)">
                {page.available_locales.length === 0
                    ? t('pages.publicNowhere')
                    : t('pages.publicIn', {
                          languages: languageNames(page.available_locales, languages),
                      })}
            </p>

            {transition.error instanceof ApiError ? (
                <Alert tone="danger">{transition.error.message}</Alert>
            ) : null}
            {remove.error instanceof ApiError ? (
                <Alert tone="danger">{remove.error.message}</Alert>
            ) : null}

            <div className="grid min-w-0 gap-4 md:grid-cols-[1fr_15rem]">
                <div className="flex min-w-0 flex-col gap-3">
                    <ContentLanguageSelector
                        id="page-content-language"
                        languages={languages}
                        onChange={switchTo}
                        value={locale}
                    />

                    {mayUpdate ? null : <Alert tone="info">{t('pages.readOnly')}</Alert>}

                    <Field label={t('pages.fields.title')} required>
                        {({ id, 'aria-describedby': describedBy }) => (
                            <Input
                                {...text}
                                aria-describedby={describedBy}
                                disabled={!mayUpdate}
                                id={id}
                                onChange={(event) => set({ title: event.target.value })}
                                value={draft.title}
                            />
                        )}
                    </Field>

                    <Field hint={t('pages.fields.slugHint')} label={t('pages.fields.slug')}>
                        {({ id, 'aria-describedby': describedBy }) => (
                            <Input
                                {...text}
                                aria-describedby={describedBy}
                                data-technical
                                disabled={!mayUpdate}
                                id={id}
                                onChange={(event) => set({ slug: event.target.value })}
                                value={draft.slug}
                            />
                        )}
                    </Field>

                    <ContentTextArea
                        {...text}
                        disabled={!mayUpdate}
                        label={t('pages.fields.summary')}
                        onChange={(value) => set({ summary: value })}
                        rows={2}
                        value={draft.summary}
                    />

                    <ContentTextArea
                        {...text}
                        disabled={!mayUpdate}
                        hint={t('pages.fields.bodyHint')}
                        label={t('pages.fields.body')}
                        onChange={(value) => set({ body: value })}
                        rows={10}
                        value={draft.body}
                    />

                    <SeoFieldsEditor
                        collection="pages"
                        direction={text.dir}
                        disabled={!mayUpdate}
                        locale={locale}
                        onChange={(seo) => set({ seo })}
                        value={draft.seo}
                    />

                    {save.error instanceof ApiError ? (
                        <Alert tone="danger">{save.error.message}</Alert>
                    ) : null}

                    {mayUpdate ? (
                        <div className="flex items-center gap-3">
                            <Button
                                disabled={!dirty}
                                loading={save.isPending}
                                onClick={() => save.mutate()}
                                size="sm"
                                variant="primary"
                            >
                                {t('pages.save')}
                            </Button>
                            {save.isSuccess && !dirty ? (
                                <span className="text-(length:--text-xs) text-(--state-success-text)">
                                    {t('pages.saved')}
                                </span>
                            ) : null}
                        </div>
                    ) : null}
                </div>

                <TranslationStatusList
                    current={locale}
                    languages={languages}
                    onSelect={switchTo}
                    states={page.progress}
                />
            </div>
        </section>
    );
}
