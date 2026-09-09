import { useTranslation } from 'react-i18next';

import { useAuth } from '@/auth/AuthProvider';
import type { RefusalReason } from '@/auth/machine';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';

import { AuthCover } from './AuthCover';

/**
 * Authenticated, and still not getting in.
 *
 * Two different situations, kept apart because the operator's next move differs. A
 * suspended account needs someone with authority to restore it; an account that is
 * simply not an administrator is working correctly and is in the wrong place. Showing
 * either as "access denied" would leave both people guessing.
 */
export function RefusedScreen({ reason }: { reason: RefusalReason }) {
    const { t } = useTranslation();
    const { signOut } = useAuth();

    return (
        <AuthCover title={t(`auth.refused.${reason}.title`)}>
            <div className="flex flex-col gap-4">
                <Alert tone={reason === 'suspended' ? 'danger' : 'neutral'}>
                    {t(`auth.refused.${reason}.body`)}
                </Alert>

                <Button onClick={() => void signOut()} variant="secondary">
                    {t('auth.signOut')}
                </Button>
            </div>
        </AuthCover>
    );
}
