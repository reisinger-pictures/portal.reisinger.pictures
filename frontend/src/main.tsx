import React from 'react';
import ReactDOM from 'react-dom/client';
import {BrowserRouter} from 'react-router-dom';
import { SWRConfig } from 'swr';
import App from './App';
import './index.css';

ReactDOM.createRoot(document.getElementById('root')!).render(
    <React.StrictMode>
        <SWRConfig value={{
            shouldRetryOnError: false
        }}>
            <BrowserRouter future={{ v7_startTransition: true, v7_relativeSplatPath: true }}>
                <App/>
            </BrowserRouter>
        </SWRConfig>
    </React.StrictMode>
);