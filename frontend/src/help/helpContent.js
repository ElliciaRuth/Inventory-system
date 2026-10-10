// Content of the Help & User Guide page (views/HelpView.vue).
// Each topic lists the access levels it is shown to (1 Staff, 2 Custodian, 3 Manager,
// 4 Technical Staff), so every user only sees what applies to them. Keep `to` in step
// with the router: the "Open page" button is only shown when the route exists.
import {
  LogIn,
  Compass,
  UserCog,
  Inbox,
  ClipboardList,
  History,
  Package,
  LayoutDashboard,
  Boxes,
  Upload,
  Handshake,
  ClipboardCheck,
  Barcode,
  BarChart3,
  FileSpreadsheet,
  DatabaseBackup,
  Settings,
  ShieldCheck,
  Users,
  Bell,
  KeyRound,
} from 'lucide-vue-next'

export const ROLE_INTROS = {
  1: {
    role: 'Staff',
    text: 'You request the items your work needs. Add items to your stock-out list, submit it, and your custodian or manager accepts or rejects each one. You can also browse the item catalog and see the transaction log.',
  },
  2: {
    role: 'Custodian',
    text: 'You keep the stock records of your office: record stock coming in and going out, handle staff requests, lend items, run physical counts, manage products and barcodes, and produce reports.',
  },
  3: {
    role: 'Manager',
    text: 'You can do everything a custodian does, and you also run your office: approve new accounts, maintain the lists the system uses (entities, units, references, product types, offices), make and restore backups, and review the audit log.',
  },
  4: {
    role: 'Technical Staff',
    text: 'You look after accounts and offices across the whole system: activate and deactivate users, manage user offices, and review the audit log of every office. Inventory pages are not shown to your account.',
  },
}

export const CATEGORIES = [
  { key: 'start', label: 'Getting Started' },
  { key: 'requests', label: 'Stock-Out Requests' },
  { key: 'inventory', label: 'Inventory & Stock' },
  { key: 'products', label: 'Products & Barcodes' },
  { key: 'reports', label: 'Reports' },
  { key: 'admin', label: 'Administration' },
]

const ALL = [1, 2, 3, 4]
const INVENTORY = [1, 2, 3]
const STOCK = [2, 3]

