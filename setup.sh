#!/usr/bin/env bash
# ==============================================================================
# BSU Integrated Inventory Monitoring System - Linux/macOS/WSL Setup & Launcher
# ==============================================================================

set -e

# Terminal Colors
COLOR_RESET="\033[0m"
COLOR_GREEN="\033[1;32m"
COLOR_YELLOW="\033[1;33m"
COLOR_RED="\033[1;31m"
COLOR_CYAN="\033[1;36m"
COLOR_MAGENTA="\033[1;35m"

print_header() {
    echo -e "\n${COLOR_CYAN}======================================================================${COLOR_RESET}"
    echo -e "  $1"
    echo -e "${COLOR_CYAN}======================================================================${COLOR_RESET}\n"
}

print_success() { echo -e "  ${COLOR_GREEN}[✓]${COLOR_RESET} $1"; }
print_warning() { echo -e "  ${COLOR_YELLOW}[!]${COLOR_RESET} $1"; }
print_error()   { echo -e "  ${COLOR_RED}[✗]${COLOR_RESET} $1"; }
print_info()    { echo -e "  ${COLOR_CYAN}[i]${COLOR_RESET} $1"; }

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$ROOT_DIR/backend"
FRONTEND_DIR="$ROOT_DIR/frontend"

BACKEND_PID=""
FRONTEND_PID=""

cleanup() {
    echo -e "\n${COLOR_CYAN}[i] Shutting down development servers...${COLOR_RESET}"
    if [ -n "$BACKEND_PID" ] && kill -0 "$BACKEND_PID" 2>/dev/null; then
        kill "$BACKEND_PID" 2>/dev/null || true
        print_success "Backend server stopped."
    fi
    if [ -n "$FRONTEND_PID" ] && kill -0 "$FRONTEND_PID" 2>/dev/null; then
        kill "$FRONTEND_PID" 2>/dev/null || true
        print_success "Frontend server stopped."
    fi
    exit 0
}

trap cleanup INT TERM

echo -e "${COLOR_GREEN}"
echo "  ╔════════════════════════════════════════════════════════════════════╗"
echo "  ║          BSU INTEGRATED INVENTORY MONITORING SYSTEM                ║"
echo "  ║             Automated Stack Setup & Server Launcher                ║"
echo "  ╚════════════════════════════════════════════════════════════════════╝"
echo -e "${COLOR_RESET}"

# ==============================================================================
# PHASE 1: ENVIRONMENT & DEPENDENCY CHECKS
# ==============================================================================
print_header "PHASE 1: ENVIRONMENT & DEPENDENCY CHECKS"

HAS_FATAL=0

# 1.1 Check Node.js and npm
if command -v node >/dev/null 2>&1; then
    NODE_VER=$(node -v)
    print_success "Node.js detected: $NODE_VER"
else
    print_error "Node.js is not installed. Download at: https://nodejs.org/"
    HAS_FATAL=1
fi

if command -v npm >/dev/null 2>&1; then
    NPM_VER=$(npm -v)
    print_success "npm detected: v$NPM_VER"
else
    print_error "npm is not installed."
    HAS_FATAL=1
fi

# 1.2 Check Vue / Vite Stack
if command -v vue >/dev/null 2>&1; then
    VUE_VER=$(vue --version 2>&1 | head -n 1)
    print_success "Vue CLI detected globally: $VUE_VER"
fi

if [ -f "$FRONTEND_DIR/package.json" ]; then
    print_success "Frontend Stack detected: Vue 3 Single Page Application with Vite"
fi

# 1.3 Check PHP
if command -v php >/dev/null 2>&1; then
    PHP_VER=$(php -r "echo PHP_VERSION;")
    print_success "PHP detected: $PHP_VER"
    
    # Check extensions
    for ext in mysqli curl intl mbstring openssl json; do
        if [ "$(php -r "echo extension_loaded('$ext') ? '1' : '0';")" != "1" ]; then
            print_warning "Missing PHP extension: $ext"
        fi
    done
