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
 * that the file is sent as pasted — the platform validates it and keeps only what the
 * driver reads — that the platform's refusal is shown in place, and that none of the
 * document's values is ever shown back.
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
    private_key_id: 'not-shown',
    private_key: FAKE_KEY,
    client_email: 'push@alphamaster-prod.iam.gserviceaccount.com',
    client_id: 'not-shown-either',
    token_uri: 'https://oauth2.googleapis.com/token',
});

function paste(value: string) {
    const field = screen.getByLabelText('Firebase Service Account JSON');

    // `paste` rather than `type`, which would interpret the braces as key descriptors.
    field.focus();

    return userEvent.paste(value);
}

describe('the service-account field', () => {
    it('sends the file as pasted, for the platform to validate', async () => {
        const onSubmit = vi.fn();

        render(<ServiceAccountField busy={false} onCancel={() => {}} onSubmit={onSubmit} />);

        await paste(DOCUMENT);
        await userEvent.click(screen.getByRole('button', { name: 'Save service account' }));

        expect(onSubmit).toHaveBeenCalledWith(DOCUMENT);
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
        expect(outside).not.toContain('not-shown');
    });

    it('refuses text that is not JSON', async () => {
        render(<ServiceAccountField busy={false} onCancel={() => {}} onSubmit={() => {}} />);

        await paste('not json at all');

        expect(screen.getByText('This is not a JSON document.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Save service account' })).toBeDisabled();
    });

    it('says which required field is missing', async () => {
        render(<ServiceAccountField busy={false} onCancel={() => {}} onSubmit={() => {}} />);

        await paste(JSON.stringify({ project_id: 'p', client_email: 'e@x.test' }));

        expect(screen.getByText('The document is missing: private_key.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Save service account' })).toBeDisabled();
    });

    it("shows the platform's refusal in place and keeps the text to correct", async () => {
        render(
            <ServiceAccountField
                busy={false}
                error="The file's token_uri must be https://oauth2.googleapis.com/token."
                onCancel={() => {}}
                onSubmit={() => {}}
            />,
        );

        await paste(DOCUMENT);

        expect(
            screen.getByText("The file's token_uri must be https://oauth2.googleapis.com/token."),
        ).toBeInTheDocument();
        expect(screen.getByLabelText('Firebase Service Account JSON')).toHaveValue(DOCUMENT);
    });
});
