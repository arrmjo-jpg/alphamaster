import { useCurrentUser } from '@/auth/AuthProvider';
import { CacheWorkspace } from '@/screens/cache/CacheWorkspace';

/**
 * The application cache (ADR 0035, ADR 0051 §7).
 *
 * Reading needs `settings.view`, which the navigation gates on. Invalidating needs
 * `settings.update`; the API enforces it and the workspace reflects it.
 */
export function CacheScreen() {
    const user = useCurrentUser();

    return <CacheWorkspace mayInvalidate={user.permissions.includes('settings.update')} />;
}
