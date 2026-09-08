import { setupServer } from 'msw/node';

/**
 * One request interceptor for the whole suite.
 *
 * Started and stopped in `setup.ts`, with handlers added per test. `onUnhandledRequest`
 * is deliberately an error: a request nobody stubbed is either a typo in a path or a
 * call the code should not be making, and silently answering it with a 404 turns both
 * into a confusing assertion failure somewhere else.
 */
export const server = setupServer();

/** Every request the suite has seen, so a test can assert on what was sent. */
export const observed: Request[] = [];

server.events.on('request:start', ({ request }) => {
    observed.push(request.clone());
});
