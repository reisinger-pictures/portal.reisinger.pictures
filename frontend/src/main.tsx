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

applyTheme();

createRoot(document.getElementById('root')!).render(
    <StrictMode>
        <BrowserRouter>
            <App/>
        </BrowserRouter>
    </StrictMode>
);