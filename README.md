# BSU Integrated Inventory Monitoring System

The repository has two separate apps:

```
backend/    CodeIgniter 4 JSON API (no HTML pages): every route is under /api
frontend/   Vue 3 + Vite single-page app that calls the API
```

## Local development

**Backend** (PHP 8.2+, MySQL/MariaDB):

```bash
cd backend
composer install
cp env .env              # set database.* and app.baseURL = 'http://localhost:8080/'
php spark migrate
php spark serve          # http://localhost:8080/api/...
```

**Frontend** (Node 22):

```bash
cd frontend
npm install
npm run dev              # http://localhost:5173
```

The Vite dev server proxies `/api` (and `/barcodes`) to `http://localhost:8080`.
If the backend runs somewhere else, set `VITE_DEV_API_TARGET` in `frontend/.env.local`.

`php spark db:seed DatabaseSeeder` creates the first Technical Staff account (`admin_tech`)
with a temporary password. The app forces a password change, SMTP setup and a recovery
email on its first login.

## Production layout

nginx serves `frontend/dist` (built with `npm run build`) and sends `/api/*` to
`backend/public/index.php` with the original `REQUEST_URI`, so CodeIgniter still sees
the `/api` prefix. Finished-product barcode SVGs are written to `backend/public/barcodes/`
and served at `/barcodes/`. PHP must be able to write to that folder and to
`backend/writable/`.

## API overview

All responses are JSON: `{ "status": bool, "message": string, "data": ... }`.
Authentication uses the CodeIgniter session cookie.

| Area | Endpoints | Min level |
|------|-----------|-----------|
| Auth | `auth/login`, `auth/me`, `auth/logout`, `auth/register-options`, `auth/register`, `auth/forgot-password`, `auth/reset-password` | public |
| Account setup | `auth/change-password`; `auth/setup-smtp`, `auth/setup-recovery-email` (level 4) | logged in |
| Dashboard | `dashboard`, `transactions`, `notifications` | 1 |
| Products | `GET products`, `products/meta`, `products/{id}` (1); `POST products`, `PUT/DELETE products/{id}`, `products/barcodes`, `products/barcodes/generate` (2) | 1 / 2 |
| Stock | `stockcard`, `stock/options`, `stock/add`, `stock/edit-transaction`, `stock/delete-transaction`, `stock/edit-report-cost`; `stock/copies/{id}` (1) | 2 |
| Reports & barcodes | `reports/batches`, `reports/batchlist`, `barcode/product/{id}`, `barcode/batch/{id}`, `barcode/lookup` | 2 |
| Exports | `export/stockcard(/options)`, `export/summary(/options)` | 2 |
| Settings | `settings`, `settings/{type}`, `settings/{type}/{id}`, `settings/system` (save: 3), `settings/users/{id}/activate\|deactivate` (3) | 2 |
| Backups | `backups`, `backups/run`, `backups/auto`, `backups/{id}/download`, `backups/restore`, `backups/config` (3) | 2 |
| Stock-out | `stockout`, `stockout/temp`, `stockout/add-temp`, `stockout/edit-temp/{id}`, `stockout/remove-temp/{id}`, `stockout/submit` (1); `stockout/pending`, `approve-item`, `approve-all`, `reject-item`, `edit-pending` (2) | 1 / 2 |

Levels: 1 Staff, 2 Custodian, 3 Manager, 4 Technical Staff. See
`backend/app/Config/Routes.php` for the full list and `frontend/src/api/` for the
matching client functions.