export const TOPICS = [
  // ── Getting started ────────────────────────────────────────────────────
  {
    id: 'sign-in',
    category: 'start',
    levels: ALL,
    icon: LogIn,
    title: 'Signing in and staying signed in',
    summary: 'Logging in, your first password, and why you might be signed out.',
    steps: [
      'Sign in with your username or your email address and your password.',
      'On your first login (or after a manager set a temporary password for you), the system asks you to choose your own password before anything else.',
      'Passwords need at least 8 characters with an uppercase letter, a lowercase letter and a number, and no runs of numbers like 123 or 654.',
      'You are signed out after 15 minutes without activity, after 24 hours in any case, and on every other device when your password is changed.',
    ],
    tips: [
      'After 5 wrong passwords, logins to that account from your device pause for 15 minutes. You can still sign in from another device.',
      'Always use Sign Out (Menu → your account card) on a shared computer.',
    ],
  },
  {
    id: 'forgot-password',
    category: 'start',
    levels: ALL,
    icon: KeyRound,
    title: 'Forgot your password',
    summary: 'Reset it with a code sent to your own email account.',
    steps: [
      'On the sign-in page, click "Forgot Password?".',
      'Enter the email address on your account and an app password of that email account. The code is sent from your own email account to itself.',
      'Enter the 6-digit code from the email (it expires in 15 minutes), then choose a new password.',
    ],
    tips: [
      'To get a Gmail or BSU (Google) app password: Google Account → Security → 2-Step Verification → App passwords. You can delete it afterwards.',
      'No email on your account, or no internet? Ask your office manager to set a temporary password for you.',
    ],
  },
  {
    id: 'navigation',
    category: 'start',
    levels: ALL,
    icon: Compass,
    title: 'Finding your way around',
    summary: 'The menu, notifications, themes and this help page.',
    steps: [
      'Click "Menu" (top right) to see every page you can use, grouped by task.',
      'The bell shows notifications in three tabs: Requests (stock-out requests, decisions and new applicants), Expiry (batches expiring or expired) and Stock Alerts (low or out of stock, borrowed items).',
      'The theme button switches between the BSU, Dark and Light colours.',
      'The question-mark button opens this guide from any page (on a phone: Menu → Help & User Guide).',
    ],
    to: '/notifications',
    linkLabel: 'Open notifications',
  },
  {
    id: 'profile',
    category: 'start',
    levels: ALL,
    icon: UserCog,
    title: 'Your profile, password and email',
    summary: 'Change your name, username, password or email address.',
    steps: [
      'Click your name in the top bar to open My Profile.',
      'Profile Details: edit your full name and username, then click Save Profile Changes.',
      'Password & Security: enter your current password and the new one twice. Your other devices are signed out.',
      'To change your email, click "Change email", enter the new address, its app password and your current password, then type the 6-digit code sent to the new address.',
    ],
    tips: ['Your email is always shown partly hidden (for example ju*****z@bsu.edu.ph) so it can\'t be read off your screen.'],
    to: '/profile',
  },
  {
    id: 'dashboard',
    category: 'start',
    levels: INVENTORY,
    icon: LayoutDashboard,
    title: 'Dashboard and transaction log',
    summary: 'Stock health at a glance, and every movement in one list.',
    steps: [
      'The Dashboard shows summary cards (low stock, out of stock, expiring, and more). Click a card to list those items.',
      'Transactions (Menu → Overview) lists every receipt, issue, return, loan and adjustment. Filter by type or date to find one.',
    ],
    to: '/',
    linkLabel: 'Open dashboard',
  },

  // ── Stock-out requests ─────────────────────────────────────────────────
  {
    id: 'request-items',
    category: 'requests',
    levels: [1],
    icon: Inbox,
    title: 'Request items (stock out)',
    summary: 'Pick the items and quantities you need.',
    steps: [
      'Open Menu → Request Stock Out.',
      'Search for a product by name, or scan the barcode on its batch label.',
      'Enter the quantity and add it to your list. Repeat for every item you need.',
    ],
    tips: ['Nothing is sent yet: items wait in your list until you submit them.'],
    to: '/stockout',
  },
  {
    id: 'submit-list',
    category: 'requests',
    levels: [1],
    icon: ClipboardList,
    title: 'Review and submit your list',
    summary: 'Check quantities, then send the request for approval.',
    steps: [
      'Open Menu → My Requests List.',
      'Change a quantity or remove an item if needed, or click "+ Add More Items".',
      'Click Submit. Your custodian or manager is notified.',
    ],
    to: '/stockout/list',
  },
  {
    id: 'request-history',
    category: 'requests',
    levels: INVENTORY,
    icon: History,
    title: 'Request history',
    summary: 'What was accepted or rejected, and why.',
    steps: [
      'Open Menu → Request History.',
      'Staff see their own requests; custodians and managers see all requests of their office.',
      'Each item shows whether it was accepted (and the quantity given) or rejected with the reason.',
    ],
    tips: ['Staff also get a notification under the bell\'s Requests tab when a decision is made.'],
    to: '/stockout/history',
  },
  {
    id: 'pending-requests',
    category: 'requests',
    levels: STOCK,
    icon: Inbox,
    title: 'Accept or reject staff requests',
    summary: 'Decide on each requested item.',
    steps: [
      'Open Menu → Pending Requests (the number shows how many are waiting).',
      'Adjust a quantity if you can only give part of it.',
      'Accept or reject each item, or accept the whole request at once. A rejection needs a reason, which the staff member sees.',
      'Accepted items are issued from stock automatically, oldest received batch first (first in, first out).',
    ],
    to: '/stockout/pending',
  },

  // ── Inventory & stock ──────────────────────────────────────────────────
  {
    id: 'record-stock',
    category: 'inventory',
    levels: STOCK,
    icon: Boxes,
    title: 'Record stock in and out',
    summary: 'Receipts, issues, adjustments and loans on the stock card.',
    steps: [
      'Open Menu → Stockcard Ledger and choose the product.',
      'Click "Record Stock In / Out" and pick the movement:',
      'Stock In (Receipt) for deliveries and purchases, with the batch, cost and expiry date.',
      'Stock Out (Issue) for items released for use.',
      'Adjust Out for spoiled, spilled or expired stock (choose the reason); Adjust In for a count correction.',
      'Lend to Another Unit for borrowed items (see "Lend and track borrowed items").',
    ],
    tips: [
      'To fix a mistake, use the edit button on the ledger row. Every edit and deletion is kept in the audit log with the before and after values.',
    ],
    to: '/stockcard',
  },
  {
    id: 'import-excel',
    category: 'inventory',
    levels: STOCK,
    icon: Upload,
    title: 'Import stock cards from Excel',
    summary: 'Bring in existing Appendix 58 stock cards.',
    steps: [
      'Open Menu → Stockcard Ledger and click "Import from Excel".',
      'Choose the Excel file. The system reads every stock card and shows a preview.',
      'Check each card, correct anything that was read wrongly, then import.',
    ],
    tips: ['Replacing the existing inventory of your office before importing needs a manager\'s password.'],
    to: '/stockcard',
  },
  {
    id: 'borrows',
    category: 'inventory',
    levels: STOCK,
    icon: Handshake,
    title: 'Lend and track borrowed items',
    summary: 'Who borrowed what, and what is still out.',
    steps: [
      'To lend: Stockcard Ledger → Record Stock In / Out → "Lend to Another Unit". Enter who borrowed it and from which unit.',
      'Open Menu → Borrowed Items to see what is still out and how much has come back.',
      'When items come back, record the return from that page. It goes back into stock as a stock-in.',
    ],
    to: '/stock/borrows',
  },
  {
    id: 'physical-count',
    category: 'inventory',
    levels: STOCK,
    icon: ClipboardCheck,
    title: 'Physical count',
    summary: 'Compare the shelf with the records and fix the difference.',
    steps: [
      'Count what is actually on the shelf.',
      'Open Menu → Physical Count and enter the counted quantity of each product.',
      'The system shows the difference. Reconcile to record an Adjust Out for shortages (expired batches first) or an Adjust In for overages, with the reason "Physical Count".',
    ],
    to: '/stock/count',
  },

  // ── Products & barcodes ────────────────────────────────────────────────
  {
    id: 'catalog',
    category: 'products',
    levels: [1],
    icon: Package,
    title: 'Browse the item catalog',
    summary: 'See what items exist and how many are on hand.',
    steps: [
      'Open Menu → Item Catalog.',
      'Search or filter to find an item and see its stock on hand.',
    ],
    to: '/products',
  },
  {
    id: 'products',
    category: 'products',
    levels: STOCK,
    icon: Package,
    title: 'Add, edit and archive products',
    summary: 'Keep the product list of your office up to date.',
    steps: [
      'Open Menu → Product List.',
      'Click "+ Add New Product" and fill in the name, unit, product type, reorder point and expiry warning thresholds.',
      'Use the edit button to change a product.',
      'A product that has history can\'t be deleted: archive it instead (with an optional reason). The Archived tab lets you restore it.',
    ],
    tips: ['The reorder level decides when a product shows as low stock; the expiry threshold decides how early expiry warnings start.'],
    to: '/products',
  },
  {
    id: 'barcodes',
    category: 'products',
    levels: STOCK,
    icon: Barcode,
    title: 'Barcodes and labels',
    summary: 'Print labels for batches and finished products.',
    steps: [
      'Every batch gets its own barcode when stock is received. Open Menu → Batch Barcodes to download one and print a label.',
      'For finished products, open Menu → Finished Products to generate a barcode or download a saved one.',
      'Staff can scan these labels on the Request Stock Out page.',
    ],
    to: '/reports/batches',
  },

  // ── Reports ────────────────────────────────────────────────────────────
  {
    id: 'summary-report',
    category: 'reports',
    levels: STOCK,
    icon: BarChart3,
    title: 'Monthly summary report',
    summary: 'Beginning, purchases, usage, spoilage and ending balance.',
    steps: [
      'Open Menu → Summary Report and choose the month.',
      'To download it, click "Export Summary" and pick a range of months.',
    ],
    to: '/reports/summary',
  },
  {
    id: 'export-stockcard',
    category: 'reports',
    levels: STOCK,
    icon: FileSpreadsheet,
    title: 'Export an official stock card',
    summary: 'Appendix 58 stock card as PDF or Word.',
    steps: [
      'Open Menu → Export Stockcard.',
      'Choose the product and the period.',
      'Download a print-ready PDF or an editable Word (.doc) file.',
    ],
    to: '/export/stockcard',
  },

  // ── Administration ─────────────────────────────────────────────────────
  {
    id: 'others-management',
    category: 'admin',
    levels: [3],
    icon: Settings,
    title: 'Accounts and lists (Others Management)',
    summary: 'New applicants, user accounts and the lists the system uses.',
    steps: [
      'Open Menu → Others Management. New sign-ups also appear as "New applicant" under the bell.',
      'Users: activate a pending account, deactivate one that should no longer sign in, or set a temporary password (the user must change it at the next login).',
      'Entities, Units, References, Product Types and Offices: add, edit or remove the entries used in forms and reports.',
    ],
    to: '/settings',
  },
  {
    id: 'backups',
    category: 'admin',
    levels: [3],
    icon: DatabaseBackup,
    title: 'Backups and restore',
    summary: 'Keep a copy of your office\'s data, and bring it back if needed.',
    steps: [
      'Open Menu → Others Management → Data Backup & Restore.',
      'Back up: choose what to include and click Create backup. Turn on password protection for any copy that leaves this computer (USB drive, email); only protected backups keep users\' passwords.',
      'Schedule: set how often automatic backups run and where they are saved (a second drive is recommended). These settings apply to your office only.',
      'Restore: choose a saved backup or a file, check what it contains, pick the parts to restore and confirm. A safety backup of the current data is made first.',
    ],
    tips: [
      'Restoring never changes anyone\'s current password.',
      'Keep backup passwords somewhere safe: a protected backup can\'t be restored without its password.',
    ],
    to: '/settings',
  },
  {
    id: 'audit-log',
    category: 'admin',
    levels: [3, 4],
    icon: ShieldCheck,
    title: 'Audit log',
    summary: 'Who did what, and when.',
    steps: [
      'Open Menu → Audit Log (technical staff: the button on User Management).',
      'Every login, failed login, stock movement, correction, approval, backup and settings change is listed with the person and time.',
      'Filter by kind of action and dates, or search by user, item or IP address. Managers see their own office; technical staff see every office.',
    ],
    tips: ['The audit log can\'t be edited or deleted, by anyone.'],
    to: '/audit-log',
  },
  {
    id: 'user-management',
    category: 'admin',
    levels: [4],
    icon: Users,
    title: 'User management',
    summary: 'Accounts and user offices across the system.',
    steps: [
      'Open Menu → User Management.',
      'Activate pending accounts, or deactivate accounts that should no longer sign in. A deactivated user is signed out at once.',
      'Add or edit user offices.',
    ],
    tips: [
      'If a technical staff account itself is locked out, someone with access to the server can run: php spark user:reset-password <username>',
    ],
    to: '/admin',
  },
]

