import React from 'react';
import ReactDOM from 'react-dom/client';
import {BrowserRouter} from 'react-router-dom';
import { SWRConfig } from 'swr';
import App from './App';
import './index.css';
import { applyTheme } from './logic/useBrand';

applyTheme();

ReactDOM.createRoot(document.getElementById('root')!).render(
    <React.StrictMode>
        <SWRConfig value={{
            shouldRetryOnError: false
        }}>
            <BrowserRouter>
                <App/>
            </BrowserRouter>
        </SWRConfig>
    </React.StrictMode>
);