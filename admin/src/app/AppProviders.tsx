import { QueryClientProvider } from '@tanstack/react-query';
import { useState } from 'react';

import { createQueryClient } from '@/api/queryClient';
import { DensityProvider } from '@/shell/DensityProvider';
import { DirectionProvider } from '@/shell/DirectionProvider';
import { ThemeProvider } from '@/shell/ThemeProvider';

/**
 * Everything the application assumes is already in place.
 *
 * The query client is created in state rather than at module scope so that each test
 * and each mount gets its own cache; a module-level client leaks one test's data into
 * the next.
 */
export function AppProviders({ children }: { children: React.ReactNode }) {
    const [queryClient] = useState(createQueryClient);

    return (
        <QueryClientProvider client={queryClient}>
            <ThemeProvider>
                <DensityProvider>
                    <DirectionProvider>{children}</DirectionProvider>
                </DensityProvider>
            </ThemeProvider>
        </QueryClientProvider>
    );
}
