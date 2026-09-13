import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/errors';
import { Alert } from '@/ui/Alert';

import { socialLoginSetup } from './api';

/**
 * What Google needs from the operator, and what the platform still needs, before social
 * sign-in can be offered (ADR 0050).
 *
 * Shown above the Authentication settings, because both addresses it depends on are set
 * there. Everything on it comes from the platform, and the platform builds it only from
 * what is configured: with nothing configured there is no address here at all, not an
 * example to copy. An address the platform will not use in this environment is shown with
 * the reason, rather than quietly left out of the list to register.
 */
export function SocialLoginSetup() {
    const { t } = useTranslation();

    const setup = useQuery({
        queryKey: ['social-login-setup'],
        queryFn: ({ signal }) => socialLoginSetup(signal),
    });

    if (setup.isPending) {
        return <p className="text-(--text-muted)">{t('state.loading')}</p>;
    }

    if (setup.error !== null) {
        return (
            <Alert tone="danger">
                {setup.error instanceof ApiError ? setup.error.message : t('state.error')}
            </Alert>
        );
    }

    const data = setup.data;
    const ignored = data.redirect_uris.filter((entry) => !entry.usable);

    return (
        <section
            aria-labelledby="social-login-setup-title"
            className="flex flex-col gap-3 border border-(--border-default) bg-(--surface-default) p-3"
        >
            <header>
                <h3
                    className="text-(length:--text-base) font-medium text-(--text-primary)"
                    id="social-login-setup-title"
                >
                    {t('settings.socialSetup.title')}
                </h3>
                <p className="text-(length:--text-sm) text-(--text-muted)">
                    {t('settings.socialSetup.intro')}
                </p>
            </header>

            <p className="text-(length:--text-sm) text-(--text-primary)">
                {data.enabled
                    ? t('settings.socialSetup.enabled')
                    : t('settings.socialSetup.disabled')}
            </p>

            {data.ready ? (
                <Alert tone="success">{t('settings.socialSetup.ready')}</Alert>
            ) : (
                <Alert title={t('settings.socialSetup.notReady')} tone="warning">
                    <ul className="list-disc ps-4">
                        {data.issues.map((issue) => (
                            <li key={issue.code}>{issue.label}</li>
                        ))}
                    </ul>
                </Alert>
            )}

            <div className="flex flex-col gap-1">
                <p className="text-(--text-secondary)" data-eyebrow>
                    {t('settings.socialSetup.registerTitle')}
                </p>
                <p className="text-(length:--text-sm) text-(--text-muted)">
                    {t('settings.socialSetup.registerHint')}
                </p>
                {data.register_in_provider_console.length === 0 ? (
                    <p className="text-(length:--text-sm) text-(--text-muted)">
                        {t('settings.socialSetup.noneToRegister')}
                    </p>
                ) : (
                    <ul className="flex flex-col gap-0.5">
                        {data.register_in_provider_console.map((uri) => (
                            <li
                                className="text-(length:--text-sm) break-all text-(--text-primary)"
                                data-technical
                                key={uri}
                            >
                                {uri}
                            </li>
                        ))}
                    </ul>
                )}
                {ignored.length > 0 ? (
                    <ul className="flex flex-col gap-0.5 border-s-(length:--rail-width) border-(--state-warning-rail) ps-2">
                        {ignored.map((entry, position) => (
                            <li
                                className="text-(length:--text-sm)"
                                key={`${entry.uri}-${position}`}
                            >
                                <span className="break-all text-(--text-primary)" data-technical>
                                    {entry.uri}
                                </span>{' '}
                                <span className="text-(--state-warning-text)">
                                    {t('settings.socialSetup.unusable')}
                                    {entry.problem_label !== null ? `: ${entry.problem_label}` : ''}
                                </span>
                            </li>
                        ))}
                    </ul>
                ) : null}
            </div>

            <div className="flex flex-col gap-1">
                <p className="text-(--text-secondary)" data-eyebrow>
                    {t('settings.socialSetup.resetTitle')}
                </p>
                {data.password_reset_url.value === null ? (
                    <p className="text-(length:--text-sm) text-(--text-muted)">
                        {t('settings.notSet')}
                    </p>
                ) : (
                    <p
                        className="text-(length:--text-sm) break-all text-(--text-primary)"
                        data-technical
                    >
                        {data.password_reset_url.value}
                    </p>
                )}
                {data.password_reset_url.problem_label !== null ? (
                    <p className="text-(length:--text-sm) text-(--state-warning-text)">
                        {t('settings.socialSetup.unusable')}:{' '}
                        {data.password_reset_url.problem_label}
                    </p>
                ) : null}
                <p className="text-(length:--text-sm) text-(--text-muted)">
                    {t('settings.socialSetup.resetHint')}
                </p>
            </div>

            <div className="flex flex-col gap-1">
                <p className="text-(--text-secondary)" data-eyebrow>
                    {t('settings.socialSetup.providersTitle')}
                </p>
                <ul className="flex flex-col gap-0.5">
                    {data.providers.map((provider) => (
                        <li
                            className="text-(length:--text-sm) text-(--text-primary)"
                            key={provider.key}
                        >
                            {provider.label}
                            {' · '}
                            <span className="text-(--text-muted)">
                                {provider.effective
                                    ? t('settings.socialSetup.effective')
                                    : provider.active
                                      ? t('settings.socialSetup.activeIncomplete')
                                      : t('settings.socialSetup.inactive')}
                            </span>
                            <span className="block text-(--text-muted)">
                                {t('settings.socialSetup.clientId', {
                                    status: provider.client_id_configured
                                        ? t('settings.socialSetup.configured')
                                        : t('settings.socialSetup.notConfigured'),
                                })}
                                {' · '}
                                {t('settings.socialSetup.clientSecret', {
                                    status: provider.client_secret_configured
                                        ? t('settings.socialSetup.configured')
                                        : t('settings.socialSetup.notConfigured'),
                                })}
                            </span>
                            {provider.missing.length > 0 ? (
                                <span className="text-(--text-muted)">
                                    {' · '}
                                    {t('settings.socialSetup.missing', {
                                        fields: provider.missing.join(', '),
                                    })}
                                </span>
                            ) : null}
                        </li>
                    ))}
                </ul>
                <p className="text-(length:--text-sm) text-(--text-muted)">
                    {t('settings.socialSetup.providersHint')}
                </p>
            </div>

            {data.production ? null : (
                <p className="text-(length:--text-2xs) text-(--text-muted)">
                    {t('settings.socialSetup.developmentNote')}
                </p>
            )}
        </section>
    );
}
