import { useCurrentUser } from '@/auth/AuthProvider';
import { CdnWorkspace } from '@/screens/cdn/CdnWorkspace';

/**
 * The content delivery network (ADR 0036, ADR 0053).
 *
 * Reading needs `cdn.view`, which the navigation gates on. Everything else is its own
 * permission and is enforced by the API; the workspace only reflects what the viewer holds:
 * `integrations.update` to connect and verify the vendor, `settings.update` for the media
 * delivery settings, `cdn.purge` for named objects and `cdn.purge_everything` for the rest.
 */
export function CdnScreen() {
    const user = useCurrentUser();

    return (
        <CdnWorkspace
            mayConfigure={user.permissions.includes('integrations.update')}
            mayPurge={user.permissions.includes('cdn.purge')}
            mayPurgeEverything={user.permissions.includes('cdn.purge_everything')}
        />
    );
}
