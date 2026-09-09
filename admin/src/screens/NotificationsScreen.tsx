import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/errors';
import { useCurrentUser } from '@/auth/AuthProvider';
import { cn } from '@/lib/cn';
import { templates as fetchTemplates } from '@/screens/notifications/api';
import { PreferenceMatrix } from '@/screens/notifications/PreferenceMatrix';
import { TemplateDetail } from '@/screens/notifications/TemplateDetail';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';
import { StatusBadge } from '@/ui/StatusBadge';

/**
 * What the platform tells people, and how it words it.
 *
 * Two surfaces, kept apart because they are two different things. Preferences are the
 * signed-in account's own settings about its own messages, so they need no permission
 * and there is no way to reach anybody else's — the endpoint acts on whoever is
 * asking. Template wording is what every recipient reads, so it is administrative and
 * behind `notifications.view`, with editing behind `notifications.update`.
 *
 * There is no inbox, and the screen says so rather than leaving a reader to wonder.
 * The platform writes an in-app record for every notification it raises and the
 * `database` channel cannot be silenced, but it publishes no endpoint that reads those
 * records back — so a notification centre listing what an operator has received would
 * be a screen with nothing behind it. When the platform grows one, this is where it
 * goes.
 */
export function NotificationsScreen() {
    const { t } = useTranslation();
    const viewer = useCurrentUser();
    const [selected, setSelected] = useState<string | null>(null);

    const mayReadTemplates = viewer.permissions.includes('notifications.view');
    const mayUpdateTemplates = viewer.permissions.includes('notifications.update');

    const templates = useQuery({
        queryKey: ['notification-templates'],
        queryFn: ({ signal }) => fetchTemplates(signal),
        // A request certain to be refused is not sent. The API remains the boundary;
        // this only avoids rendering an error where nothing is wrong.
        enabled: mayReadTemplates,
    });

    const rows = templates.data ?? [];
    const active = rows.find((row) => row.id === selected) ?? null;

    return (
        <div className="flex min-w-0 flex-col gap-(--section-gap)">
            <header>
                <p data-eyebrow>{t('notifications.eyebrow')}</p>
                <h1 className="text-(length:--text-2xl) text-(--text-primary)">
                    {t('modules.notifications')}
                </h1>
            </header>

            <section className="flex min-w-0 flex-col gap-2">
                <h2 data-eyebrow>{t('notifications.preferences.region')}</h2>
                <p className="max-w-prose text-(--text-secondary)">
                    {t('notifications.preferences.intro')}
                </p>
                <PreferenceMatrix />
            </section>

            {mayReadTemplates ? (
                <section className="flex min-w-0 flex-col gap-2">
                    <h2 data-eyebrow>{t('notifications.templates.region')}</h2>
                    <p className="max-w-prose text-(--text-secondary)">
                        {t('notifications.templates.intro')}
                    </p>

                    {templates.isPending ? (
                        <p className="text-(--text-muted)">{t('state.loading')}</p>
                    ) : templates.error !== null ? (
                        <Alert tone="danger">
                            <p>
                                {templates.error instanceof ApiError
                                    ? templates.error.message
                                    : t('state.error')}
                            </p>
                            <Button
                                className="mt-2"
                                onClick={() => void templates.refetch()}
                                size="sm"
                                variant="secondary"
                            >
                                {t('state.retry')}
                            </Button>
                        </Alert>
                    ) : rows.length === 0 ? (
                        <p className="border border-(--border-default) bg-(--surface-default) p-4 text-(--text-muted)">
                            {t('notifications.templates.none')}
                        </p>
                    ) : (
                        <div className="grid grid-cols-1 gap-(--section-gap) xl:grid-cols-[1fr_var(--panel-width-docked)]">
                            <div className="min-w-0 border border-(--border-default) bg-(--surface-default)">
                                <ul className="divide-y divide-(--border-default)">
                                    {rows.map((row) => (
                                        <li key={row.id}>
                                            <button
                                                aria-current={selected === row.id}
                                                className={cn(
                                                    'relative w-full px-3 py-2.5 ps-4 text-start',
                                                    'focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--focus-ring)',
                                                    selected === row.id
                                                        ? 'bg-(--action-secondary)'
                                                        : 'hover:bg-(--action-ghost-hover)',
                                                )}
                                                onClick={() => setSelected(row.id)}
                                                type="button"
                                            >
                                                <span
                                                    aria-hidden
                                                    className={cn(
                                                        'absolute inset-y-0 start-0 w-(--rail-width)',
                                                        row.is_active
                                                            ? 'bg-(--state-success-rail)'
                                                            : 'bg-(--state-neutral-rail)',
                                                    )}
                                                />
                                                <span className="block font-medium text-(--text-primary)">
                                                    {row.type_label}
                                                </span>
                                                <span
                                                    className="block text-(length:--text-xs) text-(--text-muted)"
                                                    data-technical
                                                >
                                                    {row.type}
                                                </span>
                                                <span className="mt-1 flex flex-wrap items-center gap-1.5">
                                                    <StatusBadge
                                                        tone={row.is_active ? 'success' : 'neutral'}
                                                    >
                                                        {row.is_active
                                                            ? t('notifications.templates.active')
                                                            : t('notifications.templates.inactive')}
                                                    </StatusBadge>
                                                    <span className="text-(length:--text-xs) text-(--text-muted)">
                                                        {t('notifications.templates.languages', {
                                                            count: row.translations.length,
                                                        })}
                                                    </span>
                                                </span>
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            </div>

                            <aside
                                aria-label={t('notifications.templates.detail')}
                                className="min-w-0"
                            >
                                {active === null ? (
                                    <p className="border border-(--border-default) bg-(--surface-default) p-4 text-(--text-muted)">
                                        {t('notifications.templates.choose')}
                                    </p>
                                ) : (
                                    <TemplateDetail
                                        // Keyed by template, so a draft never survives a
                                        // change of selection.
                                        key={active.id}
                                        mayUpdate={mayUpdateTemplates}
                                        onClose={() => setSelected(null)}
                                        template={active}
                                    />
                                )}
                            </aside>
                        </div>
                    )}
                </section>
            ) : null}

            <section className="flex flex-col gap-2">
                <h2 data-eyebrow>{t('notifications.inbox.region')}</h2>
                <div className="border border-(--border-default) bg-(--surface-default) p-4">
                    <p className="max-w-prose text-(--text-secondary)">
                        {t('notifications.inbox.absent')}
                    </p>
                </div>
            </section>
        </div>
    );
}
