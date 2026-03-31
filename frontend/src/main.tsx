import React from 'react';
import ReactDOM from 'react-dom/client';
import {BrowserRouter} from 'react-router-dom';
import { SWRConfig } from 'swr';
import App from './App';
import './index.css';

ReactDOM.createRoot(document.getElementById('root')!).render(
    <React.StrictMode>
        <SWRConfig value={{
            revalidateOnFocus: false, // Verhindert Flakiness in Tests durch Tab-Fokus
            shouldRetryOnError: false,
            dedupingInterval: navigator.userAgent.includes('Playwright') ? 0 : 5000,
        }}>
            <BrowserRouter>
                <App/>
            </BrowserRouter>
        </SWRConfig>
    </React.StrictMode>
);
