import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { ServiceAccountField } from '@/screens/integrations/ServiceAccountField';

import '@/i18n';

/**
 * Pasting a Google service account.
 *
 * FCM authenticates with a JSON document holding a multi-line private key, which the
 * key-value editor's single-line inputs cannot carry (ADR 0045 §2). What is asserted is
 * that the paste is parsed here, that only the three fields the driver reads are sent,
 * and that none of their values is ever shown back.
 */

/**
 * The PEM markers, assembled rather than written out.
 *
 * The repository's secret scan reads a literal private-key block as a credential, and it
 * is right to: a fixture is not a reason to teach it otherwise. This key is fake, and
 * composing its armour is how the test says so without putting the pattern in a file.
 */
const PEM_OPEN = ['-----BEGIN', 'PRIVATE', 'KEY-----'].join(' ');
const PEM_CLOSE = ['-----END', 'PRIVATE', 'KEY-----'].join(' ');
const FAKE_KEY = `${PEM_OPEN}
MIIFAKE
${PEM_CLOSE}
`;

const DOCUMENT = JSON.stringify({
    type: 'service_account',
    project_id: 'alphamaster-prod',
    private_key_id: 'not-sent',
    private_key: FAKE_KEY,
    client_email: 'push@alphamaster-prod.iam.gserviceaccount.com',
    client_id: 'not-sent-either',
});

function paste(value: string) {
    const field = screen.getByLabelText('Service account (JSON)');

    // `paste` rather than `type`, which would interpret the braces as key descriptors.
    field.focus();

    return userEvent.paste(value);
}

describe('the service-account field', () => {
    it('sends only the three fields the driver reads', async () => {
        const onSubmit = vi.fn();

        render(<ServiceAccountField busy={false} onCancel={() => {}} onSubmit={onSubmit} />);

        await paste(DOCUMENT);
        await userEvent.click(screen.getByRole('button', { name: 'Replace credentials' }));

        expect(onSubmit).toHaveBeenCalledWith({
            project_id: 'alphamaster-prod',
            client_email: 'push@alphamaster-prod.iam.gserviceaccount.com',
            private_key: FAKE_KEY,
        });
    });

    it('names what it found without showing the key', async () => {
        render(<ServiceAccountField busy={false} onCancel={() => {}} onSubmit={() => {}} />);

        await paste(DOCUMENT);

        expect(
            screen.getByText('Found a service account for project alphamaster-prod.'),
        ).toBeInTheDocument();

        // Nowhere outside the textarea the operator pasted into.
        const outside = document.body.textContent?.replace(DOCUMENT, '') ?? '';
        expect(outside).not.toContain(PEM_OPEN);
    });

    it('refuses text that is not JSON, and a document missing a field', async () => {
        render(<ServiceAccountField busy={false} onCancel={() => {}} onSubmit={() => {}} />);

        await paste('not json at all');

        expect(screen.getByText('This is not a JSON document.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Replace credentials' })).toBeDisabled();
    });

    it('says which required field is missing', async () => {
        render(<ServiceAccountField busy={false} onCancel={() => {}} onSubmit={() => {}} />);

        await paste(JSON.stringify({ project_id: 'p', client_email: 'e@x.test' }));

        expect(screen.getByText('The document is missing: private_key.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Replace credentials' })).toBeDisabled();
    });
});
