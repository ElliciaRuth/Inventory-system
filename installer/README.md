# BSU Inventory System — Windows Server Installer (Laragon)

`BSU-Inventory-Setup.exe` turns one Windows PC into the **BSU Inventory server** for the local
network. It installs everything the system needs (its own copy of Laragon with Apache, PHP and
MariaDB), configures it, and starts it as Windows services. Other computers, tablets and phones
only need a web browser.

No internet connection is needed to install or to use the system. Nobody has to run
`composer`, `npm`, `php spark` or a command prompt.

```
                      BSU LAN (private network)
                               │
                 ┌─────────────▼──────────────┐
                 │  BSU Inventory server PC   │
                 │                            │
                 │  BSUInventoryWeb service   │  ← https://<server IP>/   (TCP 443, 80)
                 │   Laragon Apache + PHP     │
                 │          │                 │
                 │  BSUInventoryDB service    │  ← 127.0.0.1 only (never on the network)
                 │   Laragon MariaDB          │
                 └─────────────┬──────────────┘
                 ┌─────────────┼──────────────┐
              Admin PC      Staff PC      Phone (camera scanning)
              browser       browser       browser
```

Contents

1. [What is installed](#1-what-is-installed)
2. [Requirements](#2-requirements)
3. [Installing](#3-installing)
4. [First run: the administrator account](#4-first-run-the-administrator-account)
5. [Connecting other computers and phones](#5-connecting-other-computers-and-phones)
6. [LAN setup: IP address, firewall, ports](#6-lan-setup-ip-address-firewall-ports)
7. [Daily use: the shortcut and the Server Manager](#7-daily-use-the-shortcut-and-the-server-manager)
8. [Backups](#8-backups)
9. [Restoring a backup](#9-restoring-a-backup)
10. [Updating to a new version](#10-updating-to-a-new-version)
11. [Uninstalling](#11-uninstalling)
12. [Security](#12-security)
13. [Working without internet](#13-working-without-internet)
14. [Troubleshooting](#14-troubleshooting)
15. [Moving from the XAMPP setup (setup.bat)](#15-moving-from-the-xampp-setup-setupbat)
16. [Building the installer](#16-building-the-installer)
17. [Test plan](#17-test-plan)
18. [Reference: files, services, logs](#18-reference-files-services-logs)

---

## 1. What is installed

| Part | Version | Notes |
|---|---|---|
| Laragon | 6.0.0 | BSU's **own copy** inside the install folder. Laragon 6.0 is the last free release (7.x and later need a paid licence). Its window is kept for its tools (HeidiSQL). |
| Apache | 2.4.69 (Apache Lounge, VS18 build) | Replaces Laragon 6's 2022 Apache. Runs as the Windows service `BSUInventoryWeb`. |
| PHP | 8.3.35 (thread-safe, x64) | The backend needs PHP ≥ 8.2 (CodeIgniter 4.7.5). Loaded into Apache. Extensions: intl, mbstring, mysqli, openssl, zip, gd, fileinfo, OPcache. Nothing else is enabled. |
| MariaDB | 10.11.19 LTS | The version `docker-compose.yml` uses. Runs as the Windows service `BSUInventoryDB`, listening on 127.0.0.1 only. |
| Visual C++ runtime | 2015–2026 x64 | Needed by Apache and PHP; installed or updated by the installer. |
| BSU Inventory System | from `frontend/package.json` | CodeIgniter 4.7.5 backend with production Composer packages only; the Vue 3 frontend as a built `dist` folder (no sources, no `node_modules`). |

**Not** installed on the server: Composer, Node.js, npm, Git, Python, Nginx, Redis, or Laragon 6's
old Apache, PHP 8.1 and MySQL 8.0.

The installer never changes another Laragon (for example `C:\laragon`). If it finds one, it
says so and leaves it alone.

### Why HTTPS (and not plain `http://192.168.x.x`)

Browsers only allow the camera on a secure (https) address, and the app's barcode scanner uses the
camera. So the system runs on `https://<server IP>/` with a certificate from a small local
certificate authority (CA) that the installer creates on the server. Each device trusts that
CA once (section 5). Plain `http://` just redirects to `https://`. This is the same design as the
existing XAMPP setup (`deploy/setup-server.ps1`).

### Why Windows services (and not Laragon's "Start All")

Laragon itself starts Apache and MySQL only after someone logs in and opens it. A server must be
available after Windows Update restarts it at night, before anyone logs in. So Laragon's Apache and
MariaDB are registered as Windows services that start with Windows. Laragon's own auto-start is
switched off, and **its "Start All" button must not be used** on the server.

---

## 2. Requirements

**Server PC**

- Windows 10 version 1809 or newer, Windows 11, or Windows Server 2019/2022/2025, **64-bit**
- 4 GB RAM or more; 3 GB free disk space, plus room for the data and backups
- An administrator account (only to install, update, uninstall or repair)
- A wired LAN connection with a **fixed address** (a DHCP reservation, see section 6)
- Ports **80 and 443** not used by another program (stop or uninstall XAMPP, IIS, Skype for
  Business, VMware's shared VMs or another Laragon first, or choose other ports in the installer)
- A second disk, network share or USB drive for backups (recommended)

**Client computers, tablets, phones**: a current browser (Chrome, Edge, Firefox, Safari). Nothing
else: no Laragon, PHP, MySQL, Composer or Node.js.

---

## 3. Installing

1. Copy `BSU-Inventory-Setup.exe` to the server PC (USB is fine; no internet needed).
2. Optional: check the file is intact. In PowerShell, run `Get-FileHash BSU-Inventory-Setup.exe`
   and compare the result with `BSU-Inventory-Setup.exe.sha256`.
3. Double-click it and allow it to make changes (administrator).
4. Go through the wizard:

| Page | What to enter |
|---|---|
| Installation location | Default `C:\BSU-Inventory`. Folder names must not have spaces. |
| Network (LAN access) | Keep ports **80/443** unless another program needs them. Keep **Enable LAN access** checked so other computers can connect (it opens only these two ports, only on private networks, only from LAN addresses). The page shows this PC's LAN address and warns if it comes from DHCP. |
| Administrator account | Name, username, email and password of the first **Technical Staff** account (rules: at least 8 characters with upper- and lowercase letters and a number, no `123`-style sequences). No account with a default password is ever created. |
| Backups | A folder **outside** the install folder, preferably on another disk (`D:\BSU Inventory Backups` is suggested when a D: disk exists). Daily automatic backup on/off and its time. |
| Checking this computer | Shows what is installed and checks ports, other Laragon installs, LAN address and network type, and disk space. Items marked ✗ must be fixed first. |
| Shortcuts | Desktop shortcut (Start menu shortcuts are always created). |

5. Click **Install**. Copying takes a minute. Then "Configuring the server" takes 1–3 minutes:
   database, migrations, HTTPS certificate, services, firewall, health check.
6. On the last page, tick **Open BSU Inventory now**. To see the addresses other computers use,
   tick **Show how other computers connect**.

If configuring fails, the files stay installed and a message names the log
(`C:\BSU-Inventory\logs\configure-install-*.log`). Fix the cause (usually a port taken by another
program), then open **Start → BSU Inventory → BSU Inventory Server Manager → Repair
configuration**.

### Silent install (IT staff)

A first installation needs the administrator account in a small JSON file:

```json
{"first_name": "Maria", "last_name": "Dela Cruz", "username": "it.admin", "email": "it.admin@bsu.edu.ph", "password": "…"}
```

```
BSU-Inventory-Setup.exe /VERYSILENT /SUPPRESSMSGBOXES /NORESTART /ADMINFILE="C:\temp\admin.json"
    [/DIR=C:\BSU-Inventory] [/HTTPPORT=80] [/HTTPSPORT=443] [/LAN=1] [/BACKUPDIR="D:\BSU Inventory Backups"]
    [/DAILYBACKUP=1] [/BACKUPTIME=17:30] [/TASKS=desktopicon] [/LOG="C:\temp\setup.log"]
```

The installer copies the file into its private temporary folder, and that copy is deleted right after
the account is created. **Delete your own file afterwards**, because it contains the password.
The exit code is 0 on success. Updates need no options: `BSU-Inventory-Setup.exe /VERYSILENT /SUPPRESSMSGBOXES`.

---

## 4. First run: the administrator account

1. On the server, double-click **BSU Inventory** on the desktop. The login page opens at
   `https://localhost/`.
2. Sign in with the username and password entered in the installer. This account is
   **Technical Staff** (level 4, all offices).
3. Create the other accounts in **Office Settings → Users**, or let staff sign up and approve them
   (managers approve sign-ups for their office).
4. Check **Office Settings → Backups** for the app's own per-office backups. Their default folder is
   `<backup folder>\in-app`.

The installer adds only the fixed reference lists the system needs: access levels, account
statuses, transaction types, adjustment reasons, the BAKERY and FPC offices and their default
entity, and product types. It adds **no demo accounts and no inventory data**.

Lost the administrator password? Offline, use **Server Manager → Reset a password…** (it gives a
temporary password that must be changed at the next login), or **Create administrator…**.

---

## 5. Connecting other computers and phones

**Start → BSU Inventory → How other computers connect** shows this server's addresses and the CA
fingerprint (the file is `C:\BSU-Inventory\config\connection-info.txt`).

Once per device, trust the server's certificate authority:

1. On the device, open `http://<server IP>/bsu-inventory-ca.crt` and save the file.
2. Install it:
   - **Windows**: double-click → *Install Certificate* → *Local Machine* → *Place all certificates
     in the following store* → **Trusted Root Certification Authorities**.
   - **Android**: Settings → Security → Encryption & credentials → Install a certificate → **CA
     certificate**.
   - **iPhone/iPad**: open the file → install the profile (Settings → Profile Downloaded), then
     Settings → General → About → **Certificate Trust Settings** → switch it on.
3. Check that the SHA-256 fingerprint shown matches the one in `connection-info.txt`.
4. Open `https://<server IP>/` and bookmark it.

The CA can only vouch for this server and private network addresses (name constraints). Even if
its key were stolen, it could not be used to impersonate real websites on devices that trust it.

Several devices can use the system at the same time. All data stays in the server's single
database.

---

## 6. LAN setup: IP address, firewall, ports

**Fixed address.** Clients use the server's IP address, so it must not change. Ask the network
administrator for a **DHCP reservation** for the server's network adapter, or give it a static
address in *Windows Settings → Network & internet → (adapter) → IP assignment*. The installer
reports a DHCP address but **does not change Windows network settings**.

If the address changes anyway, open **Server Manager → Repair configuration**. Repair issues a new
HTTPS certificate for the new address, and devices that trust the CA keep working without
installing anything again. Then tell users the new address.

**Network type.** The server's network must be **Private** (or a domain network). On a *Public*
network Windows blocks other PCs; the installer and the Server Manager warn about it. To change it:
*Windows Settings → Network & internet → (the network) → Network profile type → Private*.

**Firewall.** The installer creates one inbound rule, `BSU Inventory (web)`:

| Setting | Value |
|---|---|
| Ports | TCP 443 (HTTPS) and TCP 80 (redirect + CA download), or the ports chosen in the installer |
| Program | Laragon's `httpd.exe` only |
| Profiles | Private, Domain (not Public) |
| Remote addresses | 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16 (LAN only) |

The database port (3306, or the next free one if another program uses 3306) is **never** opened.
MariaDB listens on 127.0.0.1 only. Do not forward ports 80/443 on the router: the system is meant
for the BSU LAN, not the internet.

**Other ports.** If 80/443 are taken, choose e.g. 8080/8443 in the installer. The address then
includes the port: `https://192.168.1.100:8443/`.

---

## 7. Daily use: the shortcut and the Server Manager

**BSU Inventory** (desktop and Start menu) checks that the services run, starts them if needed (no
administrator rights needed), waits until the system answers, and opens the login page.

The services start with Windows by themselves, so after a restart the system is available to
the network **before anyone logs in** to the server.

**BSU Inventory Server Manager** (Start menu; asks for administrator rights):

| Shows | |
|---|---|
| Server status | ● Running / Running, with problems / Stopped |
| Laragon, Web server, PHP, Database server, Database connection, Application, LAN access | ✔ or ✖ with the reason |
| Server addresses | Every LAN URL, with a DHCP warning |

| Button | What it does |
|---|---|
| Open BSU Inventory / Copy LAN address / How other PCs connect | as named |
| Start / Stop / Restart services | Stop makes the system unavailable to everyone |
| Repair configuration | Rewrites the configuration, renews the HTTPS certificate for the current IP, runs migrations, restarts. Data is not changed. |
| Back up now / Restore backup… / Open backups folder | Section 8 and 9 |
| Open logs folder | `C:\BSU-Inventory\logs` |
| Laragon (database tools) | Opens Laragon for HeidiSQL. **Do not click "Start All".** |
| Create administrator… / Reset a password… | Offline account recovery |

The same checks print in a console with
`powershell -ExecutionPolicy Bypass -File C:\BSU-Inventory\manager\BSU-Inventory-Manager.ps1 -Report`.

---

## 8. Backups

There are two kinds of backup.

**Full server backups** (this installer). They are written to `<backup folder>\full\` and can be
read by Administrators only. Each one is a single `BSU-Inventory-Backup-<date>-<reason>.zip`:

| Inside | |
|---|---|
| `database.sql` | the complete `inventory_system` database (inventory, stock movements, borrowing, stock-out requests, users, roles, settings, audit log) |
| `config\backend.env`, `config\install.json` | application and installer settings |
| `certs\` | the local certificate authority, so devices keep working after moving to a new server |
| `files\uploads`, `files\barcodes`, `files\backup_config.json` | uploaded files, batch barcode images, in-app backup settings |
| `manifest.json` | contents with a SHA-256 checksum of every file (checked before a restore) |

When full backups are made:

- **daily** at the time chosen in the installer, by the task *BSU Inventory Daily Backup*. A backup
  missed while the PC was off runs when it is back on. The newest 30 daily backups are kept.
- **before every update** and **before an uninstall** that deletes the database (if chosen). These
  are never deleted automatically.
- whenever you click **Server Manager → Back up now**.

**In-app backups** (Office Settings → Backups) are per-office packages that office managers can
open and check. Their default folder is `<backup folder>\in-app`. They keep working as before.

**Keep a copy elsewhere.** A backup on the same PC does not survive theft, fire or a ransomware
attack. Copy `<backup folder>\full` regularly to another place, for example an external drive kept
in another room or a university file share. The backups contain password hashes, email
addresses and the CA key, so store them securely.

---

## 9. Restoring a backup

**On the same server** (for example after a mistake): **Server Manager → Restore backup…**, choose
the zip, confirm, and answer **No** to "restore the certificate authority". The restore:

1. checks every file against the manifest
2. makes a safety backup of the current state (`…-pre-restore.zip`)
3. replaces the database, and puts back uploaded files and barcode images
4. runs Repair: migrations (a backup from an older version is brought up to date), permissions,
   services, health check

`backend\.env` is not replaced, because it holds this server's own database account.

**On a new server PC** (the old one failed or is being replaced):

1. Install `BSU-Inventory-Setup.exe` on the new PC (any temporary administrator; the restore
   replaces the user accounts anyway).
2. **Server Manager → Restore backup…**, choose the newest backup, and answer **Yes** to "restore
   the certificate authority". Devices that trusted the old server then keep working.
3. Give the new PC the old PC's IP address (DHCP reservation), or tell users the new address. Run
   **Repair configuration** after any address change.

From a command prompt (as administrator):

```
powershell -ExecutionPolicy Bypass -File C:\BSU-Inventory\manager\restore.ps1 -BackupFile "D:\BSU Inventory Backups\full\BSU-Inventory-Backup-20261012-173000-scheduled.zip" [-RestoreCertificates]
```

---

## 10. Updating to a new version

Run the newer `BSU-Inventory-Setup.exe` on the server. It finds the existing installation and:

1. makes a **full backup** with the installed version's tools. **If the backup fails, nothing is
   changed** and the setup stops.
2. stops the services (users cannot work for a few minutes, so update outside office hours)
3. replaces only the program folders (`laragon\bin\apache|php|mysql`, the backend's
   `app`/`vendor`, `frontend\dist`, `manager`)
4. keeps `backend\.env`, the database (`laragon\data\bsu-inventory-db`), uploaded files, barcode
   images, certificates, settings, ports and backups
5. runs the database **migrations**. Inventory quantities and history are never reset: the
   migrations only add or change structure, and seeding only adds missing reference values.
6. starts the services and checks that the system answers locally and on the LAN address

The wizard pages for network, administrator and backups are skipped on an update. An older
setup cannot be installed over a newer one without a warning.

If an update fails, restore the `…-pre-update.zip` backup (section 9) after reinstalling the
previous version.

---

## 11. Uninstalling

**Settings → Apps → BSU Inventory System → Uninstall** (or Start → BSU Inventory → Uninstall).

The program, the two Windows services, the firewall rule, the daily backup task and **BSU's own
Laragon copy** are always removed. **No other Laragon installation is touched.** You choose what
else to remove:

| Option | Default | |
|---|---|---|
| The database | kept | If chosen: *"The BSU Inventory database contains existing records…"* → **Create Backup** / **Continue Without Backup** / **Cancel** |
| Uploaded files and barcode images | kept | |
| Configuration (.env, database login, CA) | kept | Only together with the database. If chosen, the CA is also removed from this PC's trusted certificates. |
| Logs | removed | |
| All backups | kept | Asks again if the database is deleted too without a new backup |

Kept items stay in `C:\BSU-Inventory`. Installing again into the same folder continues with them:
the wizard detects them, keeps their settings and skips the administrator page.

A silent uninstall (`C:\BSU-Inventory\uninstall\unins000.exe /VERYSILENT`) keeps all data. With
`/PURGE=1` it removes the database, uploaded files, configuration and logs, after making a full
backup first (`/BACKUP=0` skips that backup). The backup folder is always kept.

---

## 12. Security

- **Database behind the application.** MariaDB listens on 127.0.0.1 only. Its port is not opened
  in the firewall, and clients talk only to `https://SERVER-IP/`.
- **No root for the app.** The application signs in as `inventory_user`, which has rights on the
  `inventory_system` database only. The MariaDB root password is random and stored in
  `config\secrets\db-root.cnf`, readable by Administrators only.
- **No default passwords.** The first administrator is chosen in the installer. Demo accounts
  (`admin_tech`/`admin123` etc. from `DatabaseSeeder`) are never created on a server.
- **Production mode.** `CI_ENVIRONMENT = production`: no debug toolbar or error details. PHP has
  `display_errors = Off` and logs to `logs\php\php_errors.log`. Apache does not reveal versions.
- **Nothing private is web-reachable.** Apache serves only `frontend\dist`, barcode SVGs and
  `backend\public\index.php`. `.env`, `app\`, `vendor\` and `writable\` are outside what it serves,
  and everything else is denied by default.
- **Least privilege.** Apache and MariaDB run under their own virtual accounts
  (`NT SERVICE\BSUInventoryWeb`, `NT SERVICE\BSUInventoryDB`), not as SYSTEM or an administrator.
  They can write only to their own folders. The install folder can be changed only by
  Administrators; `.env` can be read only by Administrators and the web service.
- **LAN only.** The firewall rule allows only private addresses on private/domain networks.
- **Access control is the application's.** Logins, the four levels (Staff, Custodian, Manager,
  Technical Staff) and per-office permissions work exactly as before.
- **Logs contain no secrets.** Install, repair, backup and restore logs mask passwords and keys.
- **Signed users can start, not stop.** Signed-in Windows users may start the services (the
  shortcut does). Stopping them needs an administrator.

---

## 13. Working without internet

Everything runs on the server, and fonts and icons are bundled in the app, so these work with the
LAN only and no internet: login, viewing/adding/editing inventory, stock movements, borrowing and
returns, stock-out requests and approvals, physical count, barcode generation and camera scanning,
Excel import, PDF/Excel exports and reports, user management, audit log, and backups.

**Needs internet:** only **"Forgot password"**. It emails a code through the user's own email
account and needs internet only while it sends. Without internet, a manager sets a new password
in Office Settings → Users, or an administrator uses **Server Manager → Reset a password…**.

---

## 14. Troubleshooting

| Problem | What to do |
|---|---|
| Installer: "Port 80/443 is used by …" | Stop that program (XAMPP: Control Panel → Stop, and uncheck its services; IIS: *Turn Windows features off*; another Laragon: Stop All). Then click Next again, or go Back and choose other ports. |
| "Configuring the server failed" | Open the newest `logs\configure-install-*.log`; the last lines name the cause. Fix it, then Server Manager → **Repair configuration**. |
| Other PCs can't connect, the server itself can | Server Manager → LAN access. Usually the network is set to *Public* (set it to Private) or the address changed (Repair). Check that `ping <server IP>` works from the client. |
| Browser says "Your connection is not private" | That device has not trusted the CA yet (section 5), or the server's IP changed: run Repair on the server. |
| Barcode camera doesn't start | Use the `https://` address (not `http://`), and allow camera access when the browser asks. |
| Server Manager: Database server ✖ | Start services. If it still fails, read `logs\mariadb\error.log` (for example: disk full). |
| Database connection ✖ (MariaDB running) | Run Repair. It resets the application account's password from `backend\.env`. |
| Application ✖ | Read `logs\apache\error.log` and `logs\php\php_errors.log`, and the app's `laragon\www\bsu-inventory\backend\writable\logs`. |
| Someone pressed "Start All" in Laragon | Click "Stop All" in Laragon, then Server Manager → Restart services. |
| The IP address changed | Server Manager → Repair configuration; tell users the new address. |
| Daily backups missing | Task Scheduler → *BSU Inventory Daily Backup* → History; `logs\backup.log`. Check that the backup drive is connected. |
| Lost the database root login (`config\secrets\db-root.cnf`) | Restore that file from a full backup made on this PC (it is not in backups, by design). Otherwise: stop `BSUInventoryDB`, and reset the root password as described in MariaDB's documentation ("Recover the root password", `--skip-grant-tables`). Then write the new password into `db-root.cnf` and run Repair. |
| Need the install log | `logs\setup-<version>-*.log` (Inno Setup), `logs\configure-*.log`, `logs\update-*.log`. Uninstall logs go to `%TEMP%\bsu-inventory-uninstall-*.log`. |

---

## 15. Moving from the XAMPP setup (setup.bat)

The installer does not take over a running XAMPP installation. To move an existing XAMPP server:

1. On the XAMPP server, make a dump. In a command prompt:
   `C:\xampp\mysql\bin\mysqldump -u root -p --single-transaction --routines --databases inventory_system > C:\temp\inventory.sql`
   (leave the password empty if root has none). Also copy
   `C:\xampp\htdocs\BSU_Inventory_System\backend\public\barcodes`.
2. Stop XAMPP's Apache and MySQL and set their services to *Manual* (Services app). They use
   ports 80, 443 and 3306.
3. Install `BSU-Inventory-Setup.exe`.
4. In a command prompt **as administrator**:

   ```
   cd /d C:\BSU-Inventory\laragon\bin\mysql\mariadb-10.11.19-winx64\bin
   mariadb --defaults-extra-file=C:\BSU-Inventory\config\secrets\db-root.cnf -e "DROP DATABASE inventory_system"
   mariadb --defaults-extra-file=C:\BSU-Inventory\config\secrets\db-root.cnf < C:\temp\inventory.sql
   ```

5. Copy the barcode images into `C:\BSU-Inventory\laragon\www\bsu-inventory\backend\public\barcodes`.
6. Server Manager → **Repair configuration** (runs the migrations), then sign in with an existing
   account from XAMPP. The administrator created in the installer was replaced by the imported
   users.

Devices that trusted the XAMPP server's CA must trust the new one (section 5).

---

## 16. Building the installer

On a developer PC with internet:

- Windows 10/11 x64
- Node.js **20.19+ or 22.12+** with npm (Vite 8)
- nothing else: the build downloads and checks its own PHP, Composer, Inno Setup and innoextract
  into `installer-source\.cache` (no system-wide installs)

```
powershell -ExecutionPolicy Bypass -File installer\installer-source\build.ps1
```

Output: `installer\BSU-Inventory-Setup.exe` and `BSU-Inventory-Setup.exe.sha256`. The version is
`frontend/package.json` → `version`; raise it for every release with `release.ps1` at the repository
root: `.\release.ps1 -Version 2.5.0 -Tag -Build` sets the version, commits and tags it, then runs this
build (main README, section 9). A first build takes 5–15 minutes
(downloads ~600 MB); later builds reuse the cache.

What `build.ps1` does:

1. Downloads every component in `components.json` and checks its **SHA-256**. A mismatch stops the
   build.
2. Unpacks Laragon 6.0 with innoextract (its installer is not run). Keeps the Laragon manager,
   HeidiSQL and the folder layout. Drops the old Apache/PHP/MySQL and the developer tools. Adds the
   pinned Apache, PHP and MariaDB to `bin\`.
3. Backend: copies `backend\` without `.env`, `vendor`, `tests`, writable data and barcode images.
   Runs `composer install --no-dev --optimize-autoloader` with the **bundled PHP**, then
   `composer check-platform-reqs --no-dev`.
4. Frontend: `npm ci` and `npm run build` in a copy (your `frontend\node_modules` is not touched).
   Only `dist\` is shipped.
5. Compiles the two launchers (`stub\Launcher.cs`) with the C# compiler that ships with Windows,
   and makes `bsu-inventory.ico` from `frontend\src\assets\images\bsu-logo.png`.
6. Compiles `BSU-Inventory.iss` with Inno Setup 6.

The **working tree** is packaged, including uncommitted changes. Build from a clean checkout of a
release tag for real releases. Keep the repository path short (for example
`C:\src\BSU_Inventory_System`): the staged vendor files must stay under Windows' 260-character path
limit.

**Updating a component:** edit `components.json` (url, file, folder, version and the
publisher's SHA-256 together) and test again (section 17). Microsoft's `vc_redist.x64.exe` link
always serves the newest runtime. When Microsoft updates it the checksum check fails: run
`build.ps1 -AcceptNewVcRedist`, which accepts the file only if it carries a valid Microsoft
signature.

### Source layout

```
installer/
  BSU-Inventory-Setup.exe            build output (not in Git)
  README.md                          this file
  installer-source/
    build.ps1                        the build
    components.json                  pinned downloads + SHA-256
    BSU-Inventory.iss                Inno Setup script: wizard, update, uninstall
    scripts/                         installed into <install>\manager
      common.ps1                     shared paths, logging (secrets masked), services, status
      checks.ps1                     wizard checks (ports, Laragon, IPs, disk)
      configure.ps1                  install / update / Repair configuration
      pre-update.ps1                 backup + stop before an update
      backup.ps1, restore.ps1        full backups
      uninstall.ps1                  uninstall choices
      BSU-Inventory.ps1              the BSU Inventory shortcut
      BSU-Inventory-Manager.ps1      the Server Manager
    templates/
      httpd.conf.template            Apache main configuration
      bsu-inventory-vhosts.conf.template   HTTP→HTTPS and the app (from deploy/apache)
      my.ini.template                MariaDB configuration
    stub/Launcher.cs, admin.manifest launcher programs
```

Backend changes made for the installer:

- `app/Database/Seeds/ReferenceDataSeeder.php`: the reference lists only (no accounts). The
  installer runs it. `DatabaseSeeder` calls it and then adds the demo accounts as before, so
  development is unchanged.
- `app/Commands/CreateAdmin.php`: `php spark user:create-admin [--from-file f.json] [--if-none]`.
- `app/Libraries/PasswordPolicy.php`: the password rules, now shared by the API and the command.

---

## 17. Test plan

Run before each release.

**Status for 2.2.0 (2026-10-12).** Already verified on the development PC (Windows 11, with XAMPP
running on 80/443/3306), using silent installs on ports 8095/8460:

- **Install:** a clean install creates both services under their virtual accounts. MariaDB picked
  the free port 3308 by itself. HTTPS works on `localhost` and the LAN IP, and login works with
  the chosen admin. Only that admin exists, with no demo accounts and no inventory.
- **Permissions and firewall:** the ACLs on the install folder, `.env`, secrets, data and backups
  are as documented. The firewall rule covers only the two ports, only `httpd.exe`, only on
  private/domain networks, only from LAN ranges.
- **Services:** a signed-in user can start the services but not stop them. Writes through Apache
  work (sessions, sign-up).
- **Backups:** the scheduled backup task runs as SYSTEM. Backup → change → restore returns the
  data, with a safety backup made first.
- **Repair and update:** Repair is idempotent (`.env` stays byte-identical). An update keeps data
  and `.env` and makes a `pre-update` backup. A failed first install recovers on the next run.
- **Uninstall:** `/PURGE=1` makes a backup first, then removes the services, firewall rule, task
  and our CA, and leaves no files. Another BSU CA (the XAMPP setup's) was left alone.
- **Secrets:** none of the passwords, the root password or the encryption key appears in any log.
- **Status window:** the Server Manager window builds and refreshes.

**Still to do on real machines:** the interactive wizard pages (only the silent path ran), a clean
VM without XAMPP or a VC++ runtime (test 1), a second PC and a phone on the LAN (tests 4–5),
restart without logging in (6), offline (7), a port conflict in the wizard (2), an existing
Laragon (3), the interactive uninstall dialog (13–14), and an update from an older version (11).

| # | Test | Steps | Expected |
|---|---|---|---|
| 1 | Clean install | Fresh Windows 10/11 VM (no XAMPP/Laragon/VC++), offline. Run setup with defaults. | Wizard checks all ✓; configure succeeds; shortcut opens `https://localhost/`; login with the chosen admin works; Server Manager all ✔. |
| 2 | Port conflict | Start IIS or XAMPP on 80, run setup. | Checks page shows ✗ with the program name; Next blocked; Back + ports 8080/8443 works. |
| 3 | Existing Laragon | Install Laragon at `C:\laragon` (stopped), run setup. | Reported, not changed; its files and `laragon.ini` unchanged after install and uninstall. |
| 4 | LAN | Second PC on the same switch: trust the CA, open `https://<ip>/`. | Login, list inventory, add/edit a product, stock in/out, borrow/return, a stock-out request approved by a custodian from another PC; data is the same on both. Staff (level 1) cannot open Office Settings. |
| 5 | Phone | Android/iPhone: trust the CA, scan a batch barcode with the camera. | Camera opens; batch found. |
| 6 | Restart | Restart the server; **do not log in**. From the client, refresh. | Available within ~1 minute of boot. |
| 7 | Offline | Unplug the internet uplink, keep the LAN. Repeat test 4. | Everything works; "Forgot password" says the mail server can't be reached. |
| 8 | Database not on LAN | From the client: `Test-NetConnection <ip> -Port 3306`. | Fails. `https://<ip>/.env`, `/backend/.env`, `/api/../.env` → 403/404. |
| 9 | Backups | Server Manager → Back up now; set the daily time 2 minutes ahead (Repair after editing `install.json`) and wait. | Zips in `<backup>\full`; manifest checksums valid; scheduled one rotates after 30. |
| 10 | Restore | Add a product, restore the earlier backup. | Product gone; pre-restore zip exists; login works. |
| 11 | Update | Install version N, add data/users/uploads, build N+1 (raise the version), install it. | Pre-update zip; data, users, roles, transactions, barcodes, `.env`, ports kept; migrations ran; LAN works. |
| 12 | IP change | Change the server's IP, Server Manager → Repair. | New certificate; clients using the new IP work without re-trusting. |
| 13 | Uninstall, keep data | Uninstall with defaults. | Services, firewall rule, task, shortcuts gone; `laragon\data`, `.env`, `config` remain; reinstall continues with the old data. |
| 14 | Uninstall, delete DB with backup | Tick database → Create Backup. | Backup zip written first; data removed; other Laragon untouched. |

---

## 18. Reference: files, services, logs

| Path (default install folder `C:\BSU-Inventory`) | |
|---|---|
| `laragon\` | BSU's Laragon copy (`laragon.exe`, `bin\apache`, `bin\php`, `bin\mysql`, `bin\heidisql`) |
| `laragon\www\bsu-inventory\` | the application (`backend\`, `frontend\dist\`, `deploy\db-tool.php`) |
| `laragon\www\bsu-inventory\backend\.env` | production settings (Administrators + web service only) |
| `laragon\data\bsu-inventory-db\` | the database files and `my.ini` |
| `laragon\etc\bsu-inventory\` | generated `httpd.conf`, `bsu-inventory-vhosts.conf`, `certs\` |
| `manager\` | Server Manager, shortcut launcher, scripts, templates |
| `config\install.json` | ports, LAN access, backup folder/time, component versions (no secrets) |
| `config\secrets\` | MariaDB root login (Administrators only) |
| `config\connection-info.txt` | addresses and CA fingerprint for users |
| `logs\` | `setup-*`, `configure-*`, `update-*`, `restore-*`, `backup.log`, `apache\`, `php\`, `mariadb\` |

| Windows service | Account | Starts |
|---|---|---|
| `BSUInventoryDB` — BSU Inventory - Database (MariaDB) | `NT SERVICE\BSUInventoryDB` | automatically with Windows |
| `BSUInventoryWeb` — BSU Inventory - Web server (Apache) | `NT SERVICE\BSUInventoryWeb` | automatically, after the database |

Scheduled task: **BSU Inventory Daily Backup** (SYSTEM). Firewall rule: **BSU Inventory (web)**.
Trusted root certificate: **BSU Inventory Local CA (<PC name>)**.
