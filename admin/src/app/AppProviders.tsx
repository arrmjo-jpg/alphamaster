import { QueryClientProvider } from '@tanstack/react-query';
import { useState } from 'react';

import { createQueryClient } from '@/api/queryClient';
import { AuthProvider } from '@/auth/AuthProvider';
import { DensityProvider } from '@/shell/DensityProvider';
import { DirectionProvider } from '@/shell/DirectionProvider';
import { ThemeProvider } from '@/shell/ThemeProvider';

/**
 * Everything the application assumes is already in place.
 *
 * The query client is created in state rather than at module scope so that each test
 * and each mount gets its own cache; a module-level client leaks one test's data into
 * the next.
 *
 * Authentication is innermost, and the order matters: the sign-in screens are
 * themselves translated and direction-aware, so they need the display providers
 * around them — while nothing outside authentication needs to know who is signed in.
 */
export function AppProviders({ children }: { children: React.ReactNode }) {
    const [queryClient] = useState(createQueryClient);

    return (
        <QueryClientProvider client={queryClient}>
            <ThemeProvider>
                <DensityProvider>
                    <DirectionProvider>
                        <AuthProvider>{children}</AuthProvider>
                    </DirectionProvider>
                </DensityProvider>
            </ThemeProvider>
        </QueryClientProvider>
    );
}
