import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/errors';
import { useCurrentUser } from '@/auth/AuthProvider';
import { initialContentLanguage } from '@/lib/contentLanguages';
import { ContentTextArea } from '@/screens/content/ContentFields';
import { MediaImageField } from '@/screens/content/MediaImageField';
import { seoChanged, seoDraftFor, seoWrite, type SeoDraft } from '@/screens/content/seo';
import { SeoFieldsEditor } from '@/screens/content/SeoFieldsEditor';
import { languageNames, textOrNull } from '@/screens/content/text';
import { languages as fetchLanguages, type AdminLanguage } from '@/screens/languages/api';
import {
    createMember,
    deleteMember,
    members as fetchMembers,
    SOCIAL_NETWORKS,
    updateMember,
    writeProfile,
    type AdminTeamMember,
    type ProfileWrite,
    type SocialLinks,
} from '@/screens/team/api';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';
import { ContentLanguageSelector } from '@/ui/ContentLanguageSelector';
import { Field } from '@/ui/Field';
import { Input } from '@/ui/Input';
import { StateRail } from '@/ui/StateRail';
import { StatusBadge } from '@/ui/StatusBadge';
import { TranslationStatusList } from '@/ui/TranslationStatusList';

/**
 * The team directory (ADR 0055).
 *
 * A member is one record with a profile in every language Language Management knows. The
 * editor names the content language it writes, lists every known language with its status,
 * and never fills one language's fields with another's text. What is the same in every language —
 * being active, the picture, the social links — is edited once, beside the profile. Images come
 * from the platform's media capability through the shared field (ADR 0057 §4).
 */