else
    print_error "PHP is not installed. CodeIgniter 4 requires PHP 8.1+ (8.2+ recommended)."
    HAS_FATAL=1
fi

# 1.4 Check Composer
if command -v composer >/dev/null 2>&1; then
    COMPOSER_VER=$(composer --version 2>&1 | head -n 1)
    print_success "Composer detected: $COMPOSER_VER"
else
    print_error "Composer is not installed. Download at: https://getcomposer.org/download/"
    HAS_FATAL=1
fi

# 1.5 Check MySQL / MariaDB
MYSQL_RUNNING=0
if nc -z 127.0.0.1 3306 2>/dev/null || (echo > /dev/tcp/127.0.0.1/3306) 2>/dev/null; then
    MYSQL_RUNNING=1
    print_success "MySQL service is RUNNING on port 3306."
else
    print_warning "MySQL is NOT running on port 3306."
fi

if [ "$HAS_FATAL" -eq 1 ]; then
    echo -e "\n${COLOR_RED}[✗] Please install missing dependencies and re-run this script.${COLOR_RESET}\n"
    exit 1
fi

# ==============================================================================
# PHASE 2: AUTOMATED SETUP & DEPENDENCY INSTALLATION
# ==============================================================================
print_header "PHASE 2: AUTOMATED SETUP & DEPENDENCY INSTALLATION"

# 2.1 Attempt to start MySQL if stopped
if [ "$MYSQL_RUNNING" -eq 0 ]; then
    print_info "Attempting to start MySQL service..."
    if command -v systemctl >/dev/null 2>&1; then
        sudo systemctl start mysql 2>/dev/null || sudo systemctl start mariadb 2>/dev/null || true
    elif command -v service >/dev/null 2>&1; then
        sudo service mysql start 2>/dev/null || true
    fi
    
    sleep 2
    if nc -z 127.0.0.1 3306 2>/dev/null || (echo > /dev/tcp/127.0.0.1/3306) 2>/dev/null; then
        MYSQL_RUNNING=1
        print_success "MySQL is now active and listening on port 3306."
    else
        print_warning "Could not start MySQL automatically. Ensure MySQL is running on port 3306."
    fi
fi

# 2.2 Backend Environment (.env)
if [ ! -f "$BACKEND_DIR/.env" ]; then
    print_info "Creating backend/.env from docker/backend.env..."
    if [ -f "$ROOT_DIR/docker/backend.env" ]; then
        cp "$ROOT_DIR/docker/backend.env" "$BACKEND_DIR/.env"
    fi
    print_success "backend/.env created."
else
    print_success "backend/.env already exists."
fi

# 2.3 Backend Dependencies (composer install)
if [ ! -f "$BACKEND_DIR/vendor/autoload.php" ]; then
    print_warning "Backend dependencies missing. Running 'composer install'..."
    (cd "$BACKEND_DIR" && composer install --no-interaction --no-dev)
    print_success "Backend dependencies installed."
else
    print_success "Backend dependencies (vendor) already installed."
fi

# 2.4 Encryption Key
if ! grep -q "^encryption.key" "$BACKEND_DIR/.env" 2>/dev/null; then
    print_info "Generating application encryption key..."
    (cd "$BACKEND_DIR" && php spark key:generate --force)
    print_success "Encryption key generated."
fi

# 2.5 Ensure Writable Folders
mkdir -p "$BACKEND_DIR/writable/cache" "$BACKEND_DIR/writable/logs" \
         "$BACKEND_DIR/writable/session" "$BACKEND_DIR/writable/uploads" \
         "$BACKEND_DIR/writable/backups" "$BACKEND_DIR/public/barcodes"
print_success "Backend storage directories verified."

