import { useQuery } from '@tanstack/react-query';
import { History, PanelLeft, X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Route, Routes, useParams } from 'react-router';

import { ApiError } from '@/api/errors';
import { cn } from '@/lib/cn';
import { definitions } from '@/screens/settings/api';
import { ContextPanel } from '@/screens/settings/ContextPanel';
import { GroupNav } from '@/screens/settings/GroupNav';
import { SettingsWorkspace } from '@/screens/settings/SettingsWorkspace';
import { Button } from '@/ui/Button';

/**
 * The settings control centre: three regions, one workstation.
 *
 *     GROUPS │ WORKSPACE │ CONTEXT
 *
 * The catalogue drives all of it. Groups, fields, types, rules, dependencies and the
 * permission each setting needs come from `/admin/settings/definitions`, so a setting
 * added to the platform appears without this screen being edited — and one that names
 * a new permission is guarded without this screen learning what that permission
 * means.
 *
 * The regions collapse in order of how often they are needed: below 1280px the
 * context panel becomes a drawer, below 1024px the group list does too. Neither is
 * dropped — an operator on a laptop still has the history, and one on a phone still
 * has the groups.
 */
export function SettingsScreen() {
    const { t } = useTranslation();

    const [groupsOpen, setGroupsOpen] = useState(false);
    const [contextOpen, setContextOpen] = useState(false);
    const [pendingByGroup, setPendingByGroup] = useState<Record<string, number>>({});

    const catalogue = useQuery({
        queryKey: ['settings-definitions'],
        queryFn: ({ signal }) => definitions(signal),
        // The catalogue is static for the life of a deployment.
        staleTime: 5 * 60_000,
    });

    if (catalogue.isPending) {
        return <p className="text-(--text-muted)">{t('state.loading')}</p>;
    }

    if (catalogue.error !== null) {
        return (
            <p className="text-(--text-danger)">
                {catalogue.error instanceof ApiError ? catalogue.error.message : t('state.error')}
            </p>
        );
    }

    const data = catalogue.data ?? {};

    return (
        <div className="flex h-[calc(100dvh-var(--topbar-height)-var(--page-gutter)*2)] flex-col">
            <div className="flex items-center gap-2 pb-3 xl:hidden">
                <Button
                    className="lg:hidden"
                    onClick={() => setGroupsOpen(true)}
                    size="sm"
                    variant="secondary"
                >
                    <PanelLeft aria-hidden className="size-3.5" />
                    {t('settings.groups')}
                </Button>
                <Button
                    className="ms-auto"
                    onClick={() => setContextOpen(true)}
                    size="sm"
                    variant="secondary"
                >
                    <History aria-hidden className="size-3.5" />
                    {t('settings.history.title')}
                </Button>
            </div>

            <div className="grid min-h-0 flex-1 grid-cols-1 border border-(--border-default) bg-(--surface-default) lg:grid-cols-[220px_1fr] xl:grid-cols-[220px_1fr_340px]">
                <section
                    aria-label={t('settings.groups')}
                    className="hidden min-h-0 overflow-y-auto border-e border-(--border-default) lg:block"
                >
                    <GroupNav catalogue={data} pendingByGroup={pendingByGroup} />
                </section>

                <Routes>
                    <Route element={<Placeholder>{t('settings.chooseGroup')}</Placeholder>} index />
                    <Route
                        element={
                            <SelectedGroup
                                catalogue={data}
                                onPendingChange={(group, count) =>
                                    setPendingByGroup((current) => ({ ...current, [group]: count }))
                                }
                            />
                        }
                        path=":group"
                    />
                </Routes>
            </div>

            {groupsOpen ? (
                <Drawer
                    onClose={() => setGroupsOpen(false)}
                    side="start"
                    title={t('settings.groups')}
                >
                    <GroupNav
                        catalogue={data}
                        onNavigate={() => setGroupsOpen(false)}
                        pendingByGroup={pendingByGroup}
                    />
                </Drawer>
            ) : null}

            {contextOpen ? (
                <Drawer
                    onClose={() => setContextOpen(false)}
                    side="end"
                    title={t('settings.history.title')}
                >
                    <ContextDrawerBody catalogue={data} />
                </Drawer>
            ) : null}
        </div>
    );
}

function Placeholder({ children }: { children: React.ReactNode }) {
    return (
        <div className="flex items-center justify-center p-8">
            <p className="max-w-sm text-center text-(--text-muted)">{children}</p>
        </div>
    );
}

type Catalogue = Awaited<ReturnType<typeof definitions>>;

function SelectedGroup({
    catalogue,
    onPendingChange,
}: {
    catalogue: Catalogue;
    onPendingChange: (group: string, count: number) => void;
}) {
    const { t } = useTranslation();
    const { group } = useParams<{ group: string }>();

    const fields = group === undefined ? undefined : catalogue[group];

    if (group === undefined || fields === undefined) {
        // A group the catalogue does not contain. Named, because the usual cause is a
        // stale link rather than a mistake being made right now.
        return <Placeholder>{t('settings.unknownGroup')}</Placeholder>;
    }

    return (
        <>
            <SettingsWorkspace
                definitions={fields}
                name={group}
                onPendingChange={onPendingChange}
            />
            <aside
                aria-label={t('settings.history.title')}
                className="hidden min-h-0 border-s border-(--border-default) xl:block"
            >
                <ContextPanel group={group} />
            </aside>
        </>
    );
}

/** The context region on a narrow screen, where it is a drawer rather than a column. */
function ContextDrawerBody({ catalogue }: { catalogue: Catalogue }) {
    const { t } = useTranslation();
    const { group } = useParams<{ group: string }>();

    if (group === undefined || catalogue[group] === undefined) {
        return <Placeholder>{t('settings.chooseGroup')}</Placeholder>;
    }

    return <ContextPanel group={group} />;
}

/**
 * A panel that slides in over the work on a narrow screen.
 *
 * Square, full height, and at the edge — the same treatment the shell's navigation
 * drawer gets, so a drawer is one idea in this product rather than three.
 */
function Drawer({
    title,
    side,
    onClose,
    children,
}: {
    title: string;
    side: 'start' | 'end';
    onClose: () => void;
    children: React.ReactNode;
}) {
    return (
        <div className="fixed inset-0 z-(--z-overlay) flex">
            <button
                aria-label={title}
                className="absolute inset-0 bg-(--slate-950)/60"
                onClick={onClose}
                type="button"
            />

            <div
                className={cn(
                    'relative flex h-full w-72 max-w-[85vw] flex-col bg-(--surface-default) shadow-(--shadow-overlay)',
                    side === 'start' ? 'me-auto border-e' : 'ms-auto border-s',
                    'border-(--border-default)',
                )}
            >
                <header className="flex items-center justify-between gap-2 border-b border-(--border-default) px-3 py-2.5">
                    <h2 className="text-(--text-secondary)" data-eyebrow>
                        {title}
                    </h2>
                    <button
                        aria-label={title}
                        className="p-1 text-(--text-muted) hover:text-(--text-primary)"
                        onClick={onClose}
                        type="button"
                    >
                        <X aria-hidden className="size-4" />
                    </button>
                </header>

                <div className="min-h-0 flex-1 overflow-y-auto">{children}</div>
            </div>
        </div>
    );
}
