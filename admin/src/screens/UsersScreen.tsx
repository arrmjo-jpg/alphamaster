import { useQuery } from '@tanstack/react-query';
import { Search, ShieldCheck, ShieldOff } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/errors';
import { useCurrentUser } from '@/auth/AuthProvider';
import { cn } from '@/lib/cn';
import { useMediaQuery } from '@/lib/useMediaQuery';
import { users } from '@/screens/access/api';
import { UserDetail } from '@/screens/access/UserDetail';
import { Input } from '@/ui/Input';
import { StatusBadge } from '@/ui/StatusBadge';

/**
 * The identity console: every account, and what it may do.
 *
 * A table on a wide screen and a stack of records on a narrow one — not one table
 * that scrolls sideways. An operator scanning for the account that is suspended, or
 * the administrator without a second factor, is comparing down a column, and a column
 * you have to scroll to is a column nobody reads.
 *
 * Filtering happens here, in the browser, and the interface says so. `/admin/users`
 * takes no query parameters and returns every account ordered by email, so a search
 * box implying the server was asked would be a lie about where the work happened —
 * and would quietly become wrong the moment the list outgrew one response.
 */
export function UsersScreen() {
    const { t } = useTranslation();
    const viewer = useCurrentUser();
    const [term, setTerm] = useState('');
    const [selected, setSelected] = useState<string | null>(null);

    // A structural switch, not a styling one: the table and the record list are
    // different markup, and rendering both — hidden by CSS — would put every account
    // in the accessibility tree twice.
    const wide = useMediaQuery('(min-width: 768px)');

    const list = useQuery({ queryKey: ['admin-users'], queryFn: ({ signal }) => users(signal) });

    const filtered = useMemo(() => {
        const needle = term.trim().toLowerCase();

        if (needle === '') {
            return list.data ?? [];
        }

        return (list.data ?? []).filter((account) =>
            [account.name, account.email, account.phone ?? '', ...account.roles]
                .join(' ')
                .toLowerCase()
                .includes(needle),
        );
    }, [list.data, term]);

    if (list.isPending) {
        return <p className="text-(--text-muted)">{t('state.loading')}</p>;
    }

    if (list.error !== null) {
        return (
            <p className="text-(--text-danger)">
                {list.error instanceof ApiError ? list.error.message : t('state.error')}
            </p>
        );
    }

    return (
        <div className="flex flex-col gap-(--section-gap)">
            <header className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p data-eyebrow>{t('access.eyebrow')}</p>
                    <h1 className="text-(length:--text-2xl) text-(--text-primary)">
                        {t('modules.users')}
                    </h1>
                </div>

                <div className="flex w-full max-w-xs flex-col gap-1">
                    <label className="sr-only" htmlFor="user-filter">
                        {t('access.filter')}
                    </label>
                    <div className="relative">
                        <Search
                            aria-hidden
                            className="pointer-events-none absolute inset-y-0 start-2 my-auto size-3.5 text-(--text-muted)"
                        />
                        <Input
                            id="user-filter"
                            className="ps-7"
                            onChange={(event) => setTerm(event.target.value)}
                            placeholder={t('access.filterPlaceholder')}
                            type="search"
                            value={term}
                        />
                    </div>
                    {/* Said plainly, because it is true and because it stops being
                        true if the list ever outgrows one response. */}
                    <p className="text-(length:--text-2xs) text-(--text-muted)">
                        {t('access.filterNote', { count: list.data?.length ?? 0 })}
                    </p>
                </div>
            </header>

            <div className="grid grid-cols-1 gap-(--section-gap) xl:grid-cols-[1fr_380px]">
                <div className="min-w-0 border border-(--border-default) bg-(--surface-default)">
                    {wide ? (
                        <table className="w-full border-collapse">
                            <thead>
                                <tr className="border-b border-(--border-strong)">
                                    <Th>{t('access.columns.account')}</Th>
                                    <Th>{t('access.columns.type')}</Th>
                                    <Th>{t('access.columns.verification')}</Th>
                                    <Th>{t('access.columns.mfa')}</Th>
                                    <Th>{t('access.columns.roles')}</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {filtered.map((account) => (
                                    <tr
                                        className={cn(
                                            'cursor-pointer border-b border-(--border-default) last:border-b-0',
                                            selected === account.id
                                                ? 'bg-(--action-secondary)'
                                                : 'hover:bg-(--action-ghost-hover)',
                                        )}
                                        key={account.id}
                                        onClick={() => setSelected(account.id)}
                                    >
                                        <Td>
                                            {/* A real control, not a clickable row. The
                                                row keeps its own handler for the pointer,
                                                but a table row is not focusable and has
                                                no keyboard activation, so without this
                                                the whole console was reachable by keyboard
                                                and this table was not. Selecting twice is
                                                a no-op, so the two paths can coexist. */}
                                            <button
                                                className="relative flex w-full flex-col ps-3 text-start focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-(--focus-ring)"
                                                onClick={() => setSelected(account.id)}
                                                type="button"
                                            >
                                                <span
                                                    aria-hidden
                                                    className={cn(
                                                        'absolute inset-y-0 start-0 w-(--rail-width)',
                                                        account.is_active
                                                            ? 'bg-(--state-success-rail)'
                                                            : 'bg-(--state-danger-rail)',
                                                    )}
                                                />
                                                <span className="font-medium text-(--text-primary)">
                                                    {account.name}
                                                </span>
                                                <span
                                                    className="text-(length:--text-xs) text-(--text-muted)"
                                                    data-technical
                                                >
                                                    {account.email}
                                                </span>
                                            </button>
                                        </Td>
                                        <Td>{account.account_type_label}</Td>
                                        <Td>
                                            <Flag
                                                no={t('access.unverified')}
                                                value={account.email_verified}
                                                yes={t('access.verified')}
                                            />
                                        </Td>
                                        <Td>
                                            <Flag
                                                no={t('access.mfaOff')}
                                                value={account.mfa_enrolled}
                                                yes={t('access.mfaOn')}
                                            />
                                        </Td>
                                        <Td>
                                            {account.roles.length === 0 ? (
                                                <span className="text-(--text-muted)">
                                                    {t('session.none')}
                                                </span>
                                            ) : (
                                                <span className="text-(length:--text-sm)">
                                                    {account.roles.join(', ')}
                                                </span>
                                            )}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    ) : (
                        /* Below the table's breakpoint each row becomes a record, so
                       nothing has to be scrolled sideways to be read. */
                        <ul className="divide-y divide-(--border-default)">
                            {filtered.map((account) => (
                                <li key={account.id}>
                                    <button
                                        className="relative w-full px-3 py-2.5 ps-4 text-start hover:bg-(--action-ghost-hover)"
                                        onClick={() => setSelected(account.id)}
                                        type="button"
                                    >
                                        <span
                                            aria-hidden
                                            className={cn(
                                                'absolute inset-y-0 start-0 w-(--rail-width)',
                                                account.is_active
                                                    ? 'bg-(--state-success-rail)'
                                                    : 'bg-(--state-danger-rail)',
                                            )}
                                        />
                                        <span className="block font-medium text-(--text-primary)">
                                            {account.name}
                                        </span>
                                        <span
                                            className="block text-(length:--text-xs) text-(--text-muted)"
                                            data-technical
                                        >
                                            {account.email}
                                        </span>
                                        <span className="mt-1 flex flex-wrap gap-1">
                                            <StatusBadge tone="neutral">
                                                {account.account_type_label}
                                            </StatusBadge>
                                            <Flag
                                                no={t('access.unverified')}
                                                value={account.email_verified}
                                                yes={t('access.verified')}
                                            />
                                            <Flag
                                                no={t('access.mfaOff')}
                                                value={account.mfa_enrolled}
                                                yes={t('access.mfaOn')}
                                            />
                                        </span>
                                        {/* The roles column carries here too. A record
                                            that drops a field the table shows is not the
                                            same account seen smaller, it is a different
                                            and less useful answer. */}
                                        <span className="mt-1 block text-(length:--text-xs) text-(--text-muted)">
                                            {t('access.columns.roles')}:{' '}
                                            <span className="text-(--text-secondary)">
                                                {account.roles.length === 0
                                                    ? t('session.none')
                                                    : account.roles.join(', ')}
                                            </span>
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}

                    {filtered.length === 0 ? (
                        <p className="p-4 text-(--text-muted)">
                            {term === '' ? t('access.noUsers') : t('access.noMatches')}
                        </p>
                    ) : null}
                </div>

                <aside className="min-w-0">
                    {selected === null ? (
                        <p className="border border-(--border-default) bg-(--surface-default) p-4 text-(--text-muted)">
                            {t('access.chooseUser')}
                        </p>
                    ) : (
                        <UserDetail
                            id={selected}
                            onClose={() => setSelected(null)}
                            viewerPermissions={viewer.permissions}
                        />
                    )}
                </aside>
            </div>
        </div>
    );
}

function Th({ children }: { children: React.ReactNode }) {
    return (
        <th className="px-3 py-2 text-start align-bottom" scope="col">
            <span data-eyebrow>{children}</span>
        </th>
    );
}

function Td({ children }: { children: React.ReactNode }) {
    return (
        <td className="px-3 py-(--table-cell-padding-block) align-middle text-(length:--text-sm)">
            {children}
        </td>
    );
}

/**
 * A yes or a no, said in words as well as in colour.
 *
 * These two columns are the ones an operator scans for trouble — an unverified
 * address, an administrator with no second factor — so neither may depend on being
 * able to tell green from amber.
 */
function Flag({ value, yes, no }: { value: boolean; yes: string; no: string }) {
    return (
        <StatusBadge
            icon={value ? <ShieldCheck className="size-3" /> : <ShieldOff className="size-3" />}
            tone={value ? 'success' : 'warning'}
        >
            {value ? yes : no}
        </StatusBadge>
    );
}
