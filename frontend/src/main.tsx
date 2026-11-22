// MUST be the first import: I18nProvider activates the locale at module scope.
// Any module-scope `t` macro call in the app graph (e.g. zod schemas in the
// shell) would otherwise evaluate before i18n.activate() in the production
// bundle and crash the app with a Lingui locale error.
import './logic/I18nProvider';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import {BrowserRouter} from 'react-router-dom';
import App from './App';
import './index.css';
import { applyTheme } from './logic/useBrand';
import { registerPwas } from './logic/pwa';

applyTheme();
void registerPwas();

// react-router 7 wraps every declarative-router state update in
// React.startTransition by default. That defers the React `location` context —
// and everything derived from it (e.g. the EUR cockpit's `?bereich=` tab) — to
// a low-priority commit while the History API has already updated the URL
// synchronously. Under parallel E2E load that commit can be starved past the
// assertion window, leaving the URL correct but `aria-selected` stale. The
// per-navigation `flushSync` option is discarded by the declarative router;
// `useTransitions={false}` is the public prop that makes its listener call
// setStateImpl directly, keeping URL and render in one commit.
createRoot(document.getElementById('root')!).render(
    <StrictMode>
        <BrowserRouter useTransitions={false}>
            <App/>
        </BrowserRouter>
    </StrictMode>
);