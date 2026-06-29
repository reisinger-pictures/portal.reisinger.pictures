import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import {BrowserRouter} from 'react-router-dom';
import { SWRConfig } from 'swr';
import App from './App';
import './index.css';
import { applyTheme } from './logic/useBrand';

applyTheme();

createRoot(document.getElementById('root')!).render(
    <StrictMode>
        <SWRConfig value={{
            shouldRetryOnError: false
        }}>
            <BrowserRouter>
                <App/>
            </BrowserRouter>
        </SWRConfig>
    </StrictMode>
);