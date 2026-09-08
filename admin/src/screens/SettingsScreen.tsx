import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { NavLink, Route, Routes, useParams } from 'react-router';

import { cn } from '@/lib/cn';
import { definitions } from '@/screens/settings/api';
import { GroupEditor } from '@/screens/settings/GroupEditor';
import { Panel } from '@/ui/Panel';

/**
 * The settings control centre.
 *
 * The catalogue drives it: groups and fields come from `/admin/settings/definitions`,
 * which publishes each setting's type, its rules, what it depends on and the
 * permission it needs. Nothing here hard-codes a group or a key, so a setting added
 * to the platform appears without this screen being edited — and one that names a
 * new permission is guarded without this screen learning what that permission means.
 */
export function SettingsScreen() {
    const { t } = useTranslation();

    const catalogue = useQuery({
        queryKey: ['settings-definitions'],
        queryFn: ({ signal }) => definitions(signal),
        // The catalogue is static for the life of a deployment.
        staleTime: 5 * 60_000,
    });

    const groups = Object.keys(catalogue.data ?? {}).toSorted();

    return (
        <div className="flex flex-col gap-(--section-gap)">
            <h1 className="text-(length:--text-2xl) font-semibold text-(--text-primary)">
                {t('modules.settings')}
            </h1>

            <div className="grid grid-cols-1 gap-(--section-gap) lg:grid-cols-[200px_1fr]">
                <Panel
                    error={catalogue.error}
                    loading={catalogue.isPending}
                    onRetry={() => void catalogue.refetch()}
                    title={t('settings.groups')}
                >
                    <nav aria-label={t('settings.groups')} className="flex flex-col gap-0.5">
                        {groups.map((name) => (
                            <NavLink
                                className={({ isActive }) =>
                                    cn(
                                        'px-2 py-1 text-(length:--text-base)',
                                        'transition-colors duration-100 ease-out',
                                        isActive
                                            ? 'bg-(--action-secondary) font-medium text-(--text-primary)'
                                            : 'text-(--text-secondary) hover:bg-(--action-ghost-hover)',
                                    )
                                }
                                key={name}
                                to={`/settings/${name}`}
                            >
                                {name}
                            </NavLink>
                        ))}
                    </nav>
                </Panel>

                <Routes>
                    <Route
                        element={<p className="text-(--text-muted)">{t('settings.chooseGroup')}</p>}
                        index
                    />
                    <Route element={<SelectedGroup catalogue={catalogue.data} />} path=":group" />
                </Routes>
            </div>
        </div>
    );
}

function SelectedGroup({
    catalogue,
}: {
    catalogue: Record<string, Awaited<ReturnType<typeof definitions>>[string]> | undefined;
}) {
    const { t } = useTranslation();
    const { group } = useParams<{ group: string }>();

    if (catalogue === undefined || group === undefined) {
        return null;
    }

    const fields = catalogue[group];

    if (fields === undefined) {
        // A group the catalogue does not contain. Named, because the usual cause is a
        // stale link rather than a mistake being made right now.
        return (
            <Panel title={group}>
                <p className="text-(--text-muted)">{t('settings.unknownGroup')}</p>
            </Panel>
        );
    }

    return <GroupEditor definitions={fields} name={group} />;
}