export function TeamScreen() {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const viewer = useCurrentUser();
    const [selected, setSelected] = useState<string | null>(null);

    const list = useQuery({
        queryKey: ['admin-team'],
        queryFn: ({ signal }) => fetchMembers(signal),
    });
    const known = useQuery({
        queryKey: ['admin-languages'],
        queryFn: ({ signal }) => fetchLanguages(signal),
    });

    const create = useMutation({
        mutationFn: () => createMember({ sort_order: list.data?.length ?? 0 }),
        onSuccess: async (member) => {
            await queryClient.invalidateQueries({ queryKey: ['admin-team'] });
            setSelected(member.id);
        },
    });

    if (list.isPending || known.isPending) {
        return <StateRail tone="info">{t('state.loading')}</StateRail>;
    }

    if (list.error !== null || known.error !== null) {
        return (
            <div className="flex min-w-0 flex-col gap-(--section-gap)">
                <Header />
                <Alert tone="danger">{t('team.unavailable')}</Alert>
            </div>
        );
    }

    const member = list.data.find((candidate) => candidate.id === selected) ?? null;

    return (
        <div className="flex min-w-0 flex-col gap-(--section-gap)">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <Header />
                {viewer.permissions.includes('team.create') ? (
                    <Button
                        loading={create.isPending}
                        onClick={() => create.mutate()}
                        size="sm"
                        variant="primary"
                    >
                        <Plus aria-hidden className="size-3.5" />
                        {t('team.new')}
                    </Button>
                ) : null}
            </div>

            {create.error instanceof ApiError ? (
                <Alert tone="danger">{create.error.message}</Alert>
            ) : null}

            <div className="grid min-w-0 gap-4 lg:grid-cols-[minmax(14rem,20rem)_1fr]">
                <nav
                    aria-label={t('modules.team')}
                    className="flex flex-col border border-(--border-default) bg-(--surface-raised)"
                >
                    {list.data.length === 0 ? (
                        <p className="p-4 text-(length:--text-sm) text-(--text-muted)">
                            {t('team.empty')}
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
                                            {item.name ?? t('team.unnamed')}
                                        </span>
                                        <StatusBadge tone={item.is_active ? 'success' : 'pending'}>
                                            {item.is_active ? t('team.active') : t('team.inactive')}
                                        </StatusBadge>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </nav>

                {member === null ? (
                    <p className="border border-(--border-default) bg-(--surface-raised) p-4 text-(length:--text-sm) text-(--text-muted)">
                        {t('team.choose')}
                    </p>
                ) : (
                    <MemberEditor
                        key={member.id}
                        languages={known.data}
                        member={member}
                        onDeleted={() => setSelected(null)}
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
            <p data-eyebrow>{t('team.eyebrow')}</p>
            <h1 className="text-(length:--text-2xl) text-(--text-primary)">{t('modules.team')}</h1>
            <p className="mt-1 max-w-prose text-(length:--text-sm) text-(--text-secondary)">
                {t('team.description')}
            </p>
        </header>
    );
}

interface ProfileDraft {
    name: string;
    position: string;
    slug: string;
    bio: string;
    seo: SeoDraft;
}

const PROFILE_FIELDS = ['name', 'position', 'slug', 'bio'] as const;

function draftFor(member: AdminTeamMember, locale: string): ProfileDraft {
    const written = member.translations[locale];

    return {
        name: written?.name ?? '',
        position: written?.position ?? '',
        slug: written?.slug ?? '',
        bio: written?.bio ?? '',
        seo: seoDraftFor(member.seo[locale]),
    };
}

function linksFor(member: AdminTeamMember): Record<string, string> {
    return Object.fromEntries(
        SOCIAL_NETWORKS.map((network) => [network, member.social_links[network] ?? '']),
    );
}

function MemberEditor({
    member,
    languages,
    permissions,
    onDeleted,
}: {
    member: AdminTeamMember;
    languages: AdminLanguage[];
    permissions: string[];
    onDeleted: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();

    const mayUpdate = permissions.includes('team.update');
    const mayDelete = permissions.includes('team.delete');

    const [locale, setLocale] = useState<string>(
        () => initialContentLanguage(languages) ?? member.default_locale,
    );
    const [draft, setDraft] = useState<ProfileDraft>(() => draftFor(member, locale));
    const [links, setLinks] = useState<Record<string, string>>(() => linksFor(member));
    const [confirmingDelete, setConfirmingDelete] = useState(false);

    const refresh = () => queryClient.invalidateQueries({ queryKey: ['admin-team'] });

    const base = draftFor(member, locale);
    const changes: ProfileWrite = {};

    for (const field of PROFILE_FIELDS) {
        if (draft[field] !== base[field]) {
            changes[field] = textOrNull(draft[field]);
        }
    }

    // A language's SEO is replaced as a whole, and every field is in the editor.
    if (seoChanged(draft.seo, base.seo)) {
        changes.seo = seoWrite(draft.seo);
    }

    const dirty = Object.keys(changes).length > 0;
    const savedLinks = linksFor(member);
    const linksDirty = SOCIAL_NETWORKS.some((network) => links[network] !== savedLinks[network]);

    const save = useMutation({
        mutationFn: () => writeProfile(member.id, locale, changes),
        onSuccess: async (next) => {
            setDraft(draftFor(next, locale));
            await refresh();
        },
    });

    const shared = useMutation({
        mutationFn: (body: {
            is_active?: boolean;
            social_links?: SocialLinks;
            avatar_media_id?: string | null;
        }) => updateMember(member.id, body),
        onSuccess: async (next) => {
            setLinks(linksFor(next));
            await refresh();
        },
    });

    const remove = useMutation({
        mutationFn: () => deleteMember(member.id),
        onSuccess: async () => {
            await refresh();
            onDeleted();
        },
    });

    const switchTo = (code: string): void => {
        setLocale(code);
        setDraft(draftFor(member, code));
        save.reset();
    };

    const set = (patch: Partial<ProfileDraft>): void => {
        save.reset();
        setDraft((held) => ({ ...held, ...patch }));
    };

    const target = languages.find((language) => language.code === locale);
    const defaultName = languages.find((language) => language.is_default)?.native_name;
    const text = { dir: target?.direction ?? 'ltr', lang: locale };

    return (
        <section
            aria-label={member.name ?? t('team.unnamed')}
            className="flex min-w-0 flex-col gap-4 border border-(--border-default) bg-(--surface-raised) p-4"
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex flex-wrap items-center gap-2">
                    <h2 className="text-(length:--text-lg) text-(--text-primary)">
                        {member.name ?? t('team.unnamed')}
                    </h2>
                    <StatusBadge tone={member.is_active ? 'success' : 'pending'}>
                        {member.is_active ? t('team.active') : t('team.inactive')}
                    </StatusBadge>
                </div>

                <div className="flex flex-wrap gap-2">
                    {mayUpdate ? (
                        <Button
                            disabled={!member.is_active && !member.activatable}
                            loading={shared.isPending && shared.variables.is_active !== undefined}
                            onClick={() => shared.mutate({ is_active: !member.is_active })}
                            size="sm"
                            variant={member.is_active ? 'secondary' : 'primary'}
                        >
                            {member.is_active ? t('team.deactivate') : t('team.activate')}
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
                                {t('team.confirmDelete')}
                            </Button>
                            <Button
                                onClick={() => setConfirmingDelete(false)}
                                size="sm"
                                variant="ghost"
                            >
                                {t('team.cancel')}
                            </Button>
                        </>
                    ) : null}
                    {mayDelete && !confirmingDelete ? (
                        <Button onClick={() => setConfirmingDelete(true)} size="sm" variant="ghost">
                            <Trash2 aria-hidden className="size-3.5" />
                            {t('team.delete')}
                        </Button>
                    ) : null}
                </div>
            </div>

            {mayUpdate && !member.is_active && !member.activatable ? (
                <p className="text-(length:--text-xs) text-(--text-muted)">
                    {t('team.activateNeeds', { language: defaultName ?? member.default_locale })}
                </p>
            ) : null}

            <p className="text-(length:--text-xs) text-(--text-secondary)">
                {member.available_locales.length === 0
                    ? t('team.publicNowhere')
                    : t('team.publicIn', {
                          languages: languageNames(member.available_locales, languages),
                      })}
            </p>

            {shared.error instanceof ApiError ? (
                <Alert tone="danger">{shared.error.message}</Alert>
            ) : null}
            {remove.error instanceof ApiError ? (
                <Alert tone="danger">{remove.error.message}</Alert>
            ) : null}

            <div className="grid min-w-0 gap-4 md:grid-cols-[1fr_15rem]">
                <div className="flex min-w-0 flex-col gap-3">
                    <ContentLanguageSelector
                        id="member-content-language"
                        languages={languages}
                        onChange={switchTo}
                        value={locale}
                    />

                    {mayUpdate ? null : <Alert tone="info">{t('team.readOnly')}</Alert>}

                    <Field label={t('team.fields.name')} required>
                        {({ id, 'aria-describedby': describedBy }) => (
                            <Input
                                {...text}
                                aria-describedby={describedBy}
                                disabled={!mayUpdate}
                                id={id}
                                onChange={(event) => set({ name: event.target.value })}
                                value={draft.name}
                            />
                        )}
                    </Field>

                    <Field label={t('team.fields.position')} required>
                        {({ id, 'aria-describedby': describedBy }) => (
                            <Input
                                {...text}
                                aria-describedby={describedBy}
                                disabled={!mayUpdate}
                                id={id}
                                onChange={(event) => set({ position: event.target.value })}
                                value={draft.position}
                            />
                        )}
                    </Field>

                    <Field hint={t('team.fields.slugHint')} label={t('team.fields.slug')}>
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
                        hint={t('team.fields.bioHint')}
                        label={t('team.fields.bio')}
                        onChange={(value) => set({ bio: value })}
                        rows={6}
                        value={draft.bio}
                    />

                    <SeoFieldsEditor
                        collection="team"
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
                                {t('team.save')}
                            </Button>
                            {save.isSuccess && !dirty ? (
                                <span className="text-(length:--text-xs) text-(--state-success-text)">
                                    {t('team.saved')}
                                </span>
                            ) : null}
                        </div>
                    ) : null}
                </div>

                <TranslationStatusList
                    current={locale}
                    languages={languages}
                    onSelect={switchTo}
                    states={member.progress}
                />
            </div>

            <section className="flex flex-col gap-2 border-t border-(--border-default) pt-3">
                {/* The picture is the same in every language, so it is saved on its own, like
                    the links, rather than with one language's profile. */}
                <MediaImageField
                    busy={shared.isPending && shared.variables.avatar_media_id !== undefined}
                    collection="team"
                    disabled={!mayUpdate}
                    hint={t('team.fields.avatarHint')}
                    label={t('team.fields.avatar')}
                    mediaId={member.avatar_media_id}
                    onChange={(id) => shared.mutate({ avatar_media_id: id })}
                    previewUrl={member.avatar?.url ?? null}
                />
            </section>

            <section className="flex flex-col gap-2 border-t border-(--border-default) pt-3">
                <h3 data-eyebrow>{t('team.social')}</h3>
                <p className="text-(length:--text-xs) text-(--text-muted)">
                    {t('team.socialHint')}
                </p>
                <div className="grid gap-2 sm:grid-cols-2">
                    {SOCIAL_NETWORKS.map((network) => (
                        <Field key={network} label={t(`team.networks.${network}`)}>
                            {({ id, 'aria-describedby': describedBy }) => (
                                <Input
                                    aria-describedby={describedBy}
                                    data-technical
                                    dir="ltr"
                                    disabled={!mayUpdate}
                                    id={id}
                                    onChange={(event) =>
                                        setLinks((held) => ({
                                            ...held,
                                            [network]: event.target.value,
                                        }))
                                    }
                                    type="url"
                                    value={links[network] ?? ''}
                                />
                            )}
                        </Field>
                    ))}
                </div>
                {mayUpdate ? (
                    <div>
                        <Button
                            disabled={!linksDirty}
                            loading={
                                shared.isPending && shared.variables.social_links !== undefined
                            }
                            onClick={() =>
                                shared.mutate({
                                    social_links: Object.fromEntries(
                                        SOCIAL_NETWORKS.map((network) => [
                                            network,
                                            textOrNull(links[network] ?? ''),
                                        ]),
                                    ) as SocialLinks,
                                })
                            }
                            size="sm"
                            variant="secondary"
                        >
                            {t('team.saveLinks')}
                        </Button>
                    </div>
                ) : null}
            </section>
        </section>
    );
}
