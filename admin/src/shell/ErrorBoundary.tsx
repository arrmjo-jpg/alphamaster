import { Component, type ErrorInfo, type ReactNode } from 'react';

import { RenderFailure } from './RenderFailure';

interface State {
    error: Error | null;
}

/**
 * The last line before a white screen.
 *
 * A component that throws takes the whole React tree down with it, and an operator
 * looking at an administration console with nothing on it cannot tell that from the
 * platform being down. This catches the throw, says what happened and offers the one
 * action that reliably helps.
 *
 * A class, because that is still the only way to catch a render error in React — the
 * hooks API has no equivalent.
 */
export class ErrorBoundary extends Component<{ children: ReactNode }, State> {
    override state: State = { error: null };

    static getDerivedStateFromError(error: Error): State {
        return { error };
    }

    override componentDidCatch(error: Error, info: ErrorInfo): void {
        // Straight to the console, where the browser's own tooling can reach it.
        // There is no error-reporting service in this deployment, and inventing one
        // here would send an operator's data somewhere nobody agreed to.
        console.error('The Admin failed to render.', error, info.componentStack);
    }

    override render(): ReactNode {
        if (this.state.error === null) {
            return this.props.children;
        }

        return (
            <RenderFailure
                error={this.state.error}
                onReset={() => this.setState({ error: null })}
            />
        );
    }
}
