import type { LucideIcon } from 'lucide-react';
import {
    Globe,
    Images,
    KeyRound,
    LayoutDashboard,
    Plug,
    SlidersHorizontal,
    Users,
} from 'lucide-react';
import type { ComponentType } from 'react';

import { DashboardScreen } from '@/screens/DashboardScreen';
import { IntegrationsScreen } from '@/screens/IntegrationsScreen';
import { LanguagesScreen } from '@/screens/LanguagesScreen';
import { MediaScreen } from '@/screens/MediaScreen';
import { RolesScreen } from '@/screens/RolesScreen';
import { SettingsScreen } from '@/screens/SettingsScreen';
import { UsersScreen } from '@/screens/UsersScreen';

/**
 * The module registry: a typed manifest, and the only thing the shell knows about
 * what lives inside it (ADR 0009, ADR 0042).
 *
 * The shell imports this file. It never imports a module, and no module imports the
 * shell. Adding a workspace is adding a manifest here — a route, a translation key,
 * an icon, the permission it needs and where it sits in the order — and nothing in
 * the navigation, the router or the breadcrumb has to be edited to notice.
 *
 * `label` is a translation key rather than a string, so a module cannot ship English
 * into an Arabic interface by accident.
 */
export interface ModuleManifest {
    /** Stable identity. Used as the React key and in tests; never shown. */
    id: string;
    /** Path under the shell, without a trailing slash. */
    path: string;
    /** i18n key, resolved at render. Never a literal. */
    label: string;
    icon: LucideIcon;
    /**
     * The permission a viewer must hold for this to appear.
     *
     * Absent means every administrator sees it. Present means the item is hidden
     * from anyone whose grants do not include it — and that is presentation only.
     * The API is the authorization boundary (ADR 0042): hiding an item the caller
     * cannot use is a courtesy, and a module that renders because the client was
     * wrong still meets the perimeter on every request it makes.
     */
    permission?: string;
    /** Ascending. Ties are resolved by declaration order. */
    order: number;
    /**
     * Whether the module owns everything below its path.
     *
     * A module with its own internal navigation — settings and its groups — needs
     * the subtree, and declaring that here is what keeps the router generic: the
     * shell registers a wildcard for it and never learns what the deeper segments
     * mean. Without it those paths would fall through to the not-found screen.
     */
    nested?: boolean;
    component: ComponentType;
}

export const MODULES: ModuleManifest[] = [
    {
        id: 'dashboard',
        path: '/dashboard',
        label: 'modules.dashboard',
        icon: LayoutDashboard,
        order: 10,
        component: DashboardScreen,
    },
    {
        id: 'settings',
        path: '/settings',
        label: 'modules.settings',
        icon: SlidersHorizontal,
        // Reading is the gate. Changing a value needs `settings.update`, and a
        // setting that names its own permission needs that one — both enforced per
        // key by the API, and reflected field by field rather than at this level.
        permission: 'settings.view',
        order: 20,
        nested: true,
        component: SettingsScreen,
    },
    {
        id: 'users',
        path: '/access/users',
        label: 'modules.users',
        icon: Users,
        // Reading the list is the gate. Promoting an account needs `users.update`
        // and changing its roles needs `roles.update`, both enforced per operation
        // by the API and reflected control by control rather than at this level.
        permission: 'users.view',
        order: 30,
        component: UsersScreen,
    },
    {
        id: 'roles',
        path: '/access/roles',
        label: 'modules.roles',
        icon: KeyRound,
        permission: 'roles.view',
        order: 40,
        component: RolesScreen,
    },
    {
        id: 'integrations',
        path: '/integrations',
        label: 'modules.integrations',
        icon: Plug,
        // Reading which vendors exist is the gate. Changing one needs
        // `integrations.update`, enforced per operation by the API and reflected
        // control by control rather than at this level.
        permission: 'integrations.view',
        order: 50,
        component: IntegrationsScreen,
    },
    {
        id: 'languages',
        path: '/languages',
        label: 'modules.languages',
        icon: Globe,
        // No permission, and that is the platform's decision rather than an omission
        // here. The language routes sit behind the administrative perimeter and behind
        // no permission of their own — the catalogue has no `languages.*` entry — so
        // every administrator who can reach the Admin can reach them. Naming one here
        // would hide the module from accounts the API would happily serve, which is a
        // gate that only looks like security.
        order: 60,
        component: LanguagesScreen,
    },
    {
        id: 'media',
        path: '/media',
        label: 'modules.media',
        icon: Images,
        // Reading the library is the gate. Removing a file needs `media.delete`,
        // enforced per operation by the API and reflected control by control rather
        // than at this level. Uploading needs neither: media is a platform capability
        // and any signed-in account may add to it.
        permission: 'media.view',
        order: 70,
        component: MediaScreen,
    },
];

/** Where the shell sends someone who asks for `/`. */
export const HOME_PATH = '/dashboard';

/**
 * The modules a given set of grants may see, in order.
 *
 * Takes the permissions rather than the user, so the rule can be tested against any
 * set of grants without constructing a session.
 */
export function visibleModules(
    permissions: readonly string[],
    modules: readonly ModuleManifest[] = MODULES,
): ModuleManifest[] {
    return modules
        .filter(
            (module) => module.permission === undefined || permissions.includes(module.permission),
        )
        .toSorted((a, b) => a.order - b.order);
}