export const FAQS = [
  {
    levels: ALL,
    q: 'Why was I signed out?',
    a: 'After 15 minutes without activity, after 24 hours, when your password was changed (here or on another device), or when your account was deactivated. Sign in again to continue.',
  },
  {
    levels: ALL,
    q: 'It says my login is locked.',
    a: 'Too many wrong passwords were tried from your device. Wait 15 minutes, or sign in from another device. If you forgot the password, use "Forgot Password?".',
  },
  {
    levels: ALL,
    q: 'Why is my email shown with stars?',
    a: 'Email addresses are shown partly hidden for privacy. To use a different address, change it on My Profile; it is confirmed with a code sent to the new address.',
  },
  {
    levels: [1],
    q: 'My request was rejected. What now?',
    a: 'Open Request History to read the reason. You can submit a new request with a smaller quantity or a different item, or talk to your custodian.',
  },
  {
    levels: STOCK,
    q: 'I recorded a wrong quantity. Can I fix it?',
    a: 'Yes. On the Stockcard Ledger, use the edit button on that row to correct or delete it. The change is recorded in the audit log.',
  },
  {
    levels: STOCK,
    q: 'Why do I get expiry alerts?',
    a: 'Batches get an alert when they come within the product\'s expiry threshold, a reminder 3 days before, and another once expired. Use expiring batches first, or record expired ones with Adjust Out.',
  },
  {
    levels: [1, 2],
    q: 'Someone needs a new account or a password reset.',
    a: 'They can sign up on the sign-in page ("Create a new account"); your office manager then activates it. A manager can also set a temporary password for an existing account.',
  },
  {
    levels: [3, 4],
    q: 'A new user signed up. How do I let them in?',
    a: 'New sign-ups appear as "New applicant" under the bell. Activate the account in Others Management (managers) or User Management (technical staff).',
  },
]

/** Icon used for the FAQ heading */
export const FAQ_ICON = Bell
