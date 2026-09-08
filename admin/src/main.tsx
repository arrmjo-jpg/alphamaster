import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router';

import '@/i18n';
import '@/styles/index.css';

import { App } from '@/app/App';
import { AppProviders } from '@/app/AppProviders';
import { AuthGate } from '@/auth/AuthGate';
import { ErrorBoundary } from '@/shell/ErrorBoundary';

const container = document.getElementById('root');

if (container === null) {
    throw new Error('The #root element is missing from index.html.');
}

createRoot(container).render(
    <StrictMode>
        <ErrorBoundary>
            <BrowserRouter>
                <AppProviders>
                    <AuthGate>
                        <App />
                    </AuthGate>
                </AppProviders>
            </BrowserRouter>
        </ErrorBoundary>
    </StrictMode>,
);
