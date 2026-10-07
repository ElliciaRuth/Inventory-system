/**
 * Table Pagination & Search Component
 * Universal, clean, customizable client-side pagination for data tables.
 */
(() => {
    'use strict';

    const DEFAULT_PAGE_SIZES = [5, 10, 25, 50, 100, -1];
    const DEFAULT_PAGE_SIZE = 10;

    /**
     * Check if a row is an empty-state or loading placeholder
     */
    function isPlaceholderRow(tr) {
        if (!tr) return false;
        if (tr.classList.contains('empty-state') ||
            tr.classList.contains('sc-empty-row') ||
            tr.id === 'backup-loading-row' ||
            tr.classList.contains('table-pagination-empty-row')) {
            return true;
        }
        const firstTd = tr.querySelector('td');
        if (firstTd && (firstTd.colSpan >= 3) && (
            firstTd.classList.contains('empty-state') ||
            firstTd.textContent.toLowerCase().includes('no records') ||
            firstTd.textContent.toLowerCase().includes('no batches') ||
            firstTd.textContent.toLowerCase().includes('no items') ||
            firstTd.textContent.toLowerCase().includes('loading') ||
            firstTd.textContent.toLowerCase().includes('no backups yet')
        )) {
            return true;
        }
        return false;
    }

    /**
     * Check if a row is a header row (inside thead or has <th> cells)
     */
    function isHeaderRow(tr) {
        if (!tr) return false;
        if (tr.closest('thead')) return true;
        if (tr.querySelector('th')) return true;
        return false;
    }

    /**
     * Paginate an individual table element
     */
    function paginateTable(table) {
        if (!table || table.dataset.paginationInitialized === 'true') return;
        if (table.getAttribute('data-no-paginate') === 'true' || table.classList.contains('no-paginate')) return;

        // Skip tables inside CI4 error debugging views or tiny widget tables
        if (table.closest('.trace') || table.closest('.source') || table.closest('.content') || table.closest('#debugbar_loader')) {
            return;
        }

        // Mark as initialized
        table.dataset.paginationInitialized = 'true';

        // Identify table storage key
        const tableId = table.id || '';
        const storageKey = tableId
            ? 'table_pagesize_' + tableId
            : 'table_pagesize_' + window.location.pathname.replace(/[^a-zA-Z0-9]/g, '_') + '_' + (table.getAttribute('data-table-index') || '0');

        // Initial settings
        const customSizesAttr = table.getAttribute('data-page-sizes');
        const pageSizes = customSizesAttr
            ? customSizesAttr.split(',').map(s => s.trim().toLowerCase() === 'all' ? -1 : parseInt(s, 10)).filter(n => !isNaN(n))
            : DEFAULT_PAGE_SIZES;

        let savedSize = parseInt(localStorage.getItem(storageKey) || '', 10);
        if (isNaN(savedSize) || !pageSizes.includes(savedSize)) {
            const attrSize = parseInt(table.getAttribute('data-page-size') || '', 10);
            savedSize = (!isNaN(attrSize) && pageSizes.includes(attrSize)) ? attrSize : DEFAULT_PAGE_SIZE;
        }

        let currentPage = 1;
        let pageSize = savedSize;
        let searchQuery = '';
        let isUpdating = false;

        // Build UI Wrapper
        const wrapper = document.createElement('div');
        wrapper.className = 'table-pagination-wrapper';

        // Check if table is inside a scrolling card/wrap
        const parentCard = table.parentElement;
        const insertTarget = (parentCard && (parentCard.classList.contains('table-card') || parentCard.classList.contains('panel-card') || parentCard.classList.contains('section-card')) && parentCard.children.length === 1) ? parentCard : table;

        // Insert wrapper before table (or target)
        insertTarget.parentNode.insertBefore(wrapper, insertTarget);

        // Top Toolbar
        const toolbar = document.createElement('div');
        toolbar.className = 'table-pagination-toolbar';

        // Page Size Selector
        const sizeContainer = document.createElement('div');
        sizeContainer.className = 'table-pagination-size';
        const sizeLabel = document.createElement('label');
        sizeLabel.innerHTML = `<span>Show</span> <select class="table-pagination-select" aria-label="Entries per page"></select> <span>entries</span>`;
        const sizeSelect = sizeLabel.querySelector('select');

        pageSizes.forEach(size => {
            const opt = document.createElement('option');
            opt.value = String(size);
            opt.textContent = size === -1 ? 'All' : String(size);
            if (size === pageSize) opt.selected = true;
            sizeSelect.appendChild(opt);
        });

        sizeContainer.appendChild(sizeLabel);
        toolbar.appendChild(sizeContainer);

        // Search Input
        const searchContainer = document.createElement('div');
        searchContainer.className = 'table-pagination-search';
        searchContainer.innerHTML = `
            <span class="table-pagination-search-icon" aria-hidden="true">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
            </span>
            <input type="search" class="table-pagination-search-input" placeholder="Search table..." aria-label="Search this table">
            <button type="button" class="table-pagination-search-clear" aria-label="Clear search" title="Clear search">✕</button>
        `;
        const searchInput = searchContainer.querySelector('.table-pagination-search-input');
        const clearBtn = searchContainer.querySelector('.table-pagination-search-clear');
        toolbar.appendChild(searchContainer);

        wrapper.appendChild(toolbar);

        // Scroll Container
        const scrollContainer = document.createElement('div');
        scrollContainer.className = 'table-pagination-scroll';
        wrapper.appendChild(scrollContainer);

        // Move target into scroll container
        scrollContainer.appendChild(insertTarget);

        // Bottom Footer
        const footer = document.createElement('div');
        footer.className = 'table-pagination-footer';
        footer.innerHTML = `
            <div class="table-pagination-info" aria-live="polite"></div>
            <nav class="table-pagination-nav" aria-label="Table pagination"></nav>
        `;
        const infoEl = footer.querySelector('.table-pagination-info');
        const navEl = footer.querySelector('.table-pagination-nav');
        wrapper.appendChild(footer);

        // Empty state row for search queries
        let emptySearchRow = null;

        // Core Render Function
        function render() {
            if (isUpdating) return;
            isUpdating = true;

            const allRows = Array.from(table.querySelectorAll('tr')).filter(tr => !isHeaderRow(tr) && !tr.classList.contains('table-pagination-empty-row'));
            const dataRows = allRows.filter(tr => !isPlaceholderRow(tr));

            // If table has only a placeholder row and 0 actual data rows
            if (dataRows.length === 0) {
                allRows.forEach(tr => { tr.style.display = ''; });
                infoEl.innerHTML = `Showing 0 entries`;
                navEl.innerHTML = '';
                if (emptySearchRow && emptySearchRow.parentNode) {
                    emptySearchRow.parentNode.removeChild(emptySearchRow);
                }
                isUpdating = false;
                return;
            }

            // Hide original placeholder rows when we have actual data
            allRows.forEach(tr => {
                if (isPlaceholderRow(tr) && !tr.classList.contains('table-pagination-empty-row')) {
                    tr.style.display = 'none';
                }
            });

            // Filter data rows by search query
            const q = searchQuery.trim().toLowerCase();
            const matchedRows = [];

            dataRows.forEach(row => {
                if (!q) {
                    matchedRows.push(row);
                } else {
                    const rowText = row.textContent.toLowerCase();
                    if (rowText.includes(q)) {
                        matchedRows.push(row);
                    } else {
                        row.style.display = 'none';
                    }
                }
            });

            const totalMatched = matchedRows.length;
            const totalDataRows = dataRows.length;

            // Handle No Matches
            if (totalMatched === 0 && q) {
                if (!emptySearchRow) {
                    emptySearchRow = document.createElement('tr');
                    emptySearchRow.className = 'table-pagination-empty-row';
                    const colCount = Math.max(1, table.querySelectorAll('th').length || 6);
                    emptySearchRow.innerHTML = `
                        <td colspan="${colCount}">
                            <div class="table-pagination-empty-box">
                                <span class="table-pagination-empty-icon">🔍</span>
                                <div>No records found matching "<strong>${escapeHtml(searchQuery)}</strong>"</div>
                            </div>
                        </td>
                    `;
                }
                const tbody = table.querySelector('tbody') || table;
                if (!emptySearchRow.parentNode) {
                    tbody.appendChild(emptySearchRow);
                }
                emptySearchRow.style.display = '';
                infoEl.innerHTML = `Showing 0 entries <span class="table-pagination-filtered">(filtered from ${totalDataRows} total)</span>`;
                navEl.innerHTML = '';
                isUpdating = false;
                return;
            } else if (emptySearchRow && emptySearchRow.parentNode) {
                emptySearchRow.style.display = 'none';
            }

            // Calculate pagination
            const effectivePageSize = pageSize === -1 ? totalMatched : pageSize;
            const totalPages = Math.max(1, Math.ceil(totalMatched / effectivePageSize));

            if (currentPage > totalPages) {
                currentPage = totalPages;
            }
            if (currentPage < 1) {
                currentPage = 1;
            }

            const startIndex = (currentPage - 1) * effectivePageSize;
            const endIndex = pageSize === -1 ? totalMatched : Math.min(startIndex + effectivePageSize, totalMatched);

            // Toggle visibility of matched rows
            matchedRows.forEach((row, idx) => {
                if (pageSize === -1 || (idx >= startIndex && idx < endIndex)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });

            // Update Info text
            const displayStart = totalMatched === 0 ? 0 : startIndex + 1;
            const displayEnd = endIndex;
            let infoHtml = `Showing <strong>${displayStart}</strong> to <strong>${displayEnd}</strong> of <strong>${totalMatched}</strong> entries`;
            if (totalMatched < totalDataRows) {
                infoHtml += ` <span class="table-pagination-filtered">(filtered from ${totalDataRows} total)</span>`;
            }
            infoEl.innerHTML = infoHtml;

            // Render Navigation Buttons
            renderNav(totalPages);

            isUpdating = false;
        }

        // Render Page Buttons
        function renderNav(totalPages) {
            navEl.innerHTML = '';
            if (totalPages <= 1) return;

            // First Page button
            const firstBtn = createBtn('«', 'First page', 1, currentPage === 1, false, 'table-page-btn-nav');
            navEl.appendChild(firstBtn);

            // Prev Page button
            const prevBtn = createBtn('‹ Prev', 'Previous page', currentPage - 1, currentPage === 1, false, 'table-page-btn-nav');
            navEl.appendChild(prevBtn);

            // Numbered buttons with smart ellipsis
            const pagesToShow = getPageRange(currentPage, totalPages);
            pagesToShow.forEach(item => {
                if (item === '...') {
                    const ellipsis = document.createElement('span');
                    ellipsis.className = 'table-page-ellipsis';
                    ellipsis.textContent = '…';
                    navEl.appendChild(ellipsis);
                } else {
                    const pageNum = item;
                    const pBtn = createBtn(String(pageNum), `Page ${pageNum}`, pageNum, false, pageNum === currentPage);
                    navEl.appendChild(pBtn);
                }
            });

            // Next Page button
            const nextBtn = createBtn('Next ›', 'Next page', currentPage + 1, currentPage === totalPages, false, 'table-page-btn-nav');
            navEl.appendChild(nextBtn);

            // Last Page button
            const lastBtn = createBtn('»', 'Last page', totalPages, currentPage === totalPages, false, 'table-page-btn-nav');
            navEl.appendChild(lastBtn);
        }

        function createBtn(text, title, targetPage, disabled, isActive = false, extraClass = '') {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'table-page-btn' + (extraClass ? ' ' + extraClass : '');
            btn.textContent = text;
            btn.title = title;
            btn.setAttribute('aria-label', title);

            if (disabled) {
                btn.disabled = true;
            } else if (isActive) {
                btn.classList.add('is-active');
                btn.setAttribute('aria-current', 'page');
            } else {
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    currentPage = targetPage;
                    render();
                    const tableRect = table.getBoundingClientRect();
                    if (tableRect.top < 60) {
                        table.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                });
            }
            return btn;
        }

        // Smart Page Range with Ellipsis
        function getPageRange(current, total) {
            if (total <= 7) {
                return Array.from({ length: total }, (_, i) => i + 1);
            }
            if (current <= 4) {
                return [1, 2, 3, 4, 5, '...', total];
            }
            if (current >= total - 3) {
                return [1, '...', total - 4, total - 3, total - 2, total - 1, total];
            }
            return [1, '...', current - 1, current, current + 1, '...', total];
        }

        // Event Listeners
        sizeSelect.addEventListener('change', () => {
            pageSize = parseInt(sizeSelect.value, 10);
            localStorage.setItem(storageKey, String(pageSize));
            currentPage = 1;
            render();
        });

        searchInput.addEventListener('input', () => {
            searchQuery = searchInput.value;
            clearBtn.classList.toggle('is-visible', searchQuery.length > 0);
            currentPage = 1;
            render();
        });

        clearBtn.addEventListener('click', () => {
            searchInput.value = '';
            searchQuery = '';
            clearBtn.classList.remove('is-visible');
            searchInput.focus();
            currentPage = 1;
            render();
        });

        // Watch for Dynamic DOM changes
        const observer = new MutationObserver((mutations) => {
            if (isUpdating) return;
            // Only re-render if nodes were added or removed
            const hasStructuralChange = mutations.some(m => m.type === 'childList' && (m.addedNodes.length > 0 || m.removedNodes.length > 0));
            if (hasStructuralChange) {
                render();
            }
        });
        observer.observe(table, { childList: true, subtree: true });

        // Expose update API on table element
        table.paginate = {
            update: render,
            setPage: (p) => { currentPage = p; render(); },
            setPageSize: (s) => {
                pageSize = s;
                sizeSelect.value = String(s);
                localStorage.setItem(storageKey, String(s));
                render();
            }
        };

        table.addEventListener('table:update', render);

        // Initial render
        render();
    }

    function escapeHtml(str) {
        return (str || '').replace(/[&<>"']/g, m => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
        }[m]));
    }

    /**
     * Initialize pagination for all eligible tables
     */
    function initAll(root = document) {
        const selector = [
            'table.data-table',
            'table.dashboard-detail-table',
            'table.report-table',
            'table[data-paginate="true"]'
        ].join(', ');

        const tables = root.querySelectorAll(selector);
        tables.forEach((tbl, idx) => {
            if (!tbl.getAttribute('data-table-index')) {
                tbl.setAttribute('data-table-index', String(idx));
            }
            paginateTable(tbl);
        });
    }

    // Auto-init on DOMContentLoaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => initAll());
    } else {
        initAll();
    }

    // Global namespace
    window.TablePagination = {
        initAll,
        paginateTable,
        refresh: (table) => {
            if (table && table.paginate) table.paginate.update();
            else initAll();
        }
    };

    // Alias helper for convenience
    window.paginateTable = paginateTable;

})();
