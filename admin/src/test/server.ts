import { HttpResponse, http } from 'msw';
import { setupServer } from 'msw/node';

import arabic from '@catalogue/console/ar.json';

/**
 * One request interceptor for the whole suite.
 *
 * Started and stopped in `setup.ts`, with handlers added per test. `onUnhandledRequest`
 * is deliberately an error: a request nobody stubbed is either a typo in a path or a
 * call the code should not be making, and silently answering it with a 404 turns both
 * into a confusing assertion failure somewhere else.
 *
 * The one default is the console's wording (ADR 0049), which every screen asks for when a
 * language other than the catalogue's own is chosen. It answers as the platform does with no
 * translations written: the shipped catalogue where there is one, and nothing — English, key
 * by key — for any other language. A test that needs other wording adds its own handler.
 */
export const interfaceCatalogueHandler = http.get(
    '*/api/v1/interface/console/:locale',
    ({ params }) =>
        HttpResponse.json({ success: true, data: params.locale === 'ar' ? arabic : {} }),
);

export const server = setupServer(interfaceCatalogueHandler);

/** Every request the suite has seen, so a test can assert on what was sent. */
export const observed: Request[] = [];

server.events.on('request:start', ({ request }) => {
    observed.push(request.clone());
});
