import type { LucideIcon } from 'lucide-react';
import {
    Activity,
    Bell,
    CircleUser,
    Languages as LanguagesIcon,
    Globe,
    Images,
    KeyRound,
    LayoutDashboard,
    Plug,
    ShieldCheck,
    SlidersHorizontal,
    Users,
} from 'lucide-react';
import type { ComponentType } from 'react';

import { AccountScreen } from '@/screens/AccountScreen';
import { DashboardScreen } from '@/screens/DashboardScreen';
import { IntegrationsScreen } from '@/screens/IntegrationsScreen';
import { LanguagesScreen } from '@/screens/LanguagesScreen';
import { MediaScreen } from '@/screens/MediaScreen';
import { NotificationsScreen } from '@/screens/NotificationsScreen';
import { OperationsScreen } from '@/screens/OperationsScreen';
import { PermissionsScreen } from '@/screens/PermissionsScreen';
import { RolesScreen } from '@/screens/RolesScreen';
import { SettingsScreen } from '@/screens/SettingsScreen';
import { TranslationsScreen } from '@/screens/TranslationsScreen';
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
    /**
     * The group this module is a child of, or absent for a top-level item.
     *
     * Presentation only, and only in the navigation: the route is still the module's
     * own `path`, so a grouped module keeps its address and a bookmark to it goes on
     * working. Naming a group no manifest declares would hide the module, so the
     * navigation treats an unknown group as no group.
     */
    group?: string;
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

/**
 * A navigation section: a heading that owns modules rather than a screen of its own.
 *
 * A group has no path and no component. It cannot be navigated to and cannot be
 * bookmarked, because there is nothing at it — everything an operator can open is one
 * of its children. It disappears entirely when the viewer may see none of them, which
 * is the same rule a module follows and for the same reason: an empty heading tells a
 * restricted operator exactly what they are missing.
 */
export interface ModuleGroup {
    id: string;
    /** i18n key, resolved at render. Never a literal. */
    label: string;
    icon: LucideIcon;
    /** Ascending, in the same sequence modules are ordered in. */
    order: number;
}

export const MODULE_GROUPS: ModuleGroup[] = [
    {
        id: 'settings',
        label: 'modules.settings',
        icon: SlidersHorizontal,
        // Everything that configures the platform rather than operating it: its own
        // values, the accounts that may reach it, and what those accounts may do.
        order: 20,
    },
];

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
        // The section is called Settings, so its own screen is not. `generalSettings`
        // names what this child actually is — the platform's values — and leaves the
        // section heading to mean the whole of it.
        label: 'modules.generalSettings',
        icon: SlidersHorizontal,
        group: 'settings',
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
        group: 'settings',
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
        group: 'settings',
        permission: 'roles.view',
        order: 40,
        component: RolesScreen,
    },
    {
        id: 'permissions',
        path: '/access/permissions',
        label: 'modules.permissions',
        icon: ShieldCheck,
        group: 'settings',
        // The catalogue is read-only everywhere, so `permissions.view` is the only
        // permission this module can ask for. `permissions.update` exists in the
        // platform's catalogue and no endpoint enforces it; naming it here would gate
        // a screen on a permission that grants nothing.
        permission: 'permissions.view',
        order: 45,
        component: PermissionsScreen,
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
        id: 'translations',
        path: '/translations',
        label: 'modules.translations',
        icon: LanguagesIcon,
        // No permission, and for the same reason Languages has none: the workshop
        // reaches content owned by three modules with three different permissions, so
        // a single one named here could only ever be the wrong question for two of
        // them. The API answers per body of content and the screen renders what it is
        // given — an operator holding none of the three sees the screen and nothing
        // in it, which is a truthful answer rather than a hidden one.
        order: 65,
        component: TranslationsScreen,
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
    {
        id: 'notifications',
        path: '/notifications',
        label: 'modules.notifications',
        icon: Bell,
        // No permission, and deliberately. Half this module is the signed-in
        // account's own preferences about its own messages, which every account may
        // manage and no permission guards. The other half — the wording every
        // recipient reads — is administrative, and is gated inside the screen on
        // `notifications.view`. Naming that permission here would hide an operator's
        // own settings from them because they may not edit everyone's templates.
        order: 80,
        component: NotificationsScreen,
    },
    {
        id: 'operations',
        path: '/operations',
        label: 'modules.operations',
        icon: Activity,
        // Reading the administrative trail is the gate, and it is its own permission
        // for a reason: taken together the trail describes the platform's security
        // configuration and the habits of its administrators, and performing an
        // audited action is not a reason to be able to review everyone's (ADR 0037).
        // Archiving needs `audit.manage` and moving configuration needs
        // `settings.backup.manage`; both are enforced per operation by the API and
        // reflected section by section rather than at this level.
        permission: 'audit.view',
        order: 90,
        component: OperationsScreen,
    },
    {
        id: 'account',
        path: '/account',
        label: 'modules.account',
        icon: CircleUser,
        // No permission, and there is none that would fit. Everything here is the
        // viewer's own — the endpoints behind it take no account identifier, so there
        // is nobody else's account to reach however they are called. Last in the
        // order because it is the one workspace that is not about the platform.
        order: 100,
        component: AccountScreen,
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

/**
 * One row of the navigation: a module on its own, or a group and the modules under it.
 *
 * Built from the same filtered list the router uses, so the navigation cannot show a
 * module the router would refuse to mount, and a group cannot survive its children.
 */
export type NavigationEntry =
    | { kind: 'module'; module: ModuleManifest }
    | { kind: 'group'; group: ModuleGroup; children: ModuleManifest[] };

export function navigationTree(
    permissions: readonly string[],
    modules: readonly ModuleManifest[] = MODULES,
    groups: readonly ModuleGroup[] = MODULE_GROUPS,
): NavigationEntry[] {
    const visible = visibleModules(permissions, modules);
    const known = new Map(groups.map((group) => [group.id, group]));

    const entries: Array<NavigationEntry & { order: number }> = visible
        // A module naming a group that does not exist stays where it is rather than
        // vanishing into a heading nothing renders.
        .filter((module) => module.group === undefined || !known.has(module.group))
        .map((module) => ({ kind: 'module', module, order: module.order }));

    for (const group of groups) {
        const children = visible.filter((module) => module.group === group.id);

        if (children.length > 0) {
            entries.push({ kind: 'group', group, children, order: group.order });
        }
    }

    return entries
        .toSorted((a, b) => a.order - b.order)
        .map((entry) =>
            entry.kind === 'group'
                ? { kind: 'group', group: entry.group, children: entry.children }
                : { kind: 'module', module: entry.module },
        );
}
