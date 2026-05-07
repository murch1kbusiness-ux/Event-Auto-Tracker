'use strict';

const App = {
    events: [],
    scanMeta: {
        total_found: 0,
        new_count: 0,
        skipped_duplicates: 0,
        venues_checked: 0,
        total_stored: 0,
        last_checked: null,
    },

    init() {
        this.bindUI();
        this.bindTableHeaders();
        this.loadData();
        this.loadTelegramStatus();
        this.loadSheetsStatus();
    },

    bindUI() {
        this.on('btn-scan', 'click', this.runScan);
        this.on('btn-reset', 'click', this.resetFilters);
        this.on('btn-edit-telegram', 'click', this.enterTelegramEditMode);
        this.on('btn-save-telegram', 'click', this.saveTelegramSettings);
        this.on('btn-cancel-telegram', 'click', this.exitTelegramEditMode);
        this.on('btn-send-last-telegram', 'click', this.sendLastScanTelegram);
        this.on('search-input', 'input', this.render);
        this.on('venue-filter', 'change', this.render);
        this.on('sort-select', 'change', this.render);
        this.on('range-filter', 'change', this.render);
    },

    // Map from th[data-sort] → sort-select option values
    _colToSort: {
        venue: 'venue',
        title: 'title',
        date: 'date-asc',
        source: 'source',
        checked: 'checked',
    },

    bindTableHeaders() {
        const table = document.getElementById('events-table');
        if (!table) return;
        table.querySelectorAll('th[data-sort]').forEach(th => {
            th.addEventListener('click', () => {
                const col = th.dataset.sort;
                const select = document.getElementById('sort-select');
                if (!select) return;

                // If already sorted by this column ascending → flip to desc (only for date/checked)
                const current = select.value;
                const ascending = this._colToSort[col];
                const descending = ascending === 'date-asc' ? 'date-desc'
                                 : ascending === 'checked' ? 'checked-desc'
                                 : null;

                if (descending && current === ascending) {
                    select.value = descending;
                } else if (descending && current === descending) {
                    select.value = ascending;
                } else {
                    select.value = ascending;
                }
                this.render();
                this.syncHeaderSortIcons();
            });
        });
    },

    syncHeaderSortIcons() {
        const table = document.getElementById('events-table');
        if (!table) return;
        const sort = this.value('sort-select');
        table.querySelectorAll('th[data-sort]').forEach(th => {
            const col = th.dataset.sort;
            const asc = this._colToSort[col];
            const desc = asc === 'date-asc' ? 'date-desc' : asc === 'checked' ? 'checked-desc' : null;
            const icon = th.querySelector('.sort-icon');
            th.classList.remove('th-active', 'th-desc');
            if (sort === asc) {
                th.classList.add('th-active');
                if (icon) icon.textContent = '↑';
            } else if (desc && sort === desc) {
                th.classList.add('th-active', 'th-desc');
                if (icon) icon.textContent = '↓';
            } else {
                if (icon) icon.textContent = '↕';
            }
        });
    },

    on(id, eventName, handler) {
        const el = document.getElementById(id);
        if (el) el.addEventListener(eventName, handler.bind(this));
    },

    async loadData() {
        const bust = '?t=' + Date.now();
        try {
            const [eventsRes, metaRes] = await Promise.all([
                fetch('data/events.json' + bust),
                fetch('data/scan_meta.json' + bust),
            ]);
            this.events = eventsRes.ok ? await eventsRes.json() : [];
            this.scanMeta = metaRes.ok ? { ...this.scanMeta, ...(await metaRes.json()) } : this.scanMeta;
        } catch (_) {
            this.events = [];
        }

        this.populateVenueFilter();
        this.render();
        this.updateStatus();
        this.renderSourceStatus(this.scanMeta.source_status || []);
        this.updateTelegramPreview(this.scanMeta.total_found || this.events.length, this.scanMeta.new_count || 0);
    },

    async runScan() {
        const btn = document.getElementById('btn-scan');
        if (!btn || btn.disabled) return;

        btn.disabled = true;
        btn.textContent = 'Scanning...';
        this.hideResult();

        try {
            const res = await fetch('run_scan.php?t=' + Date.now());
            const data = await res.json();

            if (!res.ok || !data.success) {
                const message = data.error || 'Scan failed. Check the source pages and try again.';
                this.showResult(message, 'error');
                this.showToast(message, 'error');
                if (data.source_errors?.length) {
                    console.warn('Source page errors', data.source_errors);
                }
                this.renderSourceStatus(data.source_status || []);
                return;
            }

            this.scanMeta = { ...this.scanMeta, ...data };
            const skipped = data.skipped_duplicates ?? data.skipped ?? 0;
            const errorCount = data.source_errors?.length || 0;
            let message = `Scan complete. Found ${data.total_found} events, added ${data.new_count} new, skipped ${skipped} duplicates.`;
            if (errorCount > 0) {
                message += ` (${errorCount} source ${errorCount === 1 ? 'had' : 'had'} issues)`;
            }
            this.showResult(message, 'success');
            this.showToast(`Found ${data.total_found}. Added ${data.new_count}.`, 'success');
            this.updateTelegramPreview(data.total_found, data.new_count);
            this.renderSourceStatus(data.source_status || []);
            await this.loadData();
        } catch (err) {
            const message = 'Scan failed. Is the PHP server running?';
            this.showResult(message, 'error');
            this.showToast(message, 'error');
            console.error(err);
        } finally {
            btn.disabled = false;
            btn.textContent = 'Run Scan';
        }
    },

    async loadTelegramStatus() {
        try {
            const res = await fetch('telegram_status.php?t=' + Date.now());
            const data = res.ok ? await res.json() : { chat_id_configured: false, chat_id: '' };
            this.updateTelegramDisplay(data.chat_id || '');
        } catch (_) {
            this.updateTelegramDisplay('');
        }
    },

    async loadSheetsStatus() {
        try {
            const res = await fetch('sheets_status.php?t=' + Date.now());
            const data = res.ok ? await res.json() : { configured: false, sheet_url: '' };
            this.updateSheetsDisplay(Boolean(data.configured), data.sheet_url || '');
        } catch (_) {
            this.updateSheetsDisplay(false, '');
        }
    },

    updateSheetsDisplay(configured, sheetUrl) {
        const displayEl = document.getElementById('sheets-display');
        const notConfigEl = document.getElementById('sheets-not-configured');
        const linkEl = document.getElementById('sheets-open-link');

        if (configured && sheetUrl) {
            if (displayEl) displayEl.style.display = 'block';
            if (notConfigEl) notConfigEl.style.display = 'none';
            if (linkEl) linkEl.href = sheetUrl;
        } else {
            if (displayEl) displayEl.style.display = 'none';
            if (notConfigEl) notConfigEl.style.display = 'block';
        }
    },

    enterTelegramEditMode() {
        const displayMode = document.getElementById('telegram-display-mode');
        const editMode = document.getElementById('telegram-edit-mode');
        const chatIdInput = document.getElementById('telegram-chat-id');
        const chatDisplay = document.getElementById('telegram-chat-display');

        if (displayMode) displayMode.style.display = 'none';
        if (editMode) editMode.style.display = 'block';

        // Pre-fill with current value if exists
        const currentId = chatDisplay ? chatDisplay.textContent.trim() : '';
        if (currentId !== 'Not set' && chatIdInput) {
            chatIdInput.value = currentId;
        }
        if (chatIdInput) chatIdInput.focus();
    },

    exitTelegramEditMode() {
        const displayMode = document.getElementById('telegram-display-mode');
        const editMode = document.getElementById('telegram-edit-mode');
        const resultEl = document.getElementById('telegram-settings-result');

        if (displayMode) displayMode.style.display = 'block';
        if (editMode) editMode.style.display = 'none';
        if (resultEl) resultEl.textContent = '';
    },

    async saveTelegramSettings() {
        const chatId = this.value('telegram-chat-id').trim();
        const btn = document.getElementById('btn-save-telegram');
        if (!chatId) {
            this.showTelegramSettingsResult('Telegram ID is required.', 'error');
            return;
        }

        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Saving...';
        }

        try {
            const body = new URLSearchParams();
            body.set('telegram_chat_id', chatId);
            const res = await fetch('save_telegram_settings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body,
            });
            const data = await res.json();
            if (!res.ok || !data.success) {
                throw new Error(data.error || 'Could not save Telegram ID.');
            }
            this.showTelegramSettingsResult('✓ Telegram ID saved.', 'success');
            setTimeout(() => {
                this.updateTelegramDisplay(chatId);
                this.exitTelegramEditMode();
            }, 1000);
        } catch (err) {
            this.showTelegramSettingsResult(err.message || 'Could not save Telegram ID.', 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.textContent = 'Save';
            }
        }
    },

    async sendLastScanTelegram() {
        const btn = document.getElementById('btn-send-last-telegram');
        const resultEl = document.getElementById('telegram-send-result');
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Sending...';
        }

        try {
            const res = await fetch('send_last_scan_telegram.php', { method: 'POST' });
            const data = await res.json();
            if (!res.ok || !data.success) {
                let reason = data.reason || data.error || 'Send failed';
                // Translate error codes to user-friendly messages
                if (reason === 'not_configured') reason = 'Telegram ID not set. Click Edit to add it.';
                if (reason === 'missing_bot_token') reason = 'Server not configured for Telegram (contact admin)';
                if (reason === 'missing_chat_id') reason = 'Telegram ID not set. Click Edit to add it.';
                if (reason === 'python_requests_missing') reason = 'Server error: Python requests library missing';
                throw new Error(reason);
            }
            if (resultEl) {
                resultEl.textContent = '✓ Message sent to Telegram.';
                resultEl.className = 'settings-result success';
            }
        } catch (err) {
            if (resultEl) {
                resultEl.textContent = err.message || 'Send failed.';
                resultEl.className = 'settings-result error';
            }
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.textContent = 'Send Last Scan to Telegram';
            }
        }
    },

    updateTelegramDisplay(chatId) {
        const el = document.getElementById('telegram-chat-display');
        if (!el) return;
        el.textContent = chatId && chatId !== '' ? chatId : 'Not set';
        el.className = (chatId && chatId !== '') ? 'configured' : 'not-configured';
    },

    showTelegramSettingsResult(message, type) {
        const el = document.getElementById('telegram-settings-result');
        if (!el) return;
        el.textContent = message;
        el.className = `settings-result ${type}`;
    },

    renderSourceStatus(sourceStatus) {
        const container = document.getElementById('source-status-list');
        if (!container) return;

        if (!sourceStatus || sourceStatus.length === 0) {
            container.innerHTML = '<p class="muted">Run a scan to see source status.</p>';
            return;
        }

        let html = '<div class="status-items">';
        sourceStatus.forEach(item => {
            const statusClass = item.status === 'OK' ? 'status-ok' : 'status-error';
            const statusText = item.status === 'OK'
                ? `${item.events} event${item.events !== 1 ? 's' : ''}`
                : 'Error';
            html += `<div class="status-item-row">
                <span class="status-indicator ${statusClass}"></span>
                <span class="status-name">${this.esc(item.venue_name)}</span>
                <span class="status-text">${this.esc(statusText)}</span>
            </div>`;
        });
        html += '</div>';
        container.innerHTML = html;
    },

    getFiltered() {
        const search = this.value('search-input').toLowerCase().trim();
        const venue = this.value('venue-filter');
        const sort = this.value('sort-select') || 'date-asc';
        const range = this.value('range-filter') || '30';
        const today = this.startOfToday();

        let list = [...this.events];

        if (search) {
            list = list.filter(event =>
                (event.event_title || '').toLowerCase().includes(search) ||
                (event.venue_name || '').toLowerCase().includes(search)
            );
        }

        if (venue) {
            list = list.filter(event => event.venue_name === venue);
        }

        if (range !== 'all') {
            const days = Number(range);
            const cutoff = new Date(today);
            cutoff.setDate(cutoff.getDate() + days);
            list = list.filter(event => {
                const date = this.parseDate(event.date);
                return date && date >= today && date < cutoff;
            });
        }

        list.sort((a, b) => {
            if (sort === 'date-desc') return (b.date || '').localeCompare(a.date || '');
            if (sort === 'title') return (a.event_title || '').localeCompare(b.event_title || '') || (a.date || '').localeCompare(b.date || '');
            if (sort === 'venue') return (a.venue_name || '').localeCompare(b.venue_name || '') || (a.date || '').localeCompare(b.date || '');
            if (sort === 'source') return (a.source_url || '').localeCompare(b.source_url || '') || (a.date || '').localeCompare(b.date || '');
            if (sort === 'checked') return (a.last_checked || '').localeCompare(b.last_checked || '');
            if (sort === 'checked-desc') return (b.last_checked || '').localeCompare(a.last_checked || '');
            return (a.date || '').localeCompare(b.date || '');
        });

        return list;
    },

    render() {
        const filtered = this.getFiltered();
        this.renderTable(filtered);
        this.updateCounts(filtered);
        this.syncHeaderSortIcons();
    },

    renderTable(events) {
        const tbody = document.getElementById('events-tbody');
        if (!tbody) return;

        if (!events.length) {
            tbody.innerHTML = '<tr><td colspan="8" class="empty-cell">No matching events. Change the filters or run a scan.</td></tr>';
            return;
        }

        tbody.innerHTML = events.map(event => {
            const date = event.date ? this.formatDate(event.date) : '—';
            const time = !event.time || event.time === 'TBA'
                ? '<span class="badge tba">TBA</span>'
                : this.esc(event.time);
            const link = event.link
                ? `<a href="${this.esc(event.link)}" target="_blank" rel="noopener noreferrer">↗ Open</a>`
                : '—';
            const sourceHost = event.source_url ? this.sourceLabel(event.source_url) : '';
            const source = event.source_url
                ? `<a href="${this.esc(event.source_url)}" target="_blank" rel="noopener noreferrer" title="${this.esc(event.source_url)}">${this.esc(sourceHost)}</a>`
                : '—';
            const notes = event.newsletter_notes
                ? `<span class="notes-cell">${this.esc(event.newsletter_notes)}</span>`
                : '<span class="muted">—</span>';

            return `<tr>
                <td data-label="Venue"><span class="venue-badge">${this.esc(event.venue_name || '')}</span></td>
                <td data-label="Event" class="event-title">${this.esc(event.event_title || '')}</td>
                <td data-label="Date" class="date-cell">${date}</td>
                <td data-label="Time">${time}</td>
                <td data-label="Link" class="link-cell">${link}</td>
                <td data-label="Source" class="link-cell">${source}</td>
                <td data-label="Last Checked" class="checked-cell">${this.esc(event.last_checked || '—')}</td>
                <td data-label="Newsletter Notes" class="notes-cell">${notes}</td>
            </tr>`;
        }).join('');
    },

    updateCounts(filtered) {
        const total = this.events.length;
        const visible = filtered.length;
        this.setText('results-count', visible === total ? `${total} events` : `Showing ${visible} of ${total}`);
        this.setText('status-total', total);
        this.setText('status-tba', this.events.filter(event => event.time === 'TBA').length);
    },

    updateStatus() {
        const skipped = this.scanMeta.skipped_duplicates ?? this.scanMeta.skipped ?? 0;
        this.setText('status-last-scan', this.scanMeta.last_checked || 'Not run yet');
        this.setText('status-venues', this.scanMeta.venues_checked || new Set(this.events.map(event => event.venue_name)).size || 0);
        this.setText('status-total', this.events.length);
        this.setText('status-new', this.scanMeta.new_count || 0);
        this.setText('status-duplicates', skipped);
        this.setText('status-tba', this.events.filter(event => event.time === 'TBA').length);
    },

    updateTelegramPreview(total, newCount) {
        const el = document.getElementById('tg-message');
        if (!el) return;
        const sheet = window.APP_SHEET_URL || this.scanMeta.sheet_url || 'Google Sheet URL pending';
        el.textContent = `Data pulled! Found ${total || 0} events. Added ${newCount || 0} new events.\nGoogle Sheet: ${sheet}`;
    },

    populateVenueFilter() {
        const select = document.getElementById('venue-filter');
        if (!select) return;
        const current = select.value;
        const venues = [...new Set(this.events.map(event => event.venue_name).filter(Boolean))].sort();
        select.innerHTML = '<option value="">All venues</option>';
        venues.forEach(venue => {
            const option = document.createElement('option');
            option.value = venue;
            option.textContent = venue;
            if (venue === current) option.selected = true;
            select.appendChild(option);
        });
    },

    resetFilters() {
        this.setValue('search-input', '');
        this.setValue('venue-filter', '');
        this.setValue('sort-select', 'date-asc');
        this.setValue('range-filter', '30');
        this.render();
    },

    startOfToday() {
        const d = new Date();
        d.setHours(0, 0, 0, 0);
        return d;
    },

    parseDate(dateStr) {
        if (!dateStr) return null;
        const d = new Date(dateStr + 'T00:00:00');
        return Number.isNaN(d.getTime()) ? null : d;
    },

    formatDate(dateStr) {
        const d = this.parseDate(dateStr);
        if (!d) return this.esc(dateStr);
        return d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
    },

    sourceLabel(url) {
        try {
            const urlObj = new URL(url);
            return urlObj.hostname.replace('www.', '');
        } catch (_) {
            try {
                const match = url.match(/https?:\/\/(?:www\.)?([^\/]+)/);
                return match ? match[1] : url;
            } catch (__) {
                return url;
            }
        }
    },

    value(id) {
        return document.getElementById(id)?.value || '';
    },

    setValue(id, value) {
        const el = document.getElementById(id);
        if (el) el.value = value;
    },

    setText(id, value) {
        const el = document.getElementById(id);
        if (el) el.textContent = value;
    },

    showResult(message, type) {
        const el = document.getElementById('scan-result');
        if (!el) return;
        el.textContent = message;
        el.className = `scan-result ${type} visible`;
    },

    hideResult() {
        const el = document.getElementById('scan-result');
        if (el) el.className = 'scan-result';
    },

    showToast(message, type = 'success') {
        const toast = document.getElementById('toast');
        if (!toast) return;
        toast.textContent = message;
        toast.className = `toast ${type} visible`;
        clearTimeout(this.toastTimer);
        this.toastTimer = setTimeout(() => { toast.className = 'toast'; }, 4200);
    },

    esc(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    },
};

document.addEventListener('DOMContentLoaded', () => App.init());
