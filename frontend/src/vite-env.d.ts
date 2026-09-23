/// <reference types="vite/client" />

interface ImportMetaEnv {
    readonly VITE_STRIPE_PUBLIC_KEY: string;
    readonly VITE_TURNSTILE_SITE_KEY?: string;
}

interface ImportMeta {
    readonly env: ImportMetaEnv;
}
