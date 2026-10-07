(() => {

    // =========================
    // HELPERS
    // =========================

    function readJsonAttribute(element, attributeName = "data-page") {
        if (!element) return {};

        const payload = element.getAttribute(attributeName);
        if (!payload) return {};

        try {
            return JSON.parse(payload);
        } catch (error) {
            console.error(`Unable to parse ${attributeName}`, error);
            return {};
        }
    }

    function readJsonScript(id) {
        const element = document.getElementById(id);
        if (!element) return {};

        try {
            return JSON.parse(element.textContent ?? "{}");
        } catch (error) {
            console.error(`Unable to parse JSON script #${id}`, error);
            return {};
        }
    }

    function csrfHeaders() {
        const meta = document.querySelector(`meta[name="${window.appConfig?.csrfHeader ?? ""}"]`);
        const headers = {
            "X-Requested-With": "XMLHttpRequest",
        };

        if (meta && window.appConfig?.csrfHeader) {
            headers[window.appConfig.csrfHeader] = meta.content;
        }

        return headers;
    }

    const busyActions = new Set();

    function setBusyState(element, isBusy) {
        if (!element) return;

        element.classList.toggle("is-busy", isBusy);
        element.dataset.busy = isBusy ? "true" : "false";
        element.setAttribute("aria-busy", isBusy ? "true" : "false");

        if (element.matches("button, input[type='submit'], input[type='button']")) {
            element.disabled = isBusy;
        } else if (element.matches("a")) {
            if (isBusy) {
                element.setAttribute("aria-disabled", "true");
            } else {
                element.removeAttribute("aria-disabled");
            }
        }
    }

    async function runOnce(actionKey, trigger, task) {
        if (busyActions.has(actionKey)) return null;

        busyActions.add(actionKey);
        setBusyState(trigger, true);

        try {
            return await task();
        } finally {
            busyActions.delete(actionKey);
            setBusyState(trigger, false);
        }
    }

    function initThemeToggle() {
        const root = document.documentElement;
        const toggle = document.querySelector("[data-theme-toggle]");
        if (!toggle) return;
        const label = toggle.querySelector("[data-theme-label]");
        const themes = ["light", "dark", "bsu"];

        function applyTheme(theme) {
            const currentTheme = themes.includes(theme) ? theme : "light";
            const nextTheme = themes[(themes.indexOf(currentTheme) + 1) % themes.length];
            const themeNames = {
                light: "Light",
                dark: "Dark",
                bsu: "BSU",
            };
            const labelText = `${themeNames[currentTheme]} mode. Click for ${themeNames[nextTheme]} mode.`;

            root.dataset.theme = currentTheme;
            toggle.dataset.themeState = currentTheme;
            localStorage.setItem("inventoryTheme", currentTheme);
            toggle.setAttribute("aria-label", labelText);
            toggle.setAttribute("title", labelText);

            if (label) {
                label.textContent = labelText;
            }
        }

        if (root.dataset.theme === "rpg" || root.dataset.theme === "BSU") {
            root.dataset.theme = "bsu";
        }

        applyTheme(themes.includes(root.dataset.theme) ? root.dataset.theme : "light");

        toggle.addEventListener("click", () => {
            const currentIndex = themes.indexOf(root.dataset.theme);
            applyTheme(themes[(currentIndex + 1) % themes.length]);
        });
    }

    function bindSubmitGuards() {
        document.addEventListener("submit", (event) => {
            const form = event.target;
            if (!(form instanceof HTMLFormElement) || event.defaultPrevented) return;
            if (form.dataset.noSubmitGuard === "true") return;

            if (form.dataset.submitting === "true") {
                event.preventDefault();
                return;
            }

            if (typeof form.checkValidity === "function" && !form.checkValidity()) return;

            form.dataset.submitting = "true";
            form.querySelectorAll("button[type='submit'], input[type='submit']").forEach((button) => {
                setBusyState(button, true);
            });
        });

        document.addEventListener("click", (event) => {
            const trigger = event.target.closest("button, a.action-btn, a.btn-add, a.btn-primary, a.btn-secondary");
            if (!trigger) return;

            if (trigger.matches("[data-theme-toggle], [data-menu-toggle], [data-stockcard-toggle], [data-filter-open], [data-filter-close], .section-header, .close, .close-btn")) {
                return;
            }

            if (trigger.dataset.busy === "true" || trigger.getAttribute("aria-disabled") === "true") {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        }, true);
    }

    // =========================
    // TOAST SYSTEM
    // =========================
    function showToast(message, type = "success", duration = 3000) {
    const toast = document.createElement("div");
    toast.className = `flash-message flash-${type}`;

    // message text
    const text = document.createElement("span");
    text.textContent = message;

    // progress bar (timer visual)
    const progress = document.createElement("div");
    progress.className = "flash-progress";

    toast.appendChild(text);
    toast.appendChild(progress);

    document.body.appendChild(toast);

    // force reflow for animation
    setTimeout(() => {
        progress.style.width = "0%";
    }, 50);

    // auto remove
    setTimeout(() => {
        toast.style.opacity = "0";
        toast.style.transform = "scale(0.95)";

        setTimeout(() => {
            toast.remove();
        }, 200);
    }, duration);
}
    function toggleMenu() {
        const navLinks = document.querySelector(".nav-links");
        if (!navLinks) return;

        const isOpen = navLinks.classList.toggle("show");
        navLinks.classList.toggle("active", isOpen);
        document.body.classList.toggle("nav-open", isOpen);
        document.querySelector("[data-menu-toggle]")?.classList.toggle("is-active", isOpen);

        if (!isOpen) {
            document.querySelectorAll(".nav-links .has-submenu.active").forEach((item) => {
                item.classList.remove("active");
            });
        }
    }

    document.addEventListener("click", (e) => {
        if (!document.body.classList.contains("nav-open")) return;
        const nav = document.querySelector(".navbar");
        const toggle = document.querySelector("[data-menu-toggle]");
        if (nav && !nav.contains(e.target) && (!toggle || !toggle.contains(e.target))) {
            toggleMenu();
        }
    });

    function toggleMobileSubmenu(trigger) {
        const parent = trigger?.closest(".has-submenu");
        if (!parent) return;

        const isOpen = parent.classList.toggle("active");
        document.querySelectorAll(".nav-links .has-submenu").forEach((item) => {
            if (item !== parent) item.classList.remove("active");
        });

        const navLinks = document.querySelector(".nav-links");
        navLinks?.classList.add("show");
        navLinks?.classList.add("active");

        return isOpen;
    }

    // =========================
    // STOCK / GENERAL MODULES
    // =========================

    function bindItemRedirects() {
        document.querySelectorAll("[data-stock-item-select], [data-adjust-select]").forEach((select) => {
            select.addEventListener("change", () => {
                const itemId = select.value;
                const targetUrl =
                    select.getAttribute("data-target-url") ||
                    select.closest("[data-adjust-form]")?.getAttribute("data-target-url");

                if (!itemId || !targetUrl) return;

                window.location.href = `${targetUrl}?item_id=${encodeURIComponent(itemId)}`;
            });
        });
    }

    function bindAdjustSearch() {
        const searchInput = document.querySelector("[data-adjust-search]");
        const select = document.querySelector("[data-adjust-select]");
        if (!searchInput || !select) return;

        searchInput.addEventListener("input", () => {
            const query = searchInput.value.trim().toLowerCase();

            Array.from(select.options).forEach((option, index) => {
                if (index === 0) {
                    option.hidden = false;
                    return;
                }

                const label =
                    (option.getAttribute("data-label") ?? option.textContent).toLowerCase();

                option.hidden = query !== "" && !label.includes(query);
            });
        });
    }

    function bindStockcardTools() {
        const layout = document.querySelector("[data-stockcard-layout]");
        if (!layout) return;

        const sidebar = document.getElementById("itemSidebar");
        const backdrop = document.querySelector("[data-stockcard-backdrop]");
        const searchInput = document.querySelector("[data-stockcard-search]");
        const items = Array.from(document.querySelectorAll("[data-stockcard-item]"));

        function setSidebarState(isOpen) {
            sidebar?.classList.toggle("is-open", isOpen);
            backdrop?.classList.toggle("is-visible", isOpen);
            document.body.classList.toggle("stockcard-sidebar-open", isOpen);
        }

        document.querySelectorAll("[data-stockcard-toggle]").forEach((button) => {
            button.addEventListener("click", () => {
                setSidebarState(!sidebar?.classList.contains("is-open"));
            });
        });

        backdrop?.addEventListener("click", () => setSidebarState(false));

        searchInput?.addEventListener("input", () => {
            const query = searchInput.value.trim().toLowerCase();

            items.forEach((item) => {
                const label = item.getAttribute("data-label") ?? "";
                item.style.display = query === "" || label.includes(query) ? "" : "none";
            });
        });
    }

    function initDashboardDetailPanels() {
        const toggles = Array.from(document.querySelectorAll("[data-dashboard-toggle]"));
        const panels = Array.from(document.querySelectorAll("[data-dashboard-panel]"));
        if (!toggles.length || !panels.length) return;

        function setOpenPanel(targetKey) {
            toggles.forEach((toggle) => {
                const isActive = toggle.getAttribute("data-dashboard-toggle") === targetKey;
                toggle.classList.toggle("is-active", isActive);
                toggle.setAttribute("aria-expanded", isActive ? "true" : "false");
            });

            panels.forEach((panel) => {
                panel.hidden = panel.getAttribute("data-dashboard-panel") !== targetKey;
            });
        }

        function closePanels() {
            toggles.forEach((toggle) => {
                toggle.classList.remove("is-active");
                toggle.setAttribute("aria-expanded", "false");
            });

            panels.forEach((panel) => {
                panel.hidden = true;
            });
        }

        toggles.forEach((toggle) => {
            toggle.addEventListener("click", () => {
                if (toggle.disabled) return;

                const targetKey = toggle.getAttribute("data-dashboard-toggle");
                const isExpanded = toggle.getAttribute("aria-expanded") === "true";

                if (isExpanded) {
                    closePanels();
                    return;
                }

                setOpenPanel(targetKey);
            });
        });
    }

    // =========================
    // SETTINGS MODULE
    // =========================

    function initSettingsPage() {
        const page = document.getElementById("settingsPage");
        if (!page) return;

        const config = readJsonScript("settingsConfigJson");

        const titles = {
            users: "User",
            entity: "Entity",
            unit: "Unit",
            roles: "Role",
            reference: "Reference",
            item_type: "Item Type",
            item_category: "Category",
            office: "Office",
            user_office: "User Office",
        };

        const modal = document.getElementById("modal");
        const fields = document.getElementById("fields");
        const modalTitle = document.getElementById("modalTitle");
        const modalForm = document.getElementById("modalForm");
        const recordTypeInput = document.getElementById("recordType");
        const recordIdInput = document.getElementById("recordId");

        const savedSection = localStorage.getItem("activeSettingsSection");

        if (savedSection) {
            document.querySelectorAll(".settings-section-body").forEach(el => {
                el.classList.remove("is-open");
            });

            document.querySelectorAll(".settings-section-header").forEach(el => {
                el.classList.remove("active");
            });

            const section = document.getElementById(savedSection);
            const button = document.querySelector(`[onclick*="${savedSection}"]`);

            if (section) {
                section.classList.add("is-open");
                button?.classList.add("active");
            }
        }

        function createField(type, field, label, value = "") {
            const wrapper = document.createElement("div");
            wrapper.className = "settings-field";

            const fieldLabel = document.createElement("label");
            fieldLabel.setAttribute("for", `field-${field}`);
            fieldLabel.textContent = label;

            let input;

            if (type === "users" && field === "role") {
                input = document.createElement("select");

                (config.roles ?? []).forEach((role) => {
                    const option = document.createElement("option");
                    option.value = role.role_name;
                    option.textContent = role.role_name;
                    option.selected = role.role_name === String(value ?? "");
                    input.appendChild(option);
                });

            } else if (type === "users" && field === "user_office_id") {
                input = document.createElement("select");

                const placeholder = document.createElement("option");
                placeholder.value = "";
                placeholder.textContent = "Select user office";
                input.appendChild(placeholder);

                (config.userOffices ?? []).forEach((uo) => {
                    const option = document.createElement("option");
                    option.value = uo.user_office_id;
                    option.textContent = uo.user_office;
                    option.selected = String(uo.user_office_id) === String(value ?? "");
                    input.appendChild(option);
                });

            } else if (type === "roles" && field === "level_id") {
                input = document.createElement("select");

                const lvlPlaceholder = document.createElement("option");
                lvlPlaceholder.value = "";
                lvlPlaceholder.textContent = "Select Level of Access";
                input.appendChild(lvlPlaceholder);

                (config.levels ?? []).forEach((level) => {
                    const option = document.createElement("option");
                    option.value = level.level_id;
                    option.textContent = `Level ${level.level_id}`;
                    option.selected = String(level.level_id) === String(value ?? "");
                    input.appendChild(option);
                });

            } else {
                input = document.createElement("input");
                input.type = field === "password" ? "password" : (field === "email" ? "email" : "text");
                input.value = value ?? "";

                if (field === "password") {
                    input.placeholder = "Leave blank to keep current password";
                }
                if (field === "email") {
                    input.placeholder = "Enter email address";
                }
            }

            input.name = field;
            input.id = `field-${field}`;
            if (field !== "password") input.required = true;

            wrapper.append(fieldLabel, input);
            return wrapper;
        }

        function fillForm(type, row = {}) {
            const definition = config.definitions?.[type];
            if (!definition) return;

            fields.innerHTML = "";
            recordTypeInput.value = type;
            recordIdInput.value = row[definition.pk] ?? "";

            definition.fields.forEach((field) => {
                const label = definition.labels?.[field] ?? field;
                fields.appendChild(createField(type, field, label, row[field] ?? ""));
            });
        }

        async function openModal(type, id = null) {
            modalTitle.textContent = `${id ? "Edit" : "Add"} ${titles[type] ?? type}`;

            if (!id) {
                fillForm(type);
                modal.classList.add("is-open");
                return;
            }

            const response = await fetch(`${config.fetchBase}/${type}/${id}`, {
                headers: { "X-Requested-With": "XMLHttpRequest" },
            });

            const row = await response.json();
            fillForm(type, row);
            modal.classList.add("is-open");
        }

        function closeModal() {
            modal.classList.remove("is-open");
            modalForm.reset();
            fields.innerHTML = "";
        }

        async function deleteRecord(type, id) {
            if (!confirm("Delete this record?")) return;

            const trigger = document.activeElement instanceof HTMLElement ? document.activeElement : null;

            await runOnce(`settings-delete-${type}-${id}`, trigger, async () => {
                localStorage.setItem("activeSettingsSection", `section-${type}`);

                const response = await fetch(`${config.deleteBase}/${type}/${id}`, {
                    method: "POST",
                    headers: csrfHeaders(),
                });

                const data = await response.json();

                if (!response.ok) {
                    const msg = (data.message || "").toLowerCase();
                    let cleanMessage = "Delete failed";

                    if (msg.includes("foreign key") || msg.includes("constraint")) {
                        cleanMessage = "Delete failed: This item is currently in use and cannot be removed.";
                    } else if (msg.includes("not found")) {
                        cleanMessage = "Delete failed: Record not found.";
                    } else if (msg.includes("permission")) {
                        cleanMessage = "Delete failed: You do not have permission.";
                    } else if (msg) {
                        cleanMessage = data.message;
                    }

                    showToast(cleanMessage, "error");
                    return;
                }

                showToast("Deleted successfully", "success");
                window.location.reload();
            });
        }

        // â”€â”€ Activate / Deactivate users â”€â”€

        async function activateUser(id) {
            if (!confirm("Activate this user?")) return;

            const trigger = document.activeElement instanceof HTMLElement ? document.activeElement : null;

            await runOnce(`settings-activate-${id}`, trigger, async () => {
                const response = await fetch(`${config.activateBase}/${id}`, {
                    method: "POST",
                    headers: csrfHeaders(),
                });

                const data = await response.json();

                if (!response.ok) {
                    showToast(data.message ?? "Activation failed", "error");
                    return;
                }

                showToast("User activated successfully", "success");
                window.location.reload();
            });
        }

        async function deactivateUser(id) {
            if (!confirm("Deactivate this user?")) return;

            const trigger = document.activeElement instanceof HTMLElement ? document.activeElement : null;

            await runOnce(`settings-deactivate-${id}`, trigger, async () => {
                const response = await fetch(`${config.deactivateBase}/${id}`, {
                    method: "POST",
                    headers: csrfHeaders(),
                });

                const data = await response.json();

                if (!response.ok) {
                    showToast(data.message ?? "Deactivation failed", "error");
                    return;
                }

                showToast("User deactivated successfully", "success");
                window.location.reload();
            });
        }

        function filterTable(input, tableId) {
            const table = document.getElementById(tableId);
            if (!table) return;

            const query = input.value.toLowerCase();

            Array.from(table.querySelectorAll("tr")).slice(1).forEach((row) => {
                row.style.display =
                    row.textContent.toLowerCase().includes(query) ? "" : "none";
            });
        }

        function toggleSection(sectionId, button) {
            const section = document.getElementById(sectionId);
            if (!section) return;

            const isOpen = section.classList.toggle("is-open");
            button.classList.toggle("active", isOpen);

            if (isOpen) {
                localStorage.setItem("activeSettingsSection", sectionId);
            }
        }

        modalForm.addEventListener("submit", async (event) => {
            event.preventDefault();

            const type = recordTypeInput.value;
            const submitter = event.submitter ?? modalForm.querySelector("button[type='submit']");

            await runOnce(`settings-save-${type}`, submitter, async () => {
                localStorage.setItem("activeSettingsSection", `section-${type}`);

                const formData = new FormData(modalForm);

                const response = await fetch(`${config.saveBase}/${type}`, {
                    method: "POST",
                    headers: csrfHeaders(),
                    body: new URLSearchParams(formData),
                });

                const data = await response.json();

                if (!response.ok) {
                    showToast(data.message ?? "Save failed", "error");
                    return;
                }

                showToast("Saved successfully", "success");
                window.location.reload();
            });
        });

        window.toggleSection = toggleSection;
        window.openModal = openModal;
        window.closeModal = closeModal;
        window.deleteRecord = deleteRecord;
        window.searchTable = filterTable;
        window.activateUser = activateUser;
        window.deactivateUser = deactivateUser;

        modal.addEventListener("click", (event) => {
            if (event.target === modal) closeModal();
        });
    }

    // =========================
    // STOCKOUT APPROVAL MODULE
    // =========================

    function initStockoutApproval() {
        // Only init if on the pending page
        if (!document.querySelector('.pending-request-card')) return;

        window.approveStockoutItem = async function(itemId) {
            if (!confirm("Approve this item? Stock will be deducted.")) return;

            const trigger = document.activeElement instanceof HTMLElement ? document.activeElement : null;

            await runOnce(`stockout-approve-${itemId}`, trigger, async () => {
                const response = await fetch(`${window.appConfig.baseUrl}stockout/approve-item/${itemId}`, {
                    method: "POST",
                    headers: csrfHeaders(),
                });

                const data = await response.json();

                if (!response.ok) {
                    showToast(data.message ?? "Approval failed", "error");
                    return;
                }

                showToast("Item approved", "success");
                window.location.reload();
            });
        };

        window.rejectStockoutItem = async function(itemId) {
            if (!confirm("Reject this item?")) return;

            const trigger = document.activeElement instanceof HTMLElement ? document.activeElement : null;

            await runOnce(`stockout-reject-${itemId}`, trigger, async () => {
                const response = await fetch(`${window.appConfig.baseUrl}stockout/reject-item/${itemId}`, {
                    method: "POST",
                    headers: csrfHeaders(),
                });

                const data = await response.json();

                if (!response.ok) {
                    showToast(data.message ?? "Rejection failed", "error");
                    return;
                }

                showToast("Item rejected", "success");
                window.location.reload();
            });
        };

        window.approveAllRequest = async function(requestId) {
            if (!confirm("Approve ALL items in this request? Stock will be deducted for each.")) return;

            const trigger = document.activeElement instanceof HTMLElement ? document.activeElement : null;

            await runOnce(`stockout-approve-all-${requestId}`, trigger, async () => {
                const response = await fetch(`${window.appConfig.baseUrl}stockout/approve-all/${requestId}`, {
                    method: "POST",
                    headers: csrfHeaders(),
                });

                const data = await response.json();

                if (!response.ok) {
                    showToast(data.message ?? "Approval failed", "error");
                    return;
                }

                showToast("All items approved", "success");
                window.location.reload();
            });
        };
    }

    // =========================
    // HOVER DROPDOWN MODULE
    // =========================

    function initHoverDropdowns() {
        document.querySelectorAll('.hover-dropdown').forEach(container => {
            const input = container.querySelector('.hover-input');
            const options = container.querySelectorAll('.hover-option');
            const dropdownContent = container.querySelector('.hover-dropdown-content');

            if (!input || !dropdownContent) return;

            input.addEventListener('input', () => {
                const query = input.value.trim().toLowerCase();
                options.forEach(option => {
                    const text = (option.textContent || '').toLowerCase();
                    if (query === '' || text.includes(query)) {
                        option.classList.remove('hidden');
                    } else {
                        option.classList.add('hidden');
                    }
                });
            });

            input.addEventListener('focus', () => {
                dropdownContent.classList.add('force-show');
            });

            input.addEventListener('blur', () => {
                // Only hide if mouse is not hovering over the dropdown content
                if (!dropdownContent.matches(':hover')) {
                    setTimeout(() => {
                        if (!dropdownContent.matches(':hover')) {
                            dropdownContent.classList.remove('force-show');
                        }
                    }, 150);
                }
            });

            // Keep dropdown open while hovering over it
            dropdownContent.addEventListener('mouseenter', () => {
                dropdownContent.classList.add('force-show');
            });

            dropdownContent.addEventListener('mouseleave', () => {
                // Only hide if input is also not focused
                if (document.activeElement !== input) {
                    dropdownContent.classList.remove('force-show');
                }
            });

            options.forEach(option => {
                // Use mousedown + preventDefault to prevent the input from losing focus
                option.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    input.value = option.textContent.trim();
                    dropdownContent.classList.remove('force-show');
                    input.blur();
                });
            });
        });
    }

    // =========================
    // PRODUCT DELETE MODULE
    // =========================

    function initProductDelete() {
        document.addEventListener('click', async (e) => {
            const btn = e.target.closest('[data-delete-url]');
            if (!btn) return;

            const url = btn.dataset.deleteUrl;
            const name = btn.dataset.productName || 'this product';

            if (!confirm(`Delete "${name}"?\n\nThis cannot be undone. Products with existing stock or transactions cannot be deleted.`)) return;

            await runOnce(`product-delete-${url}`, btn, async () => {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: csrfHeaders(),
                });

                const data = await response.json();

                if (!response.ok) {
                    showToast(data.message ?? 'Delete failed', 'error');
                    return;
                }

                showToast(data.message ?? 'Deleted successfully', 'success');
                // Remove the row from the table
                const row = btn.closest('tr');
                if (row) {
                    row.style.transition = 'opacity 0.3s';
                    row.style.opacity = '0';
                    setTimeout(() => row.remove(), 300);
                }
            });
        });
    }

    // =========================
    // PASSWORD EYE TOGGLE MODULE
    // =========================

    function initPasswordToggle() {
        function setupPasswordInputs(container = document) {
            container.querySelectorAll('input[type="password"]').forEach((input) => {
                if (input.dataset.pwToggleInit === "true") return;
                input.dataset.pwToggleInit = "true";

                let wrapper = input.closest('.pw-toggle-wrapper');
                if (!wrapper) {
                    wrapper = document.createElement('div');
                    wrapper.className = 'pw-toggle-wrapper';
                    input.parentNode.insertBefore(wrapper, input);
                    wrapper.appendChild(input);
                }

                if (!wrapper.querySelector('.pw-toggle-btn')) {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'pw-toggle-btn';
                    btn.setAttribute('aria-label', 'Show password');
                    btn.setAttribute('title', 'Show password');
                    btn.setAttribute('tabindex', '-1');
                    btn.innerHTML = `
                        <svg class="eye-icon eye-open" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                        <svg class="eye-icon eye-closed" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;">
                            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                            <line x1="1" y1="1" x2="23" y2="23"></line>
                        </svg>
                    `;
                    wrapper.appendChild(btn);
                }
            });
        }

        setupPasswordInputs();

        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.pw-toggle-btn');
            if (!btn) return;
            e.preventDefault();

            const wrapper = btn.closest('.pw-toggle-wrapper');
            const input = wrapper ? wrapper.querySelector('input') : null;
            if (!input) return;

            const isShowing = input.type === 'text';
            input.type = isShowing ? 'password' : 'text';

            const eyeOpen = btn.querySelector('.eye-open');
            const eyeClosed = btn.querySelector('.eye-closed');

            if (isShowing) {
                btn.setAttribute('aria-label', 'Show password');
                btn.setAttribute('title', 'Show password');
                btn.classList.remove('is-showing');
                if (eyeOpen) eyeOpen.style.display = '';
                if (eyeClosed) eyeClosed.style.display = 'none';
            } else {
                btn.setAttribute('aria-label', 'Hide password');
                btn.setAttribute('title', 'Hide password');
                btn.classList.add('is-showing');
                if (eyeOpen) eyeOpen.style.display = 'none';
                if (eyeClosed) eyeClosed.style.display = '';
            }
        });

        // Observer for dynamically injected inputs (e.g. modals)
        const observer = new MutationObserver(() => {
            setupPasswordInputs();
        });
        if (document.body) {
            observer.observe(document.body, { childList: true, subtree: true });
        }
    }

    // =========================
    // INIT
    // =========================

    document.addEventListener("DOMContentLoaded", () => {
        initThemeToggle();
        bindSubmitGuards();
        initHoverDropdowns();
        initPasswordToggle();

        document.querySelector("[data-menu-toggle]")?.addEventListener("click", toggleMenu);

        document.querySelectorAll(".nav-links .has-submenu > a").forEach((link) => {
            link.addEventListener("click", (event) => {
                if (!window.matchMedia("(max-width: 768px)").matches) return;
                event.preventDefault();
                toggleMobileSubmenu(link);
            });
        });

        bindItemRedirects();
        bindAdjustSearch();
        bindStockcardTools();
        initDashboardDetailPanels();
        initSettingsPage();
        initStockoutApproval();
        initProductDelete();
          const flash = document.querySelector(".flash-message");

        if (flash) {
            setTimeout(() => {
                flash.style.opacity = "0";
                flash.style.transform = "scale(0.95)";

                setTimeout(() => {
                    flash.remove();
                }, 200);
            }, 1500); // 1.5 seconds
        }
    });

})();



