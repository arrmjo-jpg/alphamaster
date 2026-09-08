import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

/**
 * The reCAPTCHA checkbox, rendered only when the platform is asking for one.
 *
 * Three things are worth stating plainly.
 *
 * This is the one place the Admin loads a third-party script, and it happens only
 * when an operator has switched the captcha on and configured a site key. With the
 * feature off — the default — the page makes no external request at all.
 *
 * It implements reCAPTCHA v2. The backend verifies either version (it applies a
 * `minimum_score` when the vendor returns one, which is v3's shape), but the contract
 * publishes only `captcha_enabled` and `captcha_site_key`, so there is nothing here
 * to switch on. A deployment using a v3 site key needs a signal this contract does
 * not yet carry — and it now says so on screen rather than presenting a form that
 * cannot be submitted.
 *
 * A widget that fails to appear is reported. This is not defensive tidiness: the
 * submit button is disabled until the widget produces a response, so a silent render
 * failure is an unusable sign-in page with nothing on it to explain why. It happened
 * — see `loadScript` — and the fix for the cause is not a reason to leave the symptom
 * unhandled.
 */

interface GreCaptcha {
    render: (container: HTMLElement, options: Record<string, unknown>) => number;
    reset: (widgetId?: number) => void;
    ready: (callback: () => void) => void;
}

declare global {
    interface Window {
        grecaptcha?: GreCaptcha;
    }
}

const SCRIPT_ID = 'recaptcha-api';
const SCRIPT_SRC = 'https://www.google.com/recaptcha/api.js?render=explicit';

/**
 * Resolve once the vendor's API is usable.
 *
 * The `window.grecaptcha` check comes first and is load-bearing. React's strict mode
 * mounts, unmounts and remounts, so the second mount finds the script tag the first
 * one added — and attaching a `load` listener to a script that has *already* loaded
 * waits for an event that will never fire again. That is a promise which never
 * settles, a `render` that is never called, and a sign-in button disabled forever.
 * Measured against a live key: the widget id came back as 0, meaning nothing had
 * rendered.
 */
function loadScript(): Promise<void> {
    if (window.grecaptcha?.render !== undefined) {
        return Promise.resolve();
    }

    const existing = document.getElementById(SCRIPT_ID);

    if (existing !== null) {
        return new Promise((resolve, reject) => {
            // Poll rather than listen. The event may already have happened, and the
            // vendor's object appearing is the condition that actually matters.
            const started = Date.now();
            const timer = setInterval(() => {
                if (window.grecaptcha?.render !== undefined) {
                    clearInterval(timer);
                    resolve();
                } else if (Date.now() - started > 15_000) {
                    clearInterval(timer);
                    reject(new Error('recaptcha did not become available'));
                }
            }, 50);
        });
    }

    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.id = SCRIPT_ID;
        script.src = SCRIPT_SRC;
        script.async = true;
        script.defer = true;
        script.addEventListener('load', () => resolve());
        script.addEventListener('error', () => reject(new Error('recaptcha failed to load')));
        document.head.append(script);
    });
}

export interface CaptchaProps {
    siteKey: string;
    /** Receives the response token, or null when the widget expires or is reset. */
    onToken: (token: string | null) => void;
    /** Changing this resets the widget — a failed sign-in burns the response. */
    resetKey: number;
}

export function Captcha({ siteKey, onToken, resetKey }: CaptchaProps) {
    const { t } = useTranslation();
    const container = useRef<HTMLDivElement>(null);
    const widgetId = useRef<number | null>(null);
    const latestOnToken = useRef(onToken);
    const [failed, setFailed] = useState(false);

    latestOnToken.current = onToken;

    useEffect(() => {
        let cancelled = false;

        void loadScript()
            .then(
                () =>
                    new Promise<void>((resolve) => {
                        // `ready` is the vendor's own signal that render is safe. With
                        // `render=explicit` the object can exist before it is.
                        window.grecaptcha?.ready(() => resolve());
                    }),
            )
            .then(() => {
                const element = container.current;

                if (cancelled || element === null || window.grecaptcha === undefined) {
                    return;
                }

                // Rendered once. Re-rendering into the same element throws, and the
                // reset below is what the "try again" path uses instead.
                if (widgetId.current === null) {
                    widgetId.current = window.grecaptcha.render(element, {
                        sitekey: siteKey,
                        callback: (token: string) => latestOnToken.current(token),
                        'expired-callback': () => latestOnToken.current(null),
                        'error-callback': () => latestOnToken.current(null),
                    });
                }
            })
            .catch(() => {
                if (cancelled) {
                    return;
                }

                // The vendor being unreachable, or refusing the key, is not reported
                // as a form error — the backend fails closed and the refusal is the
                // ordinary one. What has to be said is that the challenge cannot be
                // completed, because the operator is otherwise looking at a button
                // that will not work and no reason for it.
                setFailed(true);
                latestOnToken.current(null);
            });

        return () => {
            cancelled = true;
        };
    }, [siteKey]);

    useEffect(() => {
        if (resetKey === 0 || widgetId.current === null || window.grecaptcha === undefined) {
            return;
        }

        window.grecaptcha.reset(widgetId.current);
        latestOnToken.current(null);
    }, [resetKey]);

    return (
        <div className="flex flex-col gap-2">
            <div ref={container} />
            {failed ? (
                <p className="text-(length:--text-sm) text-(--text-danger)" role="alert">
                    {t('auth.captchaUnavailable')}
                </p>
            ) : null}
        </div>
    );
}
