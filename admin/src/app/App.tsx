import { Navigate, Route, Routes, useLocation } from 'react-router';

import { useCurrentUser } from '@/auth/AuthProvider';
import { HOME_PATH, visibleModules } from '@/modules/registry';
import { AppShell } from '@/shell/AppShell';
import { NotFound } from '@/shell/NotFound';

/**
 * The routes, built from the registry.
 *
 * Only modules the signed-in account may see get a route at all, so a hidden module
 * is hidden from the address bar as well as from the navigation — otherwise the one
 * place the filtering did not apply would be the one place someone typed a path by
 * hand. This remains presentation: the API refuses the request either way
 * (ADR 0042), and a route existing has never been what authorises anything.
 */
export function App() {
    const user = useCurrentUser();
    const location = useLocation();
    const modules = visibleModules(user.permissions);

    return (
        <Routes>
            <Route element={<AppShell />} path="/">
                <Route element={<Navigate replace to={HOME_PATH} />} index />

                {modules.map((module) => (
                    <Route
                        element={<module.component />}
                        key={module.id}
                        path={module.path.replace(/^\//, '')}
                    />
                ))}

                <Route element={<NotFound pathname={location.pathname} />} path="*" />
            </Route>
        </Routes>
    );
}
