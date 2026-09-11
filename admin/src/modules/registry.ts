import type { LucideIcon } from 'lucide-react';
import {
    Activity,
    Bell,
    BrainCircuit,
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
    UsersRound,
} from 'lucide-react';
import type { ComponentType } from 'react';

import { AccountScreen } from '@/screens/AccountScreen';
import { AiScreen } from '@/screens/AiScreen';
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
    /**
     * `false` keeps the module routable and out of the navigation.
     *
     * For a screen that is somewhere a person can go but not somewhere in the system —
     * the signed-in account's own page is the one case. The navigation answers "where do
     * I go in this system"; the account is whose session this is, and it is reached from
     * the account menu instead. The router and the "Go to" search still see it, because
     * both are about what exists and may be opened, not about what the sidebar lists.
     */
    navigation?: false;
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

/**
 * The sections, and the line between them.
 *
 * `settings` is how the platform is configured — its own values, the languages it
 * serves, the library it stores, the messages it sends, the vendors it talks to — and the operations screen where that configuration
 * is audited and moved.
 * `access` is who may sign in and what they may do once they have.
 *
 * They are deliberately not one section. Access management is not a preference with
 * consequences for how a screen looks; it is the set of people who can perform every
 * operation the rest of the console offers, which is why it is audited (ADR 0037) and
 * why it carries its own permissions (ADR 0014). Filing it under Settings invites an
 * operator to read "who is an administrator" as configuration of the same kind as
 * "which date format", and those are not the same kind of question.
 *
 * Access comes first: who may act on the platform is read before how it behaves.
 *
 * Orders are spaced by a hundred so a section and a top-level module can never
 * collide on one: a tie would be resolved by insertion order, which is not a thing
 * this file should depend on.
 */
export const MODULE_GROUPS: ModuleGroup[] = [
    {
        id: 'settings',
        label: 'modules.settings',
        icon: SlidersHorizontal,
        order: 200,
    },
    {
        id: 'access',
        label: 'modules.access',
        icon: UsersRound,
        order: 100,
    },
];

export const MODULES: ModuleManifest[] = [
    {
        id: 'dashboard',
        path: '/dashboard',
        label: 'modules.dashboard',
        icon: LayoutDashboard,
        // Above both sections: it is the one screen that is neither configuration nor
        // access, and it is where `/` lands.
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
        order: 210,
        nested: true,
        component: SettingsScreen,
    },
    {
        id: 'users',
        path: '/access/users',
        label: 'modules.users',
        icon: Users,
        group: 'access',
        // Reading the list is the gate. Promoting an account needs `users.update`
        // and changing its roles needs `roles.update`, both enforced per operation
        // by the API and reflected control by control rather than at this level.
        permission: 'users.view',
        order: 110,
        component: UsersScreen,
    },
    {
        id: 'roles',
        path: '/access/roles',
        label: 'modules.roles',
        icon: KeyRound,
        group: 'access',
        permission: 'roles.view',
        order: 120,
        component: RolesScreen,
    },
    {
        id: 'permissions',
        path: '/access/permissions',
        label: 'modules.permissions',
        icon: ShieldCheck,
        group: 'access',
        // The catalogue is read-only everywhere, so `permissions.view` is the only
        // permission this module can ask for. `permissions.update` exists in the
        // platform's catalogue and no endpoint enforces it; naming it here would gate
        // a screen on a permission that grants nothing.
        permission: 'permissions.view',
        order: 130,
        component: PermissionsScreen,
    },
    {
        id: 'ai',
        path: '/ai',
        label: 'modules.ai',
        icon: BrainCircuit,
        // Reading the state is reading a vendor's configuration, so it is the same
        // permission the integrations workspace asks for. Running a check needs
        // `ai.use` and is gated inside the screen — naming that permission here would
        // hide the whole workspace from an operator who may look and not spend, which
        // is exactly the operator who most needs to see whether AI is configured.
        permission: 'integrations.view',
        group: 'settings',
        order: 270,
        component: AiScreen,
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
        group: 'settings',
        order: 260,
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
        group: 'settings',
        order: 220,
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
        group: 'settings',
        order: 230,
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
        group: 'settings',
        order: 240,
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
        group: 'settings',
        order: 250,
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
        //
        // A child of Settings, and its last. What an operator reaches here is the
        // audit trail and the configuration transfer — export and restore — and both
        // are regions of this one screen rather than separate addresses, so it is one
        // entry. It sits with Settings because moving configuration across the edge is
        // configuration work, and the trail is where every setting change is read
        // back. The address stays `/operations`, so a bookmark made before the move
        // still opens it.
        group: 'settings',
        permission: 'audit.view',
        order: 280,
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
        // Out of the navigation and reached from the account menu: the sidebar says
        // where to go in the system, and an account is not a place in it.
        navigation: false,
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
    const visible = visibleModules(permissions, modules).filter(
        (module) => module.navigation !== false,
    );
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
