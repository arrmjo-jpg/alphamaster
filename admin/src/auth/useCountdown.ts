import { useEffect, useState } from 'react';

/**
 * Seconds remaining, ticking down to zero.
 *
 * Driven by a deadline rather than by decrementing a counter: a tab that is
 * backgrounded has its timers throttled, and a counter would come back showing time
 * the operator has already waited. Comparing against a fixed deadline is right
 * whether or not the interval fired.
 */
export function useCountdown(seconds: number): number {
    const [remaining, setRemaining] = useState(seconds);

    useEffect(() => {
        if (seconds <= 0) {
            setRemaining(0);

            return;
        }

        const deadline = Date.now() + seconds * 1000;
        setRemaining(seconds);

        const timer = setInterval(() => {
            const left = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
            setRemaining(left);

            if (left === 0) {
                clearInterval(timer);
            }
        }, 1000);

        return () => clearInterval(timer);
    }, [seconds]);

    return remaining;
}
