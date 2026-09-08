import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

import { HOME_PATH } from '@/modules/registry';
import { Button } from '@/ui/Button';
import { StateRail } from '@/ui/StateRail';

/**
 * A path no module claims.
 *
 * It says which path, because the usual cause is a stale bookmark or a link from
 * somewhere else, and the operator can only tell which if they can see it.
 */
export function NotFound({ pathname }: { pathname: string }) {
    const { t } = useTranslation();

    return (
        <div className="flex max-w-xl flex-col gap-4">
            <StateRail tone="warning">
                <h1 className="text-(length:--text-xl) font-semibold text-(--text-primary)">
                    {t('shell.notFound.title')}
                </h1>
                <p className="text-(--text-secondary)">{t('shell.notFound.body')}</p>
                <p className="mt-1 text-(length:--text-sm) text-(--text-muted)" data-technical>
                    {pathname}
                </p>
            </StateRail>

            <div>
                <Button asChild variant="secondary">
                    <Link to={HOME_PATH}>{t('shell.notFound.home')}</Link>
                </Button>
            </div>
        </div>
    );
}
