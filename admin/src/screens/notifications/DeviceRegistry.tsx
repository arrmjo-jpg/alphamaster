import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Trash2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/errors';
import { absoluteTime, relativeTime } from '@/lib/time';
import { useDirection } from '@/shell/DirectionProvider';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';
import { StatusBadge } from '@/ui/StatusBadge';

import { devices as fetchDevices, forgetDevice } from './api';

export interface DeviceRegistryProps {
    mayUpdate: boolean;
}

/**
 * Who a push can reach, and whether anything could reach them.
 *
 * Two things read together (ADR 0045): the capability — is a vendor selected, does it
 * hold a credential, did it last answer — and the registry of devices it would deliver
 * to. Forty devices mean nothing without a provider, and a provider means nothing if
 * nobody has registered.
 *
 * Read-mostly. There is no way to register a device here: a registration is a claim that
 * a handset belongs to an account, and only that account's own client can make it.
 * Removing one is offered, for the rows that should not be there.
 *
 * No registration token is shown, because none is sent. A token is a delivery address
 * for one handset; a hint of its last few characters is enough to tell two rows apart.
 */
export function DeviceRegistry({ mayUpdate }: DeviceRegistryProps) {
    const { t } = useTranslation();
    const { locale } = useDirection();
    const queryClient = useQueryClient();

    const registry = useQuery({
        queryKey: ['push-devices'],
        queryFn: ({ signal }) => fetchDevices(signal),
    });

    const forget = useMutation({
        mutationFn: (id: string) => forgetDevice(id),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['push-devices'] }),
    });

    if (registry.isPending) {
        return <p className="text-(--text-muted)">{t('state.loading')}</p>;
    }

    if (registry.error !== null) {
        return (
            <Alert tone="danger">
                {registry.error instanceof ApiError ? registry.error.message : t('state.error')}
            </Alert>
        );
    }

    const data = registry.data;

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-col gap-2 border border-(--border-default) bg-(--surface-raised) p-3">
                <div className="flex flex-wrap items-center gap-1.5">
                    <StatusBadge tone={data.configured ? 'success' : 'warning'}>
                        {data.configured
                            ? t('notifications.devices.pushReady')
                            : t('notifications.devices.pushNotReady')}
                    </StatusBadge>
                    {data.provider !== null ? (
                        <StatusBadge tone={data.provider.has_credentials ? 'success' : 'warning'}>
                            {data.provider.has_credentials
                                ? t('notifications.devices.credentialStored')
                                : t('notifications.devices.credentialMissing')}
                        </StatusBadge>
                    ) : null}
                    {data.last_attempt !== null ? (
                        <StatusBadge
                            tone={data.last_attempt.status === 'success' ? 'success' : 'danger'}
                        >
                            {data.last_attempt.status === 'success'
                                ? t('notifications.devices.lastDelivered')
                                : t('notifications.devices.lastFailed')}
                        </StatusBadge>
                    ) : null}
                </div>

                <p className="text-(length:--text-sm) text-(--text-secondary)">
                    {data.provider === null
                        ? t('notifications.devices.noProvider')
                        : data.provider.label}
                </p>

                {data.last_attempt?.error_message ? (
                    <p className="text-(length:--text-xs) text-(--text-muted)">
                        {data.last_attempt.error_message}
                    </p>
                ) : null}

                {data.recent_failures > 0 ? (
                    <p className="text-(length:--text-xs) text-(--state-warning-text)">
                        {t('notifications.devices.recentFailures', {
                            count: data.recent_failures,
                        })}
                    </p>
                ) : null}

                <p className="text-(length:--text-xs) text-(--text-muted)">
                    {t('notifications.devices.configuredElsewhere')}
                </p>
            </div>

            <p className="text-(length:--text-sm) text-(--text-secondary)">
                {t('notifications.devices.totals', { total: data.total, stale: data.stale })}
            </p>

            {data.devices.length === 0 ? (
                <p className="text-(length:--text-sm) text-(--text-muted)">
                    {t('notifications.devices.none')}
                </p>
            ) : (
                <ul className="flex flex-col border border-(--border-default) bg-(--surface-raised)">
                    {data.devices.map((device) => (
                        <li
                            className="flex flex-wrap items-center justify-between gap-2 border-b border-(--border-default) px-3 py-2 last:border-b-0"
                            key={device.id}
                        >
                            <div className="flex min-w-0 flex-col gap-0.5">
                                <div className="flex flex-wrap items-center gap-1.5">
                                    <span className="text-(length:--text-sm) font-medium text-(--text-primary)">
                                        {device.label ?? device.platform_label}
                                    </span>
                                    <StatusBadge tone="neutral">
                                        {device.platform_label}
                                    </StatusBadge>
                                    {device.stale ? (
                                        <StatusBadge tone="warning">
                                            {t('notifications.devices.stale')}
                                        </StatusBadge>
                                    ) : null}
                                </div>
                                <span
                                    className="text-(length:--text-xs) text-(--text-muted)"
                                    data-technical
                                >
                                    …{device.token_hint} · {device.user_id}
                                </span>
                                <span
                                    className="text-(length:--text-xs) text-(--text-muted)"
                                    title={
                                        device.last_seen_at === null
                                            ? undefined
                                            : (absoluteTime(device.last_seen_at, locale) ??
                                              undefined)
                                    }
                                >
                                    {device.last_seen_at === null
                                        ? t('notifications.devices.neverReached')
                                        : t('notifications.devices.lastReached', {
                                              when: relativeTime(device.last_seen_at, locale),
                                          })}
                                </span>
                            </div>

                            {mayUpdate ? (
                                <Button
                                    aria-label={t('notifications.devices.forget', {
                                        name: device.label ?? device.platform_label,
                                    })}
                                    loading={forget.isPending && forget.variables === device.id}
                                    onClick={() => forget.mutate(device.id)}
                                    size="sm"
                                    variant="ghost"
                                >
                                    <Trash2 aria-hidden className="size-3.5" />
                                </Button>
                            ) : null}
                        </li>
                    ))}
                </ul>
            )}

            {forget.error instanceof ApiError ? (
                <Alert tone="danger">{forget.error.message}</Alert>
            ) : null}
        </div>
    );
}
