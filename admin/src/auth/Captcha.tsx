import { useEffect, useRef } from 'react';

/**
 * The reCAPTCHA checkbox, rendered only when the platform is asking for one.
 *
 * Two things are worth stating plainly.
 *
 * This is the one place the Admin loads a third-party script, and it happens only
 * when an operator has switched the captcha on and configured a site key. With the
 * feature off — the default — the page makes no external request at all.
 *
 * It implements reCAPTCHA v2. The backend verifies either version (it applies a
 * `minimum_score` when the vendor returns one, which is v3's shape), but the contract
 * publishes only `captcha_enabled` and `captcha_site_key`, so there is nothing here
 * to switch on. A deployment using a v3 site key needs a signal this contract does
 * not yet carry; when it grows one, this component branches on it rather than being
 * rewritten.
 */

interface GreCaptcha {
    render: (container: HTMLElement, options: Record<string, unknown>) => number;
    reset: (widgetId?: number) => void;
}

declare global {
    interface Window {
        grecaptcha?: GreCaptcha & { ready: (callback: () => void) => void };
    }
}

const SCRIPT_ID = 'recaptcha-api';
const SCRIPT_SRC = 'https://www.google.com/recaptcha/api.js?render=explicit';

function loadScript(): Promise<void> {
    if (window.grecaptcha !== undefined) {
        return Promise.resolve();
    }

    const existing = document.getElementById(SCRIPT_ID);

    if (existing !== null) {
        return new Promise((resolve, reject) => {
            existing.addEventListener('load', () => resolve());
            existing.addEventListener('error', () => reject(new Error('recaptcha failed to load')));
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
    const container = useRef<HTMLDivElement>(null);
    const widgetId = useRef<number | null>(null);
    const latestOnToken = useRef(onToken);

    latestOnToken.current = onToken;

    useEffect(() => {
        let cancelled = false;

        void loadScript()
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
                // The vendor being unreachable is not reported as a form error: the
                // backend fails closed, so the sign-in will be refused with the same
                // message a wrong password produces, which is the point.
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

    return <div ref={container} />;
}