# 2.6 Database Migrations
if [ "$MYSQL_RUNNING" -eq 1 ]; then
    php -r "
    \$m = @new mysqli('127.0.0.1', 'root', '');
    if (!\$m->connect_errno) {
        \$m->query('CREATE DATABASE IF NOT EXISTS \`inventory_system\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }
    " 2>/dev/null || true

    (cd "$BACKEND_DIR" && php spark migrate)
    print_success "Database migrations up to date."

    USER_COUNT=$(php -r "
    \$m = @new mysqli('127.0.0.1', 'root', '', 'inventory_system');
    if (!\$m->connect_errno) {
        \$r = \$m->query('SELECT COUNT(*) FROM user_table');
        echo \$r ? \$r->fetch_row()[0] : '0';
    } else { echo '0'; }
    " 2>/dev/null || echo "0")

    if [ "$USER_COUNT" = "0" ] || [ -z "$USER_COUNT" ]; then
        print_info "Database empty. Seeding starting data..."
        (cd "$BACKEND_DIR" && php spark db:seed DatabaseSeeder)
        print_success "Database seeded."
    fi
fi

# 2.7 Frontend Dependencies (npm install)
if [ ! -f "$FRONTEND_DIR/.env.local" ]; then
    echo "VITE_DEV_API_TARGET=http://127.0.0.1:8080" > "$FRONTEND_DIR/.env.local"
    print_success "frontend/.env.local created."
fi

if [ ! -d "$FRONTEND_DIR/node_modules" ]; then
    print_warning "Frontend dependencies missing. Running 'npm install'..."
    (cd "$FRONTEND_DIR" && npm install)
    print_success "Frontend dependencies installed."
else
    print_success "Frontend dependencies (node_modules) already installed."
fi

# ==============================================================================
# PHASE 3: SERVER EXECUTION & BROWSER LAUNCH
# ==============================================================================
print_header "PHASE 3: SERVER EXECUTION & BROWSER LAUNCH"

print_info "Starting CodeIgniter backend dev server on 0.0.0.0:8080..."
(cd "$BACKEND_DIR" && php spark serve --host 0.0.0.0 --port 8080 > /dev/null 2>&1) &
BACKEND_PID=$!
print_success "Backend server started (PID: $BACKEND_PID)."

print_info "Starting Vue Vite frontend dev server on port 5173..."
(cd "$FRONTEND_DIR" && npm run dev -- --host 0.0.0.0 > /dev/null 2>&1) &
FRONTEND_PID=$!
print_success "Frontend server started (PID: $FRONTEND_PID)."

# Wait for servers
print_info "Waiting for servers to become live..."
RETRIES=15
BACKEND_READY=0
FRONTEND_READY=0

check_port() {
    local port=$1
    if command -v ss >/dev/null 2>&1 && ss -tulpn 2>/dev/null | grep -q ":$port "; then return 0; fi
    if command -v lsof >/dev/null 2>&1 && lsof -i ":$port" >/dev/null 2>&1; then return 0; fi
    if nc -z 127.0.0.1 "$port" 2>/dev/null || (echo > /dev/tcp/127.0.0.1/"$port") 2>/dev/null; then return 0; fi
    if nc -z "::1" "$port" 2>/dev/null; then return 0; fi
    return 1
}

while [ "$RETRIES" -gt 0 ] && ([ "$BACKEND_READY" -eq 0 ] || [ "$FRONTEND_READY" -eq 0 ]); do
    if [ "$BACKEND_READY" -eq 0 ] && check_port 8080; then
        BACKEND_READY=1
    fi
    if [ "$FRONTEND_READY" -eq 0 ] && check_port 5173; then
        FRONTEND_READY=1
    fi
    if [ "$BACKEND_READY" -eq 1 ] && [ "$FRONTEND_READY" -eq 1 ]; then
        break
    fi
    sleep 1
    RETRIES=$((RETRIES - 1))
    echo -n "."
done
echo ""

print_success "Backend API: http://localhost:8080/api"
print_success "Frontend App: http://localhost:5173"

# Open browser
if command -v xdg-open >/dev/null 2>&1; then
    xdg-open "http://localhost:5173" >/dev/null 2>&1 || true
elif command -v open >/dev/null 2>&1; then
    open "http://localhost:5173" >/dev/null 2>&1 || true
fi

echo -e "\n${COLOR_GREEN}Application stack is running. Press Ctrl+C to stop both servers.${COLOR_RESET}\n"
wait
