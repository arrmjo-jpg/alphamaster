import { useCallback, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

import type { PublicAuthSettings } from './contract';

/**
 * The captcha, in both of the shapes reCAPTCHA actually has.
 *
 * They are not variations on one widget. **v2** renders a checkbox and produces a
 * token when a person ticks it, so the token exists before submit and the button
 * waits for it. **v3** renders nothing at all and produces a score-backed token on
 * demand, so there is nothing to wait for and the token is executed *during* submit —
 * they expire in about two minutes, which is another reason not to fetch one early.
 *
 * A client cannot tell the two apart from a site key. Guessing produces a sign-in
 * page that cannot be submitted and says nothing about why, which is exactly what
 * this deployment hit. `auth.captcha_version` is published for that reason and is
 * what this branches on — never a guess, never a probe.
 *
 * This is the one place the Admin loads a third-party script, and only when an
 * operator has switched the captcha on and configured a site key. With it off — the
 * default — the page makes no external request at all.
 *
 * The backend verifies whichever version answers: it applies `minimum_score` when the
 * vendor returns one, which is v3's shape, and takes the plain verdict when it does
 * not. Nothing here needs to know that; it only has to hand over a token the vendor
 * will recognise.
 */

interface GreCaptcha {
    render: (container: HTMLElement, options: Record<string, unknown>) => number;
    reset: (widgetId?: number) => void;
    ready: (callback: () => void) => void;
    execute: (siteKey: string, options: { action: string }) => Promise<string>;
}

declare global {
    interface Window {
        grecaptcha?: GreCaptcha;
    }
}

export type CaptchaMode = 'off' | 'v2' | 'v3';

const SCRIPT_ID = 'recaptcha-api';

/** v3 wants the site key in the script URL; v2 wants to be told to wait. */
function scriptSrc(mode: CaptchaMode, siteKey: string): string {
    return mode === 'v3'
        ? `https://www.google.com/recaptcha/api.js?render=${encodeURIComponent(siteKey)}`
        : 'https://www.google.com/recaptcha/api.js?render=explicit';
}

/**
 * Resolve once the vendor's API is usable.
 *
 * The `window.grecaptcha` check comes first and is load-bearing. React's strict mode
 * mounts, unmounts and remounts, so the second mount finds the script tag the first
 * one added — and attaching a `load` listener to a script that has *already* loaded
 * waits for an event that will never fire again. That is a promise which never
 * settles, a challenge that never appears, and a submit button disabled forever.
 * Measured against a live key: a manual render returned widget id 0, meaning nothing
 * had rendered. So this waits for the object rather than for an event.
 */
function loadScript(mode: CaptchaMode, siteKey: string): Promise<void> {
    if (window.grecaptcha?.render !== undefined) {
        return Promise.resolve();
    }

    if (document.getElementById(SCRIPT_ID) !== null) {
        return new Promise((resolve, reject) => {
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
        script.src = scriptSrc(mode, siteKey);
        script.async = true;
        script.defer = true;
        script.addEventListener('load', () => resolve());
        script.addEventListener('error', () => reject(new Error('recaptcha failed to load')));
        document.head.append(script);
    });
}

/** `ready` is the vendor's own signal that the API may be called. */
function whenReady(): Promise<void> {
    return new Promise((resolve) => {
        if (window.grecaptcha === undefined) {
            resolve();

            return;
        }

        window.grecaptcha.ready(() => resolve());
    });
}

export function captchaMode(settings: PublicAuthSettings | null): CaptchaMode {
    const siteKey = settings?.captcha_site_key ?? '';

    if (settings?.captcha_enabled !== true || siteKey === '') {
        return 'off';
    }

    return settings.captcha_version === 'v3' ? 'v3' : 'v2';
}

export interface CaptchaState {
    mode: CaptchaMode;
    /** Whether a submit may proceed. Always true for v3: it has nothing to wait for. */
    ready: boolean;
    /** True once the challenge could not be loaded, so the screen can say so. */
    failed: boolean;
    /** The token to send, obtained now for v3 and already held for v2. */
    obtainToken: () => Promise<string | null>;
    /** Burn the current response. A refused sign-in spends it. */
    reset: () => void;
    /** Where the v2 checkbox mounts. Null for v3 and for off. */
    containerRef: React.RefObject<HTMLDivElement | null>;
}

export function useCaptcha(settings: PublicAuthSettings | null): CaptchaState {
    const mode = captchaMode(settings);
    const siteKey = settings?.captcha_site_key ?? '';

    const containerRef = useRef<HTMLDivElement | null>(null);
    const widgetId = useRef<number | null>(null);
    const [token, setToken] = useState<string | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (mode === 'off') {
            return;
        }

        let cancelled = false;

        void loadScript(mode, siteKey)
            .then(whenReady)
            .then(() => {
                // v3 draws nothing. Loading the script is the whole of its setup, and
                // the token is executed at submit because it expires in minutes.
                if (cancelled || mode === 'v3') {
                    return;
                }

                const element = containerRef.current;

                if (element === null || window.grecaptcha === undefined) {
                    return;
                }

                // Rendered once. Re-rendering into the same element throws; `reset` is
                // what the try-again path uses instead.
                if (widgetId.current === null) {
                    widgetId.current = window.grecaptcha.render(element, {
                        sitekey: siteKey,
                        callback: (value: string) => setToken(value),
                        'expired-callback': () => setToken(null),
                        'error-callback': () => setToken(null),
                    });
                }
            })
            .catch(() => {
                if (cancelled) {
                    return;
                }

                // Not reported as a form error — the backend fails closed and the
                // refusal is the ordinary one. What has to be said is that the
                // challenge cannot be completed, because the operator is otherwise
                // looking at a button that will not work and no reason for it.
                setFailed(true);
                setToken(null);
            });

        return () => {
            cancelled = true;
        };
    }, [mode, siteKey]);

    const obtainToken = useCallback(async (): Promise<string | null> => {
        if (mode === 'off') {
            return null;
        }

        if (mode === 'v2') {
            return token;
        }

        try {
            await loadScript(mode, siteKey);
            await whenReady();

            // Fresh at the moment of submit. A v3 token is scored against the action
            // it was minted for, so the name matters and is not decorative.
            return (await window.grecaptcha?.execute(siteKey, { action: 'login' })) ?? null;
        } catch {
            setFailed(true);

            return null;
        }
    }, [mode, siteKey, token]);

    const reset = useCallback(() => {
        setToken(null);

        if (widgetId.current !== null && window.grecaptcha !== undefined) {
            window.grecaptcha.reset(widgetId.current);
        }
    }, []);

    return {
        mode,
        // v3 has nothing to wait for; v2 waits for a person to tick the box.
        ready: mode !== 'v2' || token !== null,
        failed,
        obtainToken,
        reset,
        containerRef,
    };
}

/**
 * What the operator sees.
 *
 * A checkbox for v2. For v3, a line of text and nothing else — the challenge is
 * invisible, and an interface that said nothing at all would be one where a hidden
 * third party scores every sign-in without a word to the person being scored.
 */
export function CaptchaField({ state }: { state: CaptchaState }) {
    const { t } = useTranslation();

    if (state.mode === 'off') {
        return null;
    }

    return (
        <div className="flex flex-col gap-2">
            {state.mode === 'v2' ? <div ref={state.containerRef} /> : null}

            {state.mode === 'v3' && !state.failed ? (
                <p className="text-(length:--text-sm) text-(--text-muted)">
                    {t('auth.captchaInvisible')}
                </p>
            ) : null}

            {state.failed ? (
                <p className="text-(length:--text-sm) text-(--text-danger)" role="alert">
                    {t('auth.captchaUnavailable')}
                </p>
            ) : null}
        </div>
    );
}
