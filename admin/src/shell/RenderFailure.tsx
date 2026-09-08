import { useTranslation } from 'react-i18next';

import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';

/**
 * What a caught render error looks like.
 *
 * Separate from the boundary itself because a boundary must be a class and this
 * needs translation, which is a hook — and keeping the two apart is also what lets
 * fast refresh keep working on the part that is edited.
 */
export function RenderFailure({ error, onReset }: { error: Error; onReset: () => void }) {
    const { t } = useTranslation();

    return (
        <div className="flex min-h-dvh items-center justify-center bg-(--surface-canvas) p-6">
            <div className="flex w-full max-w-lg flex-col gap-4">
                <Alert title={t('shell.crash.title')} tone="danger">
                    <p>{t('shell.crash.body')}</p>
                    <p className="mt-2 text-(length:--text-sm)" data-technical>
                        {error.message}
                    </p>
                </Alert>

                <div className="flex gap-2">
                    <Button onClick={onReset} variant="secondary">
                        {t('state.retry')}
                    </Button>
                    <Button onClick={() => window.location.reload()} variant="primary">
                        {t('shell.crash.reload')}
                    </Button>
                </div>
            </div>
        </div>
    );
}
