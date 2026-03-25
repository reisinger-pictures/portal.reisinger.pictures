---
domain: infrastructure
topic: deployment
status: active
---

# Technical Concept: Deployment & Production

## 1. Portainer & Docker Stack
- The project is deployed as a Docker stack via **Portainer**.
- **Automated Init:** A dependency chain ensures the database is ready before the backend starts. The `backend-init` container automatically runs `php artisan migrate --force` and generates keys (`APP_KEY`, `JWT_SECRET`) if they are missing.
  - TODO: This is most likely not up to date and has to be updated before deployment of version 1.0. 

## 2. Environment Variables
- All configuration is managed via Portainer environment variables, overriding the `.env` file.
- Key variables include database credentials (`DB_ROOT_PASSWORD`), Meilisearch keys (`MEILI_MASTER_KEY`), and SMTP settings (e.g., Gmail App Passwords).

## 3. Frontend & Routing
- The frontend is built statically (`pnpm build`) and served via Nginx.
- A Reverse Proxy (e.g., Nginx Proxy Manager / Traefik) handles routing:
  - `/api/*` -> `portal_backend` (Port 80)
  - `/*` -> `portal_frontend` (Port 80)
