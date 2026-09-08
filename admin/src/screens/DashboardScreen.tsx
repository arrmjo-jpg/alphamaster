import { useTranslation } from 'react-i18next';

import { useCurrentUser } from '@/auth/AuthProvider';
import { ActivityPanel } from '@/screens/dashboard/ActivityPanel';
import { IntegrationsPanel } from '@/screens/dashboard/IntegrationsPanel';
import { PlatformPanel } from '@/screens/dashboard/PlatformPanel';

/**
 * What is the platform doing right now.
 *
 * Panels are composed here and gated on the permission each one's endpoints need, so
 * a request that is certain to be refused is never sent: the API would answer 403,
 * the panel would render an error, and the operator would be told something is wrong
 * when nothing is. A panel they may not read is simply absent.
 *
 * The layout is a grid rather than a widget system. Panels are extensible — adding
 * one is adding a component and a permission — and nothing here builds a dashboard
 * builder.
 */
export function DashboardScreen() {
    const { t } = useTranslation();
    const user = useCurrentUser();

    const may = (permission: string) => user.permissions.includes(permission);

    const panels = [
        { id: 'platform', node: <PlatformPanel />, visible: true },
        { id: 'integrations', node: <IntegrationsPanel />, visible: may('integrations.view') },
        { id: 'activity', node: <ActivityPanel />, visible: may('audit.view') },
    ].filter((panel) => panel.visible);

    return (
        <div className="flex flex-col gap-(--section-gap)">
            <h1 className="text-(length:--text-2xl) font-semibold text-(--text-primary)">
                {t('modules.dashboard')}
            </h1>

            <div className="grid grid-cols-1 gap-(--section-gap) xl:grid-cols-2">
                {panels.map((panel) => (
                    <div
                        className={panel.id === 'activity' ? 'xl:col-span-2' : undefined}
                        key={panel.id}
                    >
                        {panel.node}
                    </div>
                ))}
            </div>
        </div>
    );
}
