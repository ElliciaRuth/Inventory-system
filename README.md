# BSU Integrated Inventory Monitoring System

Inventory, stock-card and stock-out management for BSU offices (Bakery, FPC, …).
It works in a desktop browser, on tablets and on phones.

```
backend/      CodeIgniter 4 JSON API: every route is under /api, no HTML pages
frontend/     Vue 3 + Vite single-page app that calls the API
docker/       Files for running everything on one PC with Docker
docker-compose.yml
setup.bat     Windows: double-click to set up and start without Docker (runs setup.ps1)
setup.ps1     Windows setup script (XAMPP / PHP / Composer / Node)
setup.sh      Linux / macOS / WSL setup script
```

## Contents

- [What each role can do](#what-each-role-can-do)
- [What changed in this version](#what-changed-in-this-version)
- [Setting it up on a local PC](#setting-it-up-on-a-local-pc)
- [Day-to-day operation](#day-to-day-operation)
- [Troubleshooting](#troubleshooting)
- [Development](#development)
- [Production server layout](#production-server-layout)
- [API overview](#api-overview)

## What each role can do

| Role (level) | Pages |
|---|---|
| **Staff** (1) | Dashboard, Items (read-only product list), **Stock Out** (request items by search or barcode scan, then submit "My List" for approval), Transactions, Notifications |
| **Custodian** (2) | Everything above, plus: **Stock** (Stockcard, record stock in / issue / borrow / return / spoiled, Export Stockcard), **Products** (Product List, Finished Product Barcodes, Batch Barcodes, Summary Report, Export Summary), **Requests** (approve, edit or reject staff stock-out requests), **Settings** (units, references, entities, offices, product types, backups) |
| **Manager** (3) | Everything a custodian can do, plus user management (activate, deactivate, edit), pending applicants, expiry-alert defaults and backup schedule |
| **Technical Staff** (4) | User Management (all users and user offices) and Edit Profile. No inventory pages and no notifications; the **Menu** only shows the account card (profile, theme, sign out). |

Anyone can register an account from the login page; a manager or technical staff member has
to activate it before it can log in. Every user can update their name, email and password on
**Edit Profile** (Menu → Edit Profile, or click the account card). "Forgot Password?" on the
login page emails a 6-digit reset code once the technical staff account has set up the email
sender.

Technical Staff accounts are the system admin accounts: they **cannot be deactivated or
deleted** from User Management or Settings, and the API refuses those requests too.

## What changed in this version

### Latest update

- **Edit Profile page** (`/profile`) for every user: name, email and password in one place.
  It replaces the old Change Password page; `/change-password` and `/edit-profile` redirect there.
- **Admin accounts are protected:** Technical Staff accounts can no longer be deactivated or
  deleted (buttons hidden, and blocked by the API).
- **Admin view simplified:** no notification bell, and the Menu only shows the account card.
- **Batch Barcodes** now has the same pagination bar as the other tables.
- **Look and feel:** one consistent set of line icons across all pages (the same ones as the
  Menu) instead of emoji; the BSU, Bakery and FPC logos use transparent PNGs without white
  frames; a subtle themed background pattern behind the pages.
- **Menu** closes when you click anywhere outside it.
- **Setup without Docker:** `setup.bat` / `setup.ps1` (Windows + XAMPP) and `setup.sh`
  (Linux / macOS / WSL). See [Option B](#option-b-without-docker-xampp-or-local-php--node).
- **Demo accounts:** the seeder now creates one account per role and office (see
  [Default accounts](#default-accounts)). Re-running the seeder is safe; it only adds missing
  starting data.
- **Cleanup:** removed unused files (old item models for tables that no longer exist,
  CodeIgniter example tests, the old Change Password page, duplicate logo images).

### Previous update

- **Split into `backend/` and `frontend/`.** The CodeIgniter server-rendered pages were removed.
  CodeIgniter is now only a JSON API, and the whole user interface is the Vue app.
- **All the old pages are back as Vue pages:** stock-out request, my list, pending approvals,
  settings (with backups), batch barcodes, summary report, export summary, finished product
  barcodes, forgot / reset password and change password. Old URLs such as `/batchlist` or
  `/stockout/temp` redirect to the new pages.
- **Security:** every API route needs a login and the right access level. Products, stock,
  stock-out requests and barcodes are limited to the user's own office. The fake
  "Quick Access" demo logins were removed. Changing your password needs your current password.
- **Fixes:** recording a stock out (issue / borrow / return) works again; office and reference
  are saved on stock movements; editing a borrow, return or spoilage no longer turns it into an
  issue; staff can scan barcodes; backups work on Linux and Windows and follow the interval
  set in Settings; stock-out requests show up in the custodians' notifications.
- **Phones and tablets:** compact header with a ☰ menu, forms and pop-ups that fit small
  screens, and the everyday tables (stock out, my list, approvals, products) shown as cards.
- **One-command local setup with Docker** (next section).

## Setting it up on a local PC

The local PC runs the database, the API and the web page. Other computers, tablets and phones
on the same network open it in a browser, so they only need Wi-Fi or LAN access to that PC,
not internet access.

There are two ways to run it:

- **Option A: Docker** (recommended for the office PC). Steps 1–5 below.
- **Option B: without Docker**, using XAMPP or a local PHP + Node install. Good for
  development or a PC where Docker can't be installed. See
  [Option B](#option-b-without-docker-xampp-or-local-php--node).

### 1. Install the requirements

**Windows 10/11 (64-bit):**

1. Install **Docker Desktop**: <https://www.docker.com/products/docker-desktop/>. Accept the
   WSL 2 option during setup and restart when asked.
2. Open Docker Desktop once and wait until it says *Engine running*.
   In *Settings → General*, tick **Start Docker Desktop when you sign in to your computer**
   so the system comes back by itself after a reboot.
3. Install **Git for Windows**: <https://git-scm.com/download/win> (default options are fine).

**Linux:** install Docker Engine with the Compose plugin
(<https://docs.docker.com/engine/install/>) and `git`.

### 2. Get the code

Open **PowerShell** (Windows) or a terminal (Linux) and run:

```bash
git clone https://github.com/ElliciaRuth/Inventory-system.git
cd Inventory-system
```

### 3. Start it

```bash
docker compose up -d --build
```

The first start takes about 3–5 minutes. It downloads the Docker images, builds the web app,
installs the PHP packages, creates the database and fills in the starting data (offices,
access levels, transaction types and the first admin account). Later starts take seconds.

Check that everything is up:

```bash
docker compose ps -a
```

`db`, `php` and `web` should be *running*. `setup` and `frontend` run once and then show
*exited (0)*, which is expected.

### 4. Open it and finish the first-time setup

1. On the same PC, open **<http://localhost:8080>**.
2. Log in as **`admin_tech`** (password `admin123`, see [Default accounts](#default-accounts)).
3. Open **Menu → Edit Profile** and change the password straight away. If you are asked to
   set up email, enter a Gmail address and an **app password** used to send password-reset
   codes (Google account → Security → 2-Step Verification → App passwords), and your own
   recovery email.
4. In **User Management**, check the user offices. *BAKERY* and *FPC* are created for
   you; add any others. Change the passwords of the demo accounts you want to keep and
   delete or deactivate the rest. Then ask the other users to register from the login page
   (they pick their office and role there) and activate them. Each office needs at least one
   **Manager**, who can then activate and manage that office's users.

### Default accounts

On an empty database the seeder creates these accounts, all active:

| Username | Password | Role | Office |
|---|---|---|---|
| `admin_tech`, `tech` | `admin123`, `Tech123` | Technical Staff | Global |
| `manager`, `manager_bakery` / `manager_fpc` | `Manager123` | Manager | BAKERY / BAKERY / FPC |
| `custodian`, `custodian_bakery` / `custodian_fpc` | `Custodian123` | Custodian | FPC / BAKERY / FPC |
| `staff`, `staff_bakery` / `staff_fpc` | `Staff123` | Staff | BAKERY / BAKERY / FPC |

> **These passwords are public (they are in this README and in
> `backend/app/Database/Seeds/DatabaseSeeder.php`).** Change them, or delete the accounts you
> don't need, before real data goes into the system. Running `php spark db:seed DatabaseSeeder`
> by hand **resets these accounts to the passwords above**; the Docker and setup scripts only
> seed an empty database.

### Option B: without Docker (XAMPP or local PHP + Node)

**Windows:** install [XAMPP](https://www.apachefriends.org/) with PHP 8.2+ (in `C:\xampp`),
[Composer](https://getcomposer.org/) and [Node.js 22](https://nodejs.org/). Then double-click
**`setup.bat`** in the project folder. It:

1. checks for PHP, Composer, Node/npm and XAMPP (and uses XAMPP's PHP if it isn't on `PATH`);
2. starts MySQL (and Apache) from XAMPP if they aren't running;
3. creates `backend/.env` for XAMPP (`root` user, no password, database `inventory_system`)
   if it doesn't exist, and generates the encryption key;
4. installs the PHP and npm packages, runs the database migrations, and seeds an empty
   database;
5. starts the API on <http://localhost:8080> and the web app on <http://localhost:5173>,
   and opens the browser.

Options (run `setup.ps1` from PowerShell): `-InstallOnly` (set up without starting servers),
`-SkipBrowser`, `-BackendPort 8080`, `-FrontendPort 5173`, `-HostAddress 0.0.0.0`.

**Linux / macOS / WSL:** install PHP 8.2+ (with `mysqli`, `intl`, `mbstring`), Composer,
Node 22 and MySQL/MariaDB, then run `./setup.sh`. It does the same steps and serves the app on
<http://localhost:5173>.

This mode runs the development servers, so keep the window open while the system is in use.
For an always-on office PC, use Docker (Option A).

### 5. Let other computers, tablets and phones connect

1. Find the PC's IP address: run `ipconfig` (Windows) and use the *IPv4 Address*, for
   example `192.168.1.50`, or `hostname -I` (Linux).
2. On Windows, allow port 8080 through the firewall. Run this once in PowerShell
   **as Administrator**:

   ```powershell
   New-NetFirewallRule -DisplayName "BSU Inventory" -Direction Inbound -Protocol TCP -LocalPort 8080 -Action Allow -Profile Private,Domain
   ```

3. On the other devices, open `http://192.168.1.50:8080` (use your PC's address).
   On phones and tablets you can add it to the home screen from the browser menu.
4. Ask the network administrator to give the PC a **fixed IP address** (a DHCP reservation
   on the router), so the address doesn't change and bookmarks keep working.

> **Keep it inside the BSU network.** Do not set up port forwarding for port 8080 on the
> router. The system should only be reachable from the local network.

### Settings you can change

Put these in front of the command, or in a file named `.env` next to `docker-compose.yml`
(this is a different file from `backend/.env`):

| Setting | Default | Meaning |
|---|---|---|
| `INVENTORY_PORT` | `8080` | Port the system is served on |
| `INVENTORY_BIND` | `0.0.0.0` | `127.0.0.1` makes it reachable from this PC only |

Example: `INVENTORY_PORT=9090 docker compose up -d`.

Backend settings (database, email, environment) live in `backend/.env`, which is created from
`docker/backend.env` on the first start. The database password in those files is only used
between the containers on this PC. If you change it, change it in `docker-compose.yml`,
`docker/backend.env` and `docker/setup.sh` too, before the first start.

## Day-to-day operation

Run these from the `Inventory-system` folder.

| Task | Command |
|---|---|
| Stop | `docker compose stop` |
| Start again | `docker compose up -d` |
| See whether it is running | `docker compose ps -a` |
| See errors | `docker compose logs --tail 100 php web` |
| Update to the latest code | `git pull` then `docker compose up -d --build` (rebuilds the web app and runs new database migrations; data is kept) |

**Where the data is:** the database lives in the Docker volume `bsu-inventory_db_data` and
survives restarts and updates. Uploaded backups, generated barcodes and logs are in
`backend/writable/` and `backend/public/barcodes/`.

**Backups:** in **Settings → Data Backup & Restore**, custodians and managers can create a
backup, download the `.sql` file, or restore one. Managers set the automatic-backup interval
there; an automatic backup runs when a custodian or manager opens the app and one is due.
Download a backup regularly and keep a copy on another drive or PC.

> **Warning:** `docker compose down -v` deletes the database volume, and with it **all data**.
> Use `docker compose stop` or `docker compose down` without `-v` for normal shutdowns.

## Troubleshooting

| Problem | What to do |
|---|---|
| `port is already allocated` | Another program uses port 8080. Start with `INVENTORY_PORT=9090 docker compose up -d` and use that port in the address. |
| The page opens on the PC but not on other devices | Check the firewall rule (step 5), and that the devices are on the same network and use the PC's current IP. |
| `setup` shows *exited (1)* | Run `docker compose logs setup` to see the error, fix it, then `docker compose up -d` again. |
| Blank page or "Endpoint not found" after an update | Run `docker compose up -d --build` so the web app is rebuilt. |
| Forgot a password and email reset isn't set up | See [Resetting a forgotten password](#resetting-a-forgotten-password). |
| Windows: `setup.sh` fails with `$'\r': command not found` | The repository was checked out with Windows line endings. Run `git rm --cached -r . && git reset --hard`, which re-checks the files out using the rules in `.gitattributes`. |

### Resetting a forgotten password

If email reset isn't set up (or the account is `admin_tech`), run this from the
`Inventory-system` folder:

```bash
docker compose exec php php spark user:reset-password admin_tech
```

It prints a temporary password. Log in with it and you will be asked to choose a new one.
Managers can also set a new password for their office's users in **Settings → Users → Edit**.

## Development

**Backend** (PHP 8.2+, MySQL/MariaDB):

```bash
cd backend
composer install
cp env .env              # set database.* and app.baseURL = 'http://localhost:8080/'
php spark migrate
php spark db:seed DatabaseSeeder   # empty database only
php spark serve          # http://localhost:8080/api/...
```

**Frontend** (Node 22):

```bash
cd frontend
npm install
npm run dev              # http://localhost:5173
```

The Vite dev server proxies `/api` and `/barcodes` to `http://localhost:8080`.
If the backend runs somewhere else, set `VITE_DEV_API_TARGET` in `frontend/.env.local`.
Shared page styles are in `frontend/src/style.css`; phone and tablet rules are in
`frontend/src/responsive.css`.

Don't run `php spark serve` and the Docker setup at the same time on the default ports,
because both use 8080.

## Production server layout

nginx serves `frontend/dist` (built with `npm run build`) and sends `/api/*` to
`backend/public/index.php` with the original `REQUEST_URI`, so CodeIgniter still sees
the `/api` prefix. Finished-product barcode SVGs are written to `backend/public/barcodes/`
and served at `/barcodes/`. PHP must be able to write to that folder and to
`backend/writable/`. `docker/nginx/default.conf` is a working example of this setup.

## API overview

All responses are JSON: `{ "status": bool, "message": string, "data": ... }`.
Authentication uses the CodeIgniter session cookie.

| Area | Endpoints | Min level |
|------|-----------|-----------|
| Auth | `auth/login`, `auth/me`, `auth/logout`, `auth/register-options`, `auth/register`, `auth/forgot-password`, `auth/reset-password` | public |
| Account | `auth/update-profile` (name, email; password change needs `current_password`), `auth/change-password` (needs `current_password` except on first login); `auth/setup-smtp`, `auth/setup-recovery-email` (level 4) | logged in |
| Dashboard | `dashboard`, `transactions`, `notifications` | 1 |
| Products | `GET products`, `products/meta`, `products/{id}` (1); `POST products`, `PUT/DELETE products/{id}`, `products/barcodes`, `products/barcodes/generate` (2) | 1 / 2 |
| Stock | `stockcard`, `stock/options`, `stock/add`, `stock/edit-transaction`, `stock/delete-transaction`, `stock/edit-report-cost`; `stock/copies/{id}` (1) | 2 |
| Reports & barcodes | `reports/batches`, `reports/batchlist`, `barcode/product/{id}`, `barcode/batch/{id}`; `barcode/lookup` (1) | 2 |
| Exports | `export/stockcard(/options)`, `export/summary(/options)` | 2 |
| Settings | `settings`, `settings/{type}`, `settings/{type}/{id}`, `settings/system` (save: 3), `settings/users/{id}/activate\|deactivate` (3). Technical Staff accounts can't be deactivated or deleted (422). | 2 |
| Backups | `backups`, `backups/run`, `backups/auto`, `backups/{id}/download`, `backups/restore`, `backups/config` (3) | 2 |
| Stock-out | `stockout`, `stockout/temp`, `stockout/add-temp`, `stockout/edit-temp/{id}`, `stockout/remove-temp/{id}`, `stockout/submit` (1); `stockout/pending`, `approve-item`, `approve-all`, `reject-item`, `edit-pending` (2) | 1 / 2 |

Levels: 1 Staff, 2 Custodian, 3 Manager, 4 Technical Staff. See
`backend/app/Config/Routes.php` for the full list and `frontend/src/api/` for the
matching client functions.
