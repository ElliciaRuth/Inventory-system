# BSU Integrated Inventory Monitoring System

[![Version](https://img.shields.io/badge/version-2.2.0-blue.svg)](https://github.com/ElliciaRuth/Inventory-system)
[![Release](https://img.shields.io/badge/release-October%202026-green.svg)](https://github.com/ElliciaRuth/Inventory-system)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4.svg?logo=php&logoColor=white)](https://www.php.net/)
[![CodeIgniter](https://img.shields.io/badge/CodeIgniter-4.7-EF4423.svg?logo=codeigniter&logoColor=white)](https://codeigniter.com/)
[![Vue.js](https://img.shields.io/badge/Vue.js-3.5-4FC08D.svg?logo=vuedotjs&logoColor=white)](https://vuejs.org/)
[![Vite](https://img.shields.io/badge/Vite-8.3-646CFF.svg?logo=vite&logoColor=white)](https://vitejs.dev/)

> **Current Version:** `v2.2.0` (Production Release — October 2026)

An integrated inventory, stock-card, and stock-out monitoring system engineered for Benguet State University production and operating units (Bakery Project, Food Processing Center, and administrative offices).

The application is built as a decoupled web application with a CodeIgniter 4 REST API backend and a responsive Vue 3 single-page application frontend. It runs seamlessly on desktop workstations, tablets, and mobile smartphones over local office networks without requiring an active internet connection.

```
BSU_Inventory_System/
├── backend/          CodeIgniter 4 JSON REST API (all endpoints under /api)
├── frontend/         Vue 3 + Vite single-page application
├── deploy/           Offline deployment automation, Apache SSL configs, and database tools
├── docker/           Container definitions (PHP 8.2-FPM, Nginx, MariaDB)
├── docker-compose.yml Single-command containerized deployment
├── setup.bat         Windows launcher (Default: Offline HTTPS; --dev: Dev servers; --prepare: USB packaging)
├── setup.ps1         Windows PowerShell stack management script
└── setup.sh          Linux / macOS / WSL automated setup script
```

---

## Contents

- [User Roles & Permissions](#user-roles--permissions)
- [Key Features & Recent Updates](#key-features--recent-updates)
- [Installation & Deployment](#installation--deployment)
  - [Option C: Offline HTTPS Server on XAMPP (Recommended for Office PC)](#option-c-offline-https-server-on-xampp-recommended-for-the-office-pc)
  - [Option A: Docker Deployment](#option-a-docker-deployment)
  - [Option B: Development Mode (XAMPP / PHP + Vite)](#option-b-development-mode-without-docker)
  - [Default Accounts & Credentials](#default-accounts--credentials)
  - [Connecting Other Computers, Tablets, and Phones](#connecting-other-computers-tablets-and-phones)
- [Day-to-Day Operations](#day-to-day-operations)
  - [Backup & Disaster Recovery (.bsubackup / .zip)](#backup--disaster-recovery)
  - [Importing Appendix 58 Stock Cards from Excel](#importing-appendix-58-stock-cards-from-excel)
  - [Physical Inventory Count & Reconciliation](#physical-inventory-count--reconciliation)
  - [Inter-Unit Borrowing & Returns](#inter-unit-borrowing--returns)
  - [FEFO Stock Issuance & Batch Tracking](#fefo-stock-issuance--batch-tracking)
  - [Product Catalog Archiving & Restoration](#product-catalog-archiving--restoration)
  - [Barcode Generation & Scanning](#barcode-generation--scanning)
- [Security & Architecture Guardrails](#security--architecture-guardrails)
- [Troubleshooting & Maintenance](#troubleshooting--maintenance)
  - [Resetting Forgotten Passwords](#resetting-forgotten-passwords)
  - [Common Questions & Issues](#common-questions--issues)
- [Development Workflow](#development-workflow)
- [Production Server Architecture](#production-server-architecture)
- [Version History & Changelog](#version-history--changelog)
- [API Reference](#api-reference)

---

## User Roles & Permissions

The system enforces strict role-based access control (RBAC) segregated by office assignment:

| Role (Level) | Accessible Modules & Permissions |
|---|---|
| **Staff** (1) | **Dashboard**, **Item Catalog** (read-only product inventory), **Request Stock Out** (search or scan batch barcodes, build personal list, submit for approval), **My Stock-Out List**, **Request History** (track submitted requests, approval decisions, and custodian rejection reasons), **Transaction Log**, **Notifications** (request status updates and stock alerts), and **Edit Profile**. |
| **Custodian** (2) | All Staff capabilities, plus: **Stockcard Ledger** (record receipts/stock-in, issues, lends, returns, and adjust in/out with required reasons; edit report unit cost), **Physical Count** (reconcile shelf stock against recorded balances), **Borrowed Items** (track open, overdue, and returned inter-unit loans), **Pending Stock-Out Requests** (review requests, edit quantities, approve individual/all, reject with mandatory feedback reason), **Products & Catalog** (add/edit products, archive/restore items, generate & download finished product and batch barcodes in SVG), **Reports** (Inventory Summary Report, Batch Inventory report), **Exports** (Stockcard ledger and summary reports to Excel/CSV), **Import from Excel** (load Appendix 58 stock cards into office inventory; replace mode requires manager password), and **Automatic Daily Backups** on login. |
| **Manager** (3) | All Custodian capabilities, plus: **Others Management** (System settings: manage user accounts and activate pending registrants, entity types, units of measure, reference types, product categories, offices, and batch expiry alert thresholds), **Data Backup & Restore** (create modular ZIP packages or encrypted `.bsubackup` archives, download archives, restore backups with automatic pre-restore safety snapshots, configure backup schedule), **Audit Log** (detailed, filterable audit trail for their office), and **Excel Stockcard Import** with direct replace authorization. |
| **Technical Staff** (4) | System Administration: **User Management** (manage all users and assignments across all offices; activate/deactivate accounts), **Office Management**, **Global Audit Log** (inspect immutable audit records across all university offices), and **Edit Profile**. Technical staff accounts are protected: they **cannot be deactivated or deleted** by any user or API request. Inventory operations and notification alerts are hidden for admin accounts. |

### Account Lifecycle & Authentication
- **Registration & Activation:** New users register through the login portal, selecting their requested role and office. Accounts remain in pending status until activated by a Manager (for their office) or Technical Staff.
- **Profile Management:** Any logged-in user can update their name, registered email, and password via `/profile` (Menu → Edit Profile, or by clicking the user account chip). Updating an email address or password requires confirming the user's current password.
- **First Login Security:** New and seeded accounts flagged with `must_change_password` are automatically redirected to the Account Setup wizard (`/account-setup`) upon login and cannot access any inventory modules until a new, secure password is created.
- **Self-Service Password Reset:** Users can initiate password reset on the login page by providing their registered email and an email App Password (e.g., Google App Password). The 6-digit code is dispatched directly from their own account to itself over TLS and expires in 15 minutes. The App Password is used strictly for that single transmission and is never persisted on the server. Alternatively, office managers or technical staff can reset passwords directly.

---

## Key Features & Recent Updates

### 1. Tamper-Evident Audit Trail (FR-09)
- Every significant action across the system is recorded to `audit_log`: logins, failed logins, lockout events, logouts, password changes, stock transactions (receipts, issues, borrows, returns, adjustments), ledger edits and deletions (capturing exact before-and-after value diffs), unit cost overrides, physical counts, stock-out approvals and rejections (with custodian reason), product creation, archiving, and restoration, Excel imports, configuration updates, user activations, backup creations, and restores.
- **Append-only integrity:** Database triggers prevent any `UPDATE` or `DELETE` operations on the `audit_log` table.
- Accessible via Menu → Management → Audit Log (Managers view their office; Technical Staff view all offices) with category filters, date ranges, and text search.

### 2. FEFO Automated Stock Issuance (FR-04)
- Outgoing stock movements (issues, approved staff stock-out requests, and lending) automatically adhere to **First-Expired, First-Out (FEFO)** ordering.
- Deductions automatically consume the earliest unexpired batch first; non-dated batches are consumed last.
- Expired batches are blocked from standard issuance and must be settled via **Adjust Out** with reason "Expired".
- The stock entry form features a real-time **Batch Plan** preview displaying which batches will be depleted prior to confirming.

### 3. Appendix 58 Excel Stockcard Importer
- Import historical government-standard Appendix 58 stock cards from multi-sheet `.xlsx` workbooks directly into the database.
- **Automated Data Cleaning:** Normalizes inconsistent units of measure (e.g., "kls", "kilos" → `kilo`; "pcs", "pieces" → `pcs`), performs fuzzy matching and spell-checking for item names against existing products, and detects product categories from stock number prefixes (`RM`, `FP`, `OS`, `LABEL`).
- **Interactive Preview & Reconciliation:** Reviews beginning balances, receipts, issues, carry-forward RPCI physical count rows, running balance calculations, and skipped entries. Custodians can tweak matched products or categories before committing.
- **Import Modes:** Choose between **Keep existing data** (skips already imported transactions) or **Delete existing inventory first** (requires manager authentication and automatically creates a full safety backup beforehand).

### 4. Modular & Encrypted Backup System
- Backups are generated as self-contained `.zip` packages or encrypted `.bsubackup` archives containing:
  - `README.txt`: Plain-language instructions for manual restoration.
  - `manifest.json`: SHA-256 integrity checksums for every included artifact.
  - `schema.sql`: Complete DDL table layouts allowing reconstruction on blank databases.
  - `data.json`: Structured, human-readable record dumps.
  - `spreadsheets/`: CSV copies for spreadsheet analysis, including an *Inventory Summary*.
  - `barcodes/`: Standalone batch barcode SVG graphics.
- **Encryption:** Optional AES-256-CBC encryption with PBKDF2 key derivation and HMAC-SHA256 authentication saved as `.bsubackup`.
- **Pre-Restore Validation:** The system inspects backup packages before restoration, displaying contained modules, record counts, and verifying cryptographic checksums.
- **Automatic Safety Snapshot:** A backup of current data is always created automatically prior to applying any restore operation.

### 5. Physical Inventory Count Reconciler (FR-07)
- Custodians input physically counted stock quantities on the **Physical Count** interface (`/stock/count`).
- Real-time comparison displays recorded book balances, counted figures, and calculated shortages or overages.
- Reconciling automatically generates offsetting `Adjust Out` (shortage) or `Adjust In` (overage) ledger transactions tagged with reason "Physical Count" under a consolidated `COUNT-YYYYMMDD-...` reference identifier.

### 6. Inter-Unit Borrowing System (FR-06)
- Record borrowed stock issued to external campus units or departments, logging the recipient name, unit, and expected return date.
- Dedicated **Borrowed Items** dashboard (`/stock/borrows`) groups records into *Not yet returned*, *Overdue*, and *Returned* tabs.
- Returns are linked to the original borrow transaction, automatically updating outstanding balances and restoring inventory upon return.

### 7. Product Catalog Archiving (FR-02)
- Products that are inactive or no longer stocked can be safely archived via Products → **Archive**.
- Archiving hides items from active selection lists, stock forms, stock-out catalogs, and physical counts while preserving historical ledger transactions, batch data, reports, and audit trails.
- Safeguards prevent archiving products that possess positive remaining stock, pending stock-out requests, or unreturned borrows.
- Archived items can be reviewed and restored at any time under the **Archived** tab.

### 8. Barcode Scanner Support (FR-10)
- Full support for hardware USB/Bluetooth barcode scanners operating in keyboard-wedge mode (value + Enter).
- Scanners can be used directly on the Stockcard item selector, stock movement forms, stock-out request forms, and physical counts.
- Generates high-resolution Code 128 SVG barcodes for individual finished products and manufactured batches, printable and downloadable directly from the browser.

---

## Installation & Deployment

The application runs on an office server PC and serves client workstations, tablets, and mobile devices connected to the local office Wi-Fi or LAN.

Three setup methods are available:

| Method | Recommended Use | Internet Required? |
|---|---|---|
| **Option C: Offline HTTPS (XAMPP)** | **Production office deployment** (always-on server) | **No** (installs and runs completely offline) |
| **Option A: Docker Compose** | Multi-platform or containerized environments | Yes (to pull images during first build) |
| **Option B: Development Mode** | Local feature development and testing | Yes (to install npm/composer packages) |

---

### Option C: Offline HTTPS Server on XAMPP (Recommended for the Office PC)

This option turns an office PC into an always-on, offline HTTPS web server using XAMPP's Apache and MySQL. No internet connection is ever required on the office PC.

#### Step 1: Prepare the portable folder on a PC with internet (Once)
On a computer with Git, PHP, Composer, and Node.js 22 installed, clone the repository and run:

```bat
setup.bat --prepare
```

This installs all PHP dependencies into `backend\vendor` and compiles the production frontend assets into `frontend\dist`.
Copy the **entire project folder** onto a USB flash drive (exclude `backend\.env` and `backend\writable\backups` if they already exist). Also download the offline [XAMPP for Windows installer (PHP 8.2+)](https://www.apachefriends.org/) onto the flash drive.

#### Step 2: Install and configure on the offline office PC
1. Run the XAMPP installer on the office PC and install it to `C:\xampp`.
2. Copy the project folder from your USB drive to your preferred local location (e.g., `C:\BSU_Inventory_System` or `C:\xampp\htdocs\BSU_Inventory_System`).
3. Double-click **`setup.bat`** (run as Administrator when prompted).

The automated installer (`deploy\setup-server.ps1`):
- Configures required PHP extensions (`mysqli`, `intl`, `mbstring`, `openssl`, `curl`, `gd`).
- Starts MySQL and restricts connections to localhost for security.
- Creates `backend\.env`, generates application encryption keys, and executes database migrations and starting seeds.
- Generates a local Certificate Authority (CA) and an SSL certificate covering all local IP addresses of the server using XAMPP's OpenSSL.
- Configures Apache VirtualHost directives with HTTPS redirect on port 80 and secure hosting on port 443.
- Registers Apache and MySQL as persistent Windows services that launch automatically on computer startup.
- Opens inbound firewall rules for ports 80 and 443 on Private networks.

The script finishes by printing the server's HTTPS addresses (e.g., `https://192.168.1.50/`).

#### Step 3: Install the Local Root Certificate on Client Devices (Once per device)
To enable secure, warning-free HTTPS browsing across devices on your local network:
1. From any phone, tablet, or client computer on the same Wi-Fi/LAN, navigate to `http://<server-ip>/bsu-inventory-ca.crt`.
2. Install the certificate as a Trusted Root Certification Authority:
   - **Windows:** Open the downloaded `.crt` file → *Install Certificate* → *Local Machine* → *Place all certificates in the following store* → browse to **Trusted Root Certification Authorities** → Complete wizard.
   - **Android:** Settings → Security / Encryption & credentials → *Install a certificate* → *CA certificate* → select the downloaded file.
   - **iOS / iPadOS:** Settings → Profile Downloaded → Install → Settings → General → About → *Certificate Trust Settings* → Enable full trust for *BSU Inventory Local CA*.
   - **Mozilla Firefox:** Open `about:config`, search for `security.enterprise_roots.enabled`, and toggle it to `true`.

> **Note on Changing IP Addresses:** If the server's IP address changes, simply run `setup.bat` again. The script will automatically renew the SSL certificate for the new IP addresses without requiring client devices to reinstall the root CA. For best results, request a permanent DHCP reservation for the server from your network administrator.

---

### Option A: Docker Deployment

Docker runs the complete stack (Nginx web server, PHP 8.2-FPM, MariaDB database) in isolated containers.

#### Prerequisites
- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (Windows with WSL 2 backend) or Docker Engine with Compose (Linux).
- [Git](https://git-scm.com/).

#### Setup & Startup
```bash
# Clone the repository
git clone https://github.com/ElliciaRuth/Inventory-system.git
cd Inventory-system

# Build and start the container stack in detached mode
docker compose up -d --build
```

The initial build compiles the Vue frontend, installs backend composer packages, provisions the MariaDB database, runs database migrations, and seeds the default administrator account.

Verify container status:
```bash
docker compose ps -a
```
Containers `db`, `php`, and `web` should show state *running*. The `setup` and `frontend` service containers exit with status `0` after initialization, which is normal.

Open your browser and navigate to **`http://localhost:8080`**.

---

### Option B: Development Mode (Without Docker)

Ideal for active code development with instant hot-module replacement (HMR).

#### Prerequisites
- PHP 8.2 or higher (with `mysqli`, `intl`, `mbstring`, `openssl`, `curl`, `gd` enabled).
- [Composer](https://getcomposer.org/).
- [Node.js](https://nodejs.org/) version 22 or higher.
- MySQL / MariaDB server running locally (e.g., from XAMPP on port 3306).

#### Windows Development Launcher
Run the batch file in dev mode:
```bat
setup.bat --dev
```
Or execute through PowerShell:
```powershell
.\setup.ps1
```
Available flags for `setup.ps1`:
- `-BackendPort 8080`: Custom port for CodeIgniter API.
- `-FrontendPort 5173`: Custom port for Vite dev server.
- `-HostAddress 0.0.0.0`: Network binding address.
- `-InstallOnly`: Install packages and migrate database without launching servers.
- `-SkipBrowser`: Prevent auto-launching default browser.

#### Linux / macOS / WSL Development Launcher
```bash
chmod +x setup.sh
./setup.sh
```

The launcher starts the CodeIgniter API server on `http://localhost:8080` and the Vite development server on `http://localhost:5173`, with automatic reverse proxying of `/api` and `/barcodes` calls.

---

### Default Accounts & Credentials

When initializing an empty database, the seeder provisions the following starting accounts:

| Username | Initial Password | Role | Assigned Office |
|---|---|---|---|
| `admin_tech` | `admin123` | Technical Staff (Level 4) | Global (System Admin) |
| `tech` | `Tech123` | Technical Staff (Level 4) | Global (System Admin) |
| `manager_bakery` | `Manager123` | Manager (Level 3) | BAKERY |
| `manager_fpc` | `Manager123` | Manager (Level 3) | FPC |
| `custodian_bakery` | `Custodian123` | Custodian (Level 2) | BAKERY |
| `custodian_fpc` | `Custodian123` | Custodian (Level 2) | FPC |
| `staff_bakery` | `Staff123` | Staff (Level 1) | BAKERY |
| `staff_fpc` | `Staff123` | Staff (Level 1) | FPC |

> **Security Warning:** The passwords listed above are public default credentials. Upon initial sign-in, all seeded accounts are required to change their password before accessing the system. You should deactivate or delete demo accounts not needed for active operations.

---

### Connecting Other Computers, Tablets, and Phones

Client devices on the same local network access the system directly through the server's local IPv4 address:

1. Identify the server's local IP address by running `ipconfig` (Windows) or `hostname -I` (Linux). Look for an address such as `192.168.1.50`.
2. Ensure inbound firewall access is permitted for your chosen port:
   - For **Option C (HTTPS)**, ports `80` and `443` are configured automatically by `setup.bat`.
   - For **Docker / Dev server (Port 8080 & 5173)**, execute in PowerShell as Administrator:
     ```powershell
     New-NetFirewallRule -DisplayName "BSU Inventory System" -Direction Inbound -Protocol TCP -LocalPort 8080,5173 -Action Allow -Profile Private,Domain
     ```
3. On connected phones, tablets, or computers, open the browser and navigate to:
   - Option C (HTTPS): `https://192.168.1.50/`
   - Option A (Docker): `http://192.168.1.50:8080/`
   - Option B (Dev): `http://192.168.1.50:5173/`
4. On mobile devices, tap browser settings and select **"Add to Home Screen"** to run the interface as a full-screen standalone web app.

> **Network Isolation Tip:** Ensure that client devices are connected to the same Wi-Fi access point and that **AP Isolation / Client Isolation** is disabled on the office router. If the router enforces isolation, turn on the Windows **Mobile Hotspot** feature on the server PC and connect client tablets directly to the hotspot.

---

## Day-to-Day Operations

### Backup & Disaster Recovery

Managers configure and trigger backups under **Menu → Management → Others Management → Data Backup & Restore**.

- **Creating Backups:** Click **Create Backup**. Choose the modules to package:
  - *Office Setup:* Entities, product types, reference types, and units.
  - *Inventory Records:* Products, batches, ledger transactions, stock-out requests, and borrows.
  - *Users & Accounts:* Office user profiles and account records.
  - *System Settings:* Expiry alert thresholds and application configurations.
  - *Password Protection:* Optional AES-256 encryption. Creates a secure `.bsubackup` file.
- **Scheduled Backups:** Managers set the automated backup interval (e.g., every 24 hours). The system executes an automated backup in the background when a Custodian or Manager signs in and a backup is due.
- **Restoring Data:** Click **Restore Backup** and select an uploaded file or an archive stored on the server. The inspector validates file integrity, checks office matches, prompts for a decryption password if protected, and displays a breakdown of contents. You select which sections to apply.
- **Pre-Restore Snapshot:** A full safety backup of the active office database is created automatically immediately before any restore operation is executed.

### Importing Appendix 58 Stock Cards from Excel

1. Navigate to **Stockcard Ledger** or **Product List** and click **Import from Excel**.
2. Select an `.xlsx` workbook formatted according to Appendix 58 specifications.
3. The server inspects the file, normalizes units, cleans spellings, links dates, and opens the **Import Preview** dialog.
4. Review extracted products, starting balances, transaction lines, and running balances. You can adjust the assigned product classification or edit item mappings directly in the dialog.
5. Select the import mode:
   - **Keep existing data:** Appends new transaction rows while automatically skipping rows that have been imported previously.
   - **Delete existing inventory first:** Purges existing products, batches, and transactions for the affected office prior to loading. Requires re-entering the Manager's password. A safety backup is created automatically before deletion.

### Physical Inventory Count & Reconciliation

1. Open **Menu → Inventory & Stock → Physical Count** (`/stock/count`).
2. The grid displays all active items with their current system ledger balances.
3. Input actual shelf count quantities manually or scan batch barcodes with a handheld scanner.
4. The system calculates and displays variances in real-time:
   - **Shortage (Negative variance):** Physical stock is lower than system balance.
   - **Overage (Positive variance):** Physical stock exceeds system balance.
5. Click **Reconcile Count**. The system automatically creates `Adjust Out` or `Adjust In` ledger transactions tagged with reason "Physical Count" and assigned a shared reference ID (e.g., `COUNT-20261010-...`).

### Inter-Unit Borrowing & Returns

1. To record lent items, select transaction type **Lend / Borrow Out** on the Stockcard modal. Specify the borrower's name, department/unit, and due date.
2. Monitor outstanding loans under **Menu → Inventory & Stock → Borrowed Items** (`/stock/borrows`). Overdue items are flagged with warning indicators.
3. When items are returned, click **Return** directly on the borrow record. Enter the quantity returned and any condition notes. The return is recorded as a linked `Return` transaction on the ledger, replenishing available stock and updating the open borrow balance.

### FEFO Stock Issuance & Batch Tracking

- All stock deductions (stock-outs, issues, and lending) automatically prioritize batches expiring soonest.
- Under **Products & Catalog → Batch Barcodes** (`/reports/batches`), custodians can:
  - Search batches by batch number or product title.
  - View manufacturing dates, expiration dates, and remaining stock.
  - Toggle visibility of exhausted batches via **Show empty batches**.
  - Download individual batch barcode SVGs for labeling.

### Product Catalog Archiving & Restoration

- When a product is discontinued, click **Archive** on the Product List. Provide a brief reason for archiving.
- The item is hidden from day-to-day operations while all historical transactions and stockcard balances are preserved.
- To view or reactivate archived items, navigate to the **Archived** tab on the Product List and click **Restore**.

### Barcode Generation & Scanning

- **Finished Products:** Generate unique Code 128 barcodes under **Products & Catalog → Finished Products** (`/products/barcodes`).
- **Batches:** Generated automatically upon batch creation following the unique naming structure:
  ```
  B-{OFFICE}-{YYYYMMDD}-{PRODUCT_ID}-{INDEX}
  ```
- **Scanning:** Any hardware scanner emulating keyboard input can scan into search inputs, the Stockcard item selector, or the stock-out request scanner field.

---

## Security & Architecture Guardrails

### 1. Request Limits & DoS Prevention (`backend/app/Config/RequestLimits.php`)
Every API call passes through `RequestGuard` middleware, enforcing strict boundaries:
- **Payload Size Limits:** Max 1 MB for standard JSON/form payloads; 40 MB for upload routes (`import/stockcards/preview`, `backups/restore`, `backups/inspect`).
- **Data Complexity:** Max 5,000 array elements/fields, max 10 levels of nesting, and max 10,000 characters per text field.
- **Archive Extraction Protection:** Decompression limits against "zip bombs" enforce a maximum of 64 MB per individual uncompressed file and 160 MB uncompressed total size, capped at 20,000 entries.
- **Rate Limiting:**
  - Standard API traffic: 600 requests per minute per IP.
  - State mutations (POST/PUT/PATCH/DELETE): 90 requests per minute per user.
  - Public authentication limits: Login capped at 30 req/min; registration at 5 req/hour; password reset at 10 requests per 15 minutes.

### 2. Brute-Force & Lockout Protection
- Accounts are locked for 15 minutes following 5 consecutive failed login attempts for a specific username.
- IP addresses triggering 20 failed login attempts across accounts are similarly throttled for 15 minutes.
- Suspicious or blocked requests are automatically logged to the Audit Trail.

### 3. Password Storage & Zero Persisted SMTP Credentials
- Passwords are encrypted using PHP's native `password_hash()` with `PASSWORD_DEFAULT` (bcrypt). Legacy plain-text passwords have been automatically hashed and upgraded.
- System-wide plaintext SMTP credential storage has been eliminated (`smtp_settings` table dropped). Password recovery utilizes user-provided ephemeral app passwords or direct administrative reset.

---

## Troubleshooting & Maintenance

### Resetting Forgotten Passwords

#### Option 1: Manager Self-Service
An office Manager can reset passwords for staff in their office via **Others Management → Users → Edit User**.

#### Option 2: Spark CLI Command (Offline Server)
If an administrator account password is forgotten, run the password reset command from a terminal on the host computer:

**On XAMPP (Option C):**
Open Command Prompt in the `backend` folder and run:
```bat
C:\xampp\php\php.exe spark user:reset-password admin_tech
```

**On Docker (Option A):**
```bash
docker compose exec php php spark user:reset-password admin_tech
```

The command generates and outputs a temporary password. Sign in with the temporary password and set a new password on the prompted setup screen.

---

### Common Questions & Issues

| Issue | Cause | Solution |
|---|---|---|
| `Port 8080 is already allocated` | Another local application is utilizing port 8080. | For Docker, launch with `INVENTORY_PORT=9090 docker compose up -d`. For dev mode, use `.\setup.ps1 -BackendPort 9090`. |
| Client devices cannot load the page | Local firewall is blocking incoming traffic. | Verify the server and client are on the same Wi-Fi network. Re-run the firewall PowerShell rule command in Administrator mode. |
| Browser displays `Not Secure` over HTTPS | Local CA certificate is not installed on client device. | Download `http://<server-ip>/bsu-inventory-ca.crt` on the device and install it into the Trusted Root Certification Authorities store. |
| Server IP changed after router reboot | DHCP lease expired and router assigned a new IP. | Re-run `setup.bat` on the server PC. It will automatically update the Apache configuration and renew the local SSL certificate for the new IP. |
| Changes in frontend code do not show | Cached production assets or pending build. | In development, ensure Vite is running (`npm run dev`). In production/Docker, rebuild assets with `docker compose up -d --build` or `npm run build`. |

---

## Development Workflow

### Backend (CodeIgniter 4)
```bash
cd backend
composer install
cp env .env              # Configure database and set app.baseURL = 'http://localhost:8080/'
php spark migrate        # Execute database migrations
php spark db:seed DatabaseSeeder  # Populate starting data (empty DB only)
php spark serve --port 8080       # Serves API on http://localhost:8080/api/
```

### Frontend (Vue 3 + Vite)
```bash
cd frontend
npm install
npm run dev              # Starts development server on http://localhost:5173
```
The Vite development server automatically proxies calls matching `/api` and `/barcodes` to `http://localhost:8080`.

To build the optimized production distribution:
```bash
cd frontend
npm run build            # Compiles distribution to frontend/dist/
```

---

## Production Server Architecture

In production setups (such as the Docker Nginx container or XAMPP Apache):
1. **Frontend Distribution:** Pre-compiled static assets in `frontend/dist` are served directly by the web server. HTML5 history mode routing is supported via fallback rewrites to `index.html`.
2. **API Proxying:** Requests prefixed with `/api/*` are passed directly to CodeIgniter's front controller (`backend/public/index.php`) preserving original query parameters and request URIs.
3. **Barcode Storage:** Barcode SVG files generated by the backend are stored in `backend/public/barcodes/` and served under `/barcodes/`.
4. **Writable Directories:** Ensure `backend/writable/` (logs, session files, cached data, backups) and `backend/public/barcodes/` are writable by the web server process.

---

## Version History & Changelog

| Version | Release Date | Key Enhancements & Changes |
|---|---|---|
| **`v2.2.0`** *(Current)* | October 2026 | **Full Functional Requirements & Security Hardening Release:**<br>• Appendix 58 Excel workbook importer with interactive preview and spell-check auto-correction.<br>• Automated First-Expired, First-Out (FEFO) stock issuance and real-time batch planning.<br>• Tamper-evident, trigger-protected append-only audit trail (`audit_log`) with before/after diffs.<br>• Modular `.zip` and AES-256 encrypted `.bsubackup` disaster recovery archives with SHA-256 manifests.<br>• Physical inventory count reconciler with automatic shortage/overage offsetting.<br>• Inter-unit borrowing tracking with overdue flags, linked returns, and balance replenishment.<br>• Product catalog archiving with safety validations against active stock and pending loans.<br>• Hardware barcode scanning & SVG generation for batches (`B-OFFICE-YYYYMMDD-PRODUCT-NN`) and products.<br>• Removal of stored plaintext SMTP credentials; user self-service ephemeral app-key resets.<br>• `RequestGuard` DoS/zip-bomb protection and IP/user rate-limiting.<br>• Offline XAMPP HTTPS server with automated local Root CA certificate authority provisioning. |
| **`v2.1.0`** | September 2026 | **Administrative & Operational Hardening:**<br>• Unified Profile management portal (`/profile`) replacing legacy password views.<br>• Protected technical staff administrator accounts against deletion and deactivation.<br>• Uniform Lucide icon set and responsive mobile mega-menu navigation.<br>• Cross-platform deployment and server launcher scripts (`setup.bat`, `setup.ps1`, `setup.sh`).<br>• Decimal quantity precision and custom per-product expiration alert thresholds. |
| **`v2.0.0`** | July 2026 | **Decoupled Architecture Modernization:**<br>• Decoupled system into `backend/` (CodeIgniter 4 REST JSON API) and `frontend/` (Vue 3 + Vite SPA).<br>• Single-command containerized local Docker stack (`docker-compose.yml`).<br>• Responsive smartphone and tablet views with optimized card layouts.<br>• Office-level multi-tenant data segregation. |
| **`v1.0.0`** | June 2026 | **Initial Baseline:**<br>• Monolithic CodeIgniter server-rendered inventory and stock-out prototype. |

---

## API Reference

All backend API endpoints return structured JSON responses:
```json
{
  "status": true,
  "message": "Operation completed successfully.",
  "data": { ... }
}
```

Authentication is managed via HTTP cookies (`ci_session`).

| Resource Area | Route Path & Methods | Minimum Access Level | Notes |
|---|---|---|---|
| **Authentication** | `POST api/auth/login`<br>`GET api/auth/me`<br>`POST api/auth/logout`<br>`GET api/auth/register-options`<br>`POST api/auth/register`<br>`POST api/auth/forgot-password`<br>`POST api/auth/verify-reset-code`<br>`POST api/auth/reset-password` | Public | Handles authentication, registration, and self-service 15-minute verification code resets. |
| **Account** | `POST api/auth/update-profile`<br>`POST api/auth/change-password` | Authenticated | Updates name, email, and password. Updating sensitive fields requires `current_password`. |
| **Dashboard** | `GET api/dashboard`<br>`GET api/transactions`<br>`GET api/notifications` | Level 1 (Staff) | Returns overview statistics, transaction histories, and user notification feeds. |
| **Products** | `GET api/products`<br>`GET api/products/meta`<br>`GET api/products/{id}`<br>`POST api/products`<br>`PUT/PATCH api/products/{id}`<br>`DELETE api/products/{id}`<br>`POST api/products/{id}/archive`<br>`POST api/products/{id}/restore`<br>`GET api/products/barcodes`<br>`POST api/products/barcodes/generate` | Level 1 (Read)<br>Level 2 (Write) | Product catalog management. `GET api/products?archived=1` returns archived catalog items. |
| **Stockcard & Stock** | `GET api/stockcard`<br>`GET api/stock/options`<br>`POST api/stock/add`<br>`POST api/stock/edit-transaction`<br>`POST api/stock/delete-transaction`<br>`POST api/stock/edit-report-cost`<br>`GET api/stock/batch-plan`<br>`GET api/stock/borrows`<br>`POST api/stock/count`<br>`GET api/stock/copies/{id}` | Level 2 (Custodian) | Core ledger transactions (receipts, issues, lends, returns, adjust in/out), FEFO batch depletion planning, physical counts, and inter-unit borrows. |
| **Excel Import** | `POST api/import/stockcards/preview`<br>`GET api/import/stockcards/card`<br>`POST api/import/stockcards/revise`<br>`POST api/import/stockcards/commit` | Level 2 (Custodian) | Uploads and processes Appendix 58 `.xlsx` workbooks. Commit mode `replace` requires manager password verification. |
| **Audit Trail** | `GET api/audit-logs` | Level 3 (Manager) | Immutable audit log records. Managers query their office; Technical Staff query all university offices. |
| **Reports & Barcodes**| `GET api/reports/batches`<br>`GET api/reports/batchlist`<br>`GET api/barcode/product/{id}`<br>`GET api/barcode/batch/{id}`<br>`GET api/barcode/lookup` | Level 2 (Reports)<br>Level 1 (Lookup) | Batch summaries and Code 128 barcode lookups. |
| **Exports** | `GET/POST api/export/stockcard`<br>`GET api/export/stockcard/options`<br>`POST api/export/summary`<br>`GET api/export/summary/options` | Level 2 (Custodian) | Generates and streams Excel/CSV downloads of stockcards and inventory summary reports. |
| **System Settings** | `GET api/settings`<br>`GET api/settings/system`<br>`POST api/settings/system`<br>`GET api/settings/{type}/{id}`<br>`POST api/settings/{type}`<br>`DELETE api/settings/{type}/{id}`<br>`POST api/settings/users/{id}/activate`<br>`POST api/settings/users/{id}/deactivate` | Level 3 (Manager)<br>Level 4 (Admin) | Manages auxiliary tables (entities, units, references, categories, offices, users). Technical staff accounts cannot be deactivated or deleted. |
| **Backup & Recovery** | `GET api/backups`<br>`POST api/backups/run`<br>`POST api/backups/auto`<br>`GET api/backups/{id}/download`<br>`POST api/backups/inspect`<br>`POST api/backups/restore`<br>`POST api/backups/config` | Level 3 (Manager)<br>Level 2 (Auto on login) | Manages modular `.zip` and encrypted `.bsubackup` packages, inspects packages, and triggers restores. |
| **Stock-Out Workflow**| `GET api/stockout`<br>`GET api/stockout/temp`<br>`POST api/stockout/add-temp`<br>`POST api/stockout/edit-temp/{id}`<br>`POST api/stockout/remove-temp/{id}`<br>`POST api/stockout/submit`<br>`GET api/stockout/history`<br>`GET api/stockout/pending`<br>`POST api/stockout/approve-item/{id}`<br>`POST api/stockout/approve-all/{id}`<br>`POST api/stockout/reject-item/{id}`<br>`POST api/stockout/edit-pending/{id}` | Level 1 (Submit)<br>Level 2 (Approve) | Complete request-to-approval lifecycle. Custodian rejection requires providing an explanatory feedback reason. |

---

*Benguet State University Integrated Inventory Monitoring System v2.2.0 · Developed for BSU Operating & Production Units.*
