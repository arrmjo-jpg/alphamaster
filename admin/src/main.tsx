import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';

import '@/i18n';
import '@/styles/index.css';

import { App } from '@/app/App';
import { AppProviders } from '@/app/AppProviders';

const container = document.getElementById('root');

if (container === null) {
    throw new Error('The #root element is missing from index.html.');
}

createRoot(container).render(
    <StrictMode>
        <AppProviders>
            <App />
        </AppProviders>
    </StrictMode>,
);