// ═══════════════════════════════════════════════════════════════
//  BACKUP MODULE  (global — called from settings view)
// ═══════════════════════════════════════════════════════════════
// ================================================================
//  BACKUP MODULE  (global -- called from settings view)
// ================================================================
// ================================================================
//  BACKUP MODULE
// ================================================================
// ================================================================
//  BACKUP MODULE
// ================================================================

(function initBackupModule() {
    var BASE = (window.appConfig && window.appConfig.baseUrl) ? window.appConfig.baseUrl : '/';
    var LS_LAST_KEY = 'bsu_lastAutoBackup';
    var LS_DATE_KEY = 'bsu_lastAutoBackupDate';

    function csrfH() {
        var n = (window.appConfig && window.appConfig.csrfHeader) ? window.appConfig.csrfHeader : '';
        var m = document.querySelector('meta[name="' + n + '"]');
        var h = { 'X-Requested-With': 'XMLHttpRequest' };
        if (m && n) h[n] = m.content;
        return h;
    }

    function fmtSize(b) {
        b = parseInt(b, 10);
        if (b < 1024) return b + ' B';
        if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
        return (b / 1048576).toFixed(2) + ' MB';
    }

    function setStatus(msg, ok) {
        var el = document.getElementById('backupStatusText');
        if (!el) return;
        el.textContent = msg;
        el.style.color = (ok !== false) ? 'var(--color-success,#16a34a)' : '#dc2626';
    }

    function convertTo12h(hhmm) {
        var parts = (hhmm || '00:00').split(':');
        var h = parseInt(parts[0], 10);
        var m = parts[1] || '00';
        var suffix = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        return h + ':' + m + ' ' + suffix;
    }

    function updateHint(hours, time) {
        var hint = document.querySelector('.backup-dir-hint');
        if (!hint) return;
        hours = parseInt(hours, 10);
        if (!hours || hours <= 0) {
            hint.innerHTML = 'Auto-backup is <strong>disabled</strong> (Manual only).';
            return;
        }
        if (hours >= 24) {
            var ampm = convertTo12h(time);
            var lastDateStr = localStorage.getItem(LS_DATE_KEY) || '';
            var todayStr = new Date().toISOString().slice(0, 10);
            if (lastDateStr === todayStr) {
                hint.innerHTML = 'Auto-backup runs <strong>once per day at ' + ampm + '</strong>. Already ran today.';
            } else {
                hint.innerHTML = 'Auto-backup runs <strong>once per day at ' + ampm + '</strong>. Will run on next page load after that time.';
            }
        } else {
            var lastMs = parseInt(localStorage.getItem(LS_LAST_KEY) || '0', 10);
            var nextMs = lastMs + hours * 3600000;
            var nowMs = Date.now();
            if (!lastMs || nextMs <= nowMs) {
                hint.innerHTML = 'Auto-backup every <strong>' + hours + ' hour(s)</strong>. Runs on next page load.';
            } else {
                hint.innerHTML = 'Auto-backup every <strong>' + hours + ' hour(s)</strong>. Next: <strong>' + new Date(nextMs).toLocaleString() + '</strong>.';
            }
        }
    }

    function applyConfigToUI(data) {
        var dirInput  = document.getElementById('backupDirInput');
        var dirInput2 = document.getElementById('backupDirInput2');
        if (dirInput  && data.backup_dir)   dirInput.value  = data.backup_dir;
        if (dirInput2 && data.backup_dir_2 !== undefined) dirInput2.value = data.backup_dir_2 || '';

        var sel = document.getElementById('backupIntervalSelect');
        if (sel && data.backup_interval_hours !== undefined) {
            var v = String(data.backup_interval_hours);
            var matched = false;
            for (var i = 0; i < sel.options.length; i++) {
                if (sel.options[i].value === v) { sel.options[i].selected = true; matched = true; break; }
            }
            if (!matched) {
                var custom = document.createElement('option');
                custom.value = v;
                custom.textContent = 'Every ' + v + ' hours (custom)';
                custom.selected = true;
                sel.insertBefore(custom, sel.firstChild);
            }
        }

        var timeInput = document.getElementById('backupTimeInput');
        if (timeInput && data.backup_time) timeInput.value = data.backup_time;

        updateHint(
            parseInt((data && data.backup_interval_hours) || 24, 10),
            (data && data.backup_time) ? data.backup_time : '00:00'
        );
    }

    document.addEventListener('change', function (e) {
        if (!e.target) return;
        if (e.target.id === 'backupIntervalSelect' || e.target.id === 'backupTimeInput') {
            var sel = document.getElementById('backupIntervalSelect');
            var ti  = document.getElementById('backupTimeInput');
            updateHint(parseInt(sel ? sel.value : '24', 10), ti ? ti.value : '00:00');
        }
    });

    // ---- drive status badge ----------------------------------

    function driveBadge(b) {
        var hasDr2 = b.backup_filepath_2 && b.backup_filepath_2.length > 0;
        if (!hasDr2) return '<span style="font-size:11px;color:#94a3b8;">Drive 1 only</span>';
        var ok2 = parseInt(b.drive2_ok, 10) === 1;
        return ok2
            ? '<span style="font-size:11px;color:#16a34a;font-weight:600;">Both drives</span>'
            : '<span style="font-size:11px;color:#dc2626;">Drive 2 failed</span>';
    }

    // ---- load backup list -----------------------------------

    async function loadBackupList() {
        var table = document.getElementById('backup-list-table');
        if (!table) return;
        try {
            var resp = await fetch(BASE + 'settings/backup/list', { headers: csrfH() });
            var data = await resp.json();
            applyConfigToUI(data);

            var loadingRow = document.getElementById('backup-loading-row');
            if (loadingRow) loadingRow.remove();
            table.querySelectorAll('tr.backup-row').forEach(function (r) { r.remove(); });

            // Update header to show Drives column
            var headerRow = table.querySelector('tr');
            if (headerRow) {
                var ths = headerRow.querySelectorAll('th');
                if (ths.length === 7 && ths[6].textContent === 'Action') {
                    var drTh = document.createElement('th');
                    drTh.textContent = 'Drives';
                    headerRow.insertBefore(drTh, ths[6]);
                }
            }

            var backups = data.backups || [];
            if (!backups.length) {
                var er = document.createElement('tr');
                er.className = 'backup-row';
                er.innerHTML = '<td colspan="8" style="text-align:center;padding:20px;color:#94a3b8;">No backups yet. Click <strong>Backup Now</strong> to create the first one.</td>';
                table.appendChild(er);
                return;
            }
            backups.forEach(function (b) {
                var tr = document.createElement('tr');
                tr.className = 'backup-row';
                var safe = b.backup_filename.replace(/'/g, "\\'");
                tr.innerHTML =
                    '<td><span class="status-badge" style="background:#e0f2fe;color:#075985;border:1px solid #bae6fd;">#' + b.backup_slot + '</span></td>' +
                    '<td style="font-size:12px;word-break:break-all;">' + b.backup_filename + '</td>' +
                    '<td>' + (b.created_at || '') + '</td>' +
                    '<td>' + fmtSize(b.file_size_bytes) + '</td>' +
                    '<td>' + (b.office_name || '') + '</td>' +
                    '<td>' + (b.created_by_name || '') + '</td>' +
                    '<td>' + driveBadge(b) + '</td>' +
                    '<td><a class="action-btn edit-btn" href="' + BASE + 'settings/backup/download/' + b.backup_id + '" style="text-decoration:none;">Download</a> ' +
                    '<a class="action-btn activate-btn" href="#" onclick="restoreFromBackupId(' + b.backup_id + ',\'' + safe + '\');return false;">Restore</a></td>';
                table.appendChild(tr);
            });
        } catch (e) { setStatus('Failed to load: ' + e.message, false); }
    }

    // ---- manual backup --------------------------------------

    window.triggerBackup = async function () {
        var btn = document.getElementById('backupNowBtn');
        if (btn) { btn.disabled = true; btn.textContent = 'Backing up...'; }
        setStatus('Creating backup...', true);
        try {
            var resp = await fetch(BASE + 'settings/backup/run', { method: 'POST', headers: csrfH() });
            var data = await resp.json();
            if (resp.ok) {
                var now = Date.now();
                localStorage.setItem(LS_LAST_KEY, String(now));
                localStorage.setItem(LS_DATE_KEY, new Date().toISOString().slice(0, 10));
                setStatus('Backup created! ' + (data.message || ''), true);
                await loadBackupList();
            } else {
                setStatus('Backup failed: ' + (data.message || ''), false);
            }
        } catch (e) { setStatus('Network error: ' + e.message, false); }
        finally { if (btn) { btn.disabled = false; btn.textContent = 'Backup Now'; } }
    };

    // ---- save settings (dir1, dir2, interval, time) ---------

    window.saveBackupSettings = async function () {
        var dirInput  = document.getElementById('backupDirInput');
        var dirInput2 = document.getElementById('backupDirInput2');
        var sel       = document.getElementById('backupIntervalSelect');
        var timeInput = document.getElementById('backupTimeInput');

        var dir      = (dirInput  ? dirInput.value  : '').trim();
        var dir2     = (dirInput2 ? dirInput2.value : '').trim();
        var interval = parseInt(sel ? sel.value : '24', 10);
        var bkTime   = (timeInput ? timeInput.value : '00:00') || '00:00';

        if (!dir) { setStatus('Drive 1 directory path cannot be empty.', false); return; }

        var body = new URLSearchParams({ backup_dir: dir, backup_dir_2: dir2, backup_interval_hours: interval, backup_time: bkTime });
        try {
            var resp = await fetch(BASE + 'settings/backup/config', { method: 'POST', headers: csrfH(), body: body });
            var data = await resp.json();
            if (resp.ok) {
                var drive2note = data.backup_dir_2 ? ' Drive 2: ' + data.backup_dir_2 : ' (Drive 2 not set)';
                setStatus('Settings saved!' + drive2note, true);
                updateHint(data.backup_interval_hours !== undefined ? data.backup_interval_hours : interval, data.backup_time || bkTime);
                if (dirInput2 && data.backup_dir_2 !== undefined) dirInput2.value = data.backup_dir_2;
            } else {
                setStatus('Save failed: ' + (data.message || ''), false);
            }
        } catch (e) { setStatus('Error: ' + e.message, false); }
    };

    // ---- restore from file ----------------------------------

    window.restoreFromFile = async function (input) {
        var file = input.files[0];
        if (!file) return;
        if (!confirm('Restore from "' + file.name + '"?\n\nThis will OVERWRITE all existing data for your office.\nThis action cannot be undone.')) {
            input.value = ''; return;
        }
        setStatus('Restoring...', true);
        var fd = new FormData();
        fd.append('sql_file', file);
        var n = (window.appConfig && window.appConfig.csrfHeader) ? window.appConfig.csrfHeader : '';
        var m = document.querySelector('meta[name="' + n + '"]');
        if (m && window.appConfig && window.appConfig.csrfTokenName) fd.append(window.appConfig.csrfTokenName, m.content);
        try {
            var resp = await fetch(BASE + 'settings/backup/restore', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd });
            var data = await resp.json();
            setStatus(resp.ok ? 'Restored! ' + (data.message || '') : 'Restore failed: ' + (data.message || ''), resp.ok);
        } catch (e) { setStatus('Error: ' + e.message, false); }
        input.value = '';
    };

    window.restoreFromBackupId = async function (id, filename) {
        if (!confirm('Restore backup "' + filename + '"?\n\nThis will OVERWRITE all existing data for your office.\nThis action cannot be undone.')) return;
        setStatus('Restoring...', true);
        var body = new URLSearchParams({ backup_id: id });
        try {
            var resp = await fetch(BASE + 'settings/backup/restore', { method: 'POST', headers: csrfH(), body: body });
            var data = await resp.json();
            setStatus(resp.ok ? 'Restored! ' + (data.message || '') : 'Restore failed: ' + (data.message || ''), resp.ok);
        } catch (e) { setStatus('Error: ' + e.message, false); }
    };

    // ---- auto-backup ----------------------------------------

    async function runAutoBackupIfNeeded() {
        var levelId = parseInt((window.appConfig && window.appConfig.levelId) || '0', 10);
        if (levelId < 2 || levelId > 3) return;
        var intervalHours = 24, backupTime = '00:00';
        try {
            var resp = await fetch(BASE + 'settings/backup/list', { headers: csrfH() });
            if (resp.ok) { var data = await resp.json(); intervalHours = parseInt(data.backup_interval_hours || '24', 10); backupTime = data.backup_time || '00:00'; }
        } catch (_) {}
        if (!intervalHours || intervalHours <= 0) return;
        var nowMs = Date.now();
        var nowDate = new Date();
        if (intervalHours >= 24) {
            var lastDateStr = localStorage.getItem(LS_DATE_KEY) || '';
            var todayStr = nowDate.toISOString().slice(0, 10);
            if (lastDateStr === todayStr) return;
            var parts = backupTime.split(':');
            var schedMins = parseInt(parts[0], 10) * 60 + parseInt(parts[1] || '0', 10);
            var nowMins = nowDate.getHours() * 60 + nowDate.getMinutes();
            if (nowMins < schedMins) return;
        } else {
            var lastMs = parseInt(localStorage.getItem(LS_LAST_KEY) || '0', 10);
            if (lastMs && (nowMs - lastMs) < intervalHours * 3600000) return;
        }
        localStorage.setItem(LS_LAST_KEY, String(nowMs));
        localStorage.setItem(LS_DATE_KEY, nowDate.toISOString().slice(0, 10));
        try { await fetch(BASE + 'settings/backup/auto', { method: 'POST', headers: csrfH() }); } catch (_) {}
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (document.getElementById('backup-list-table')) loadBackupList();
        runAutoBackupIfNeeded();
    });

})();
