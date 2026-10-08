/*
 * UPS Battery report page.
 *
 * Served by the plugin as plugin/ups-battery/assets/report.js. The page data (defaults, URLs, translated
 * texts) is read from <script type="application/json" id="ub-config">. All values that come from the server
 * are put into the page with textContent / createElement, never innerHTML.
 */
(function () {
    'use strict';

    const configNode = document.getElementById('ub-config');
    if (!configNode) { return; }

    const cfg = JSON.parse(configNode.textContent);
    const T = cfg.i18n;
    const LIMITS = ['10', '25', '50', '100', '0'];
    const MAX_METRICS = 6;
    const SINGLE_SORTS = ['hostname', 'location', 'descr', 'value', 'lastupdate'];
    const UPS_SORTS = ['status', 'hostname', 'location', 'runtime', 'charge', 'load', 'temperature', 'swap'];
    const VIEWS = ['ups', 'single', 'matrix'];
    const FOCUS = ['on_battery', 'overdue', 'due', 'alarm', 'unknown', 'down'];
    const MAX_BADGES = 3;
    const KIOSK_REFRESH_SECONDS = 300;
    const el = (id) => document.getElementById(id);

    const state = {};
    let options = { types: [], os: [], groups: [], classes: [] };
    let last = null;
    let savedViews = [];
    let optionsCtl = null;
    let dataCtl = null;
    let searchTimer = null;
    let refreshTimer = null;
    let graphBox = null;

    // ---- state ----

    function splitClasses(text) {
        return String(text || '').split(',').map((s) => s.trim()).filter((s) => s !== '');
    }

    function initState() {
        const d = cfg.defaults || {};
        const i = (cfg.initial && typeof cfg.initial === 'object') ? cfg.initial : {};

        state.view = VIEWS.indexOf(cfg.defaultView) !== -1 ? cfg.defaultView : 'single';
        state.type = d.type === null || d.type === undefined ? '' : String(d.type);
        state.klass = d['class'] ? String(d['class']) : 'runtime';
        state.classes = [];
        state.os = '';
        state.group = '';
        state.q = '';
        state.sensor = '';
        state.suspect = false;
        state.attention = false;
        state.focus = '';
        state.sort = '';
        state.dir = null;
        state.limit = String(d.limit === undefined ? 25 : d.limit);
        state.aggregate = 'none';

        if (Object.keys(i).length > 0) { applyQueryObject(i); }
        state.kiosk = i.kiosk === '1';
    }

    /** Copies recognised string values from a query object (URL parameters or a saved view) into the state. */
    function applyQueryObject(q) {
        const s = (key, fallback) => (typeof q[key] === 'string' ? q[key] : fallback);
        const defaults = cfg.defaults || {};

        // Addresses and saved views from before the UPS overview have no view parameter: they are the single view.
        state.view = VIEWS.indexOf(s('view', 'single')) !== -1 ? s('view', 'single') : 'single';
        state.type = s('type', defaults.type === null || defaults.type === undefined ? '' : String(defaults.type));
        state.klass = s('class', defaults['class'] ? String(defaults['class']) : 'runtime');
        state.classes = splitClasses(s('classes', ''));
        state.os = s('os', '');
        state.group = s('group', '');
        state.q = s('q', '');
        state.sensor = s('sensor', '');
        state.suspect = s('suspect', '') === '1';
        state.attention = s('attention', '') === '1';
        state.focus = FOCUS.indexOf(s('focus', '')) !== -1 ? s('focus', '') : '';
        state.sort = s('sort', '');
        state.dir = s('dir', '') || null;
        state.limit = s('limit', String(defaults.limit === undefined ? 25 : defaults.limit));
        state.aggregate = s('aggregate', 'none');

        if (LIMITS.indexOf(state.limit) === -1) { state.limit = '25'; }
        if (['none', 'min', 'max'].indexOf(state.aggregate) === -1) { state.aggregate = 'none'; }
    }

    /**
     * Query parameters for the current state.
     * @param {string} [format] "csv" for an export link
     * @param {boolean} [forPage] include page-only parameters (kiosk), used for the address bar
     */
    function queryParams(format, forPage) {
        const p = new URLSearchParams();
        const matrix = state.view === 'matrix';
        const ups = state.view === 'ups';
        if (state.view !== 'single') { p.set('view', state.view); }
        p.set('type', state.type);
        if (matrix) {
            p.set('classes', state.classes.join(','));
            if (state.suspect) { p.set('suspect', '1'); }
        } else if (ups) {
            if (state.attention) { p.set('attention', '1'); }
            if (state.focus) { p.set('focus', state.focus); }
        } else {
            p.set('class', state.klass);
            p.set('aggregate', state.aggregate);
        }
        p.set('os', state.os);
        p.set('group', state.group);
        if (state.q) { p.set('q', state.q); }
        if (state.sensor && !ups) { p.set('sensor', state.sensor); }
        if (state.sort) { p.set('sort', state.sort); }
        if (state.dir) { p.set('dir', state.dir); }
        p.set('limit', state.limit);
        if (format) { p.set('format', format); }
        if (forPage && state.kiosk) { p.set('kiosk', '1'); }
        return p;
    }

    function query(format) { return queryParams(format, false).toString(); }

    function endpoint() {
        return state.view === 'ups' ? cfg.urls.ups : (state.view === 'matrix' ? cfg.urls.matrix : cfg.urls.data);
    }

    function dataUrl(format) {
        return endpoint() + '?' + query(format);
    }

    function exportAllUrl() {
        const p = queryParams('csv', false);
        p.set('limit', '0');
        return endpoint() + '?' + p.toString();
    }

    function updateUrl() {
        el('ub-csv').setAttribute('href', absolute(dataUrl('csv')));
        el('ub-csv-all').setAttribute('href', absolute(exportAllUrl()));
        try { history.replaceState(null, '', absolute(cfg.urls.page + '?' + queryParams(null, true).toString())); } catch (e) { /* ignore */ }
    }

    // ---- network ----

    function handleResponse(res) {
        return res.json().catch(() => ({})).then((body) => {
            if (!res.ok) { throw new Error(body.message || ('HTTP ' + res.status)); }
            return body;
        });
    }

    /**
     * LibreNMS pages carry <base href="(base_url)">, which may name another host than the one in the address bar,
     * and the browser resolves every path against it. Server paths are therefore resolved against this page's origin.
     */
    function absolute(url) {
        return url.charAt(0) === '/' && url.charAt(1) !== '/' ? window.location.origin + url : url;
    }

    function getJson(url, signal) {
        return fetch(absolute(url), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin', signal: signal }).then(handleResponse);
    }

    function postJson(url, payload) {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return fetch(absolute(url), {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': meta ? meta.getAttribute('content') : ''
            },
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        }).then(handleResponse);
    }

    function showError(message) {
        const box = el('ub-error');
        if (!message) { box.style.display = 'none'; box.textContent = ''; return; }
        box.textContent = T.error.replace(':message', message);
        box.style.display = '';
    }

    function handleFailure(err) {
        if (err && err.name === 'AbortError') { return; }
        el('ub-table').style.opacity = '';
        showError(err && err.message ? err.message : String(err));
    }

    // ---- filter controls ----

    function fill(select, items, selected) {
        select.textContent = '';
        items.forEach((item) => {
            const o = document.createElement('option');
            o.value = String(item.value);
            o.textContent = item.label;
            if (String(item.value) === String(selected)) { o.selected = true; }
            select.appendChild(o);
        });
    }

    function classValues() { return options.classes.map((c) => c.value); }

    function classMeta(value) {
        return options.classes.filter((c) => c.value === value)[0] || null;
    }

    /** The suspect battery verdict needs both the runtime and the load metric. */
    function canJudgeBattery() {
        return state.classes.indexOf('runtime') !== -1 && state.classes.indexOf('load') !== -1;
    }

    /** Makes the selected metric(s) and the sort column valid for the current options and view. */
    function ensureSelection() {
        const values = classValues();

        if (values.indexOf(state.klass) === -1) {
            const fallback = cfg.defaults && cfg.defaults['class'];
            state.klass = values.indexOf(fallback) !== -1 ? fallback : (values[0] || '');
            state.dir = null;
        }

        state.classes = state.classes.filter((c) => values.indexOf(c) !== -1).slice(0, MAX_METRICS);
        if (state.classes.length === 0) {
            state.classes = cfg.matrixDefaults.filter((c) => values.indexOf(c) !== -1);
        }
        if (state.classes.length === 0) {
            state.classes = values.slice(0, 3);
        }

        const validSorts = state.view === 'ups' ? UPS_SORTS : (state.view === 'matrix' ? ['hostname', 'location'].concat(state.classes) : SINGLE_SORTS);
        if (state.sort && validSorts.indexOf(state.sort) === -1) {
            state.sort = '';
            state.dir = null;
        }

        if (state.klass === 'state') { state.aggregate = 'none'; }
        if (!canJudgeBattery()) { state.suspect = false; }
    }

    function renderClassBoxes() {
        const box = el('ub-classes');
        box.textContent = '';
        options.classes.forEach((c) => {
            const label = document.createElement('label');
            label.className = 'checkbox-inline';
            const input = document.createElement('input');
            input.type = 'checkbox';
            input.value = c.value;
            input.checked = state.classes.indexOf(c.value) !== -1;
            input.addEventListener('change', () => onClassBoxChange(input));
            label.appendChild(input);
            label.appendChild(document.createTextNode(' ' + c.label));
            box.appendChild(label);
        });
    }

    function onClassBoxChange(changed) {
        const checked = Array.prototype.slice.call(el('ub-classes').querySelectorAll('input:checked')).map((i) => i.value);
        if (checked.length > MAX_METRICS) {
            changed.checked = false;
            window.alert(T.view.max_metrics.replace(':n', MAX_METRICS));
            return;
        }
        if (checked.length === 0) {
            changed.checked = true;
            return;
        }
        state.classes = checked;
        ensureSelection();
        syncControls();
        loadData().catch(handleFailure);
    }

    function syncControls() {
        const matrix = state.view === 'matrix';
        const ups = state.view === 'ups';
        el('ub-view-ups').checked = ups;
        el('ub-view-single').checked = state.view === 'single';
        el('ub-view-matrix').checked = matrix;
        ['ub-view-ups', 'ub-view-single', 'ub-view-matrix'].forEach((id) => {
            el(id).parentNode.classList.toggle('active', el(id).checked);
        });
        el('ub-class-group').style.display = state.view === 'single' ? '' : 'none';
        el('ub-aggregate-group').style.display = state.view === 'single' ? '' : 'none';
        el('ub-sensor-group').style.display = ups ? 'none' : '';
        el('ub-attention-group').style.display = ups ? '' : 'none';
        el('ub-attention').checked = state.attention;
        el('ub-cards').style.display = ups ? '' : 'none';
        el('ub-timeline-box').style.display = ups ? '' : 'none';
        el('ub-classes-group').style.display = matrix ? '' : 'none';
        el('ub-hint').style.display = matrix ? '' : 'none';
        el('ub-suspect-group').style.display = matrix ? '' : 'none';
        el('ub-suspect').checked = state.suspect;
        el('ub-suspect').disabled = !canJudgeBattery();
        el('ub-suspect').title = canJudgeBattery() ? '' : T.suspect.needs_metrics;
        el('ub-aggregate').value = state.aggregate;
        el('ub-aggregate').disabled = state.klass === 'state';
        el('ub-limit').value = state.limit;
        el('ub-q').value = state.q;
        el('ub-sensor').value = state.sensor;
        el('ups-battery').classList.toggle('ub-kiosk', state.kiosk);
    }

    function applyOptions(body) {
        options = body;

        if (state.type !== '' && !body.types.some((t) => t.value === state.type)) { state.type = ''; }
        if (state.os !== '' && !body.os.some((o) => o.value === state.os)) { state.os = ''; }
        if (state.group !== '' && !body.groups.some((g) => String(g.id) === state.group)) { state.group = ''; }

        fill(el('ub-type'), [{ value: '', label: T.all }].concat(body.types.map((t) => ({ value: t.value, label: t.value + ' (' + t.count + ')' }))), state.type);
        fill(el('ub-os'), [{ value: '', label: T.all }].concat(body.os.map((o) => ({ value: o.value, label: o.value + ' (' + o.count + ')' }))), state.os);
        fill(el('ub-group'), [{ value: '', label: T.all }].concat(body.groups.map((g) => ({ value: String(g.id), label: g.name }))), state.group);

        ensureSelection();
        fill(el('ub-class'), body.classes.map((c) => ({ value: c.value, label: c.label + ' (' + c.count + ')' })), state.klass);
        renderClassBoxes();
        syncControls();
    }

    function loadOptions(retry) {
        if (optionsCtl) { optionsCtl.abort(); }
        optionsCtl = new AbortController();
        const requestedType = state.type;
        return getJson(cfg.urls.options + '?type=' + encodeURIComponent(state.type), optionsCtl.signal).then((body) => {
            applyOptions(body);
            if (retry !== false && requestedType !== state.type) { return loadOptions(false); }
            return null;
        });
    }

    // ---- data ----

    function loadData() {
        updateUrl();
        const matrix = state.view === 'matrix';
        const ups = state.view === 'ups';
        if (!ups && ((matrix && state.classes.length === 0) || (!matrix && state.klass === ''))) {
            last = null;
            buildHead([{ sort: 'hostname', label: T.columns.hostname }]);
            renderEmpty(1);
            el('ub-summary').textContent = '';
            return Promise.resolve();
        }

        if (dataCtl) { dataCtl.abort(); }
        dataCtl = new AbortController();
        el('ub-table').style.opacity = '0.5';

        return getJson(dataUrl(), dataCtl.signal).then((body) => {
            last = body;
            showError(null);
            if (ups) { renderUps(body); } else if (matrix) { renderMatrix(body); } else { renderSingle(body); }
            updateHeaders();
            el('ub-table').style.opacity = '';
        });
    }

    function effectiveSort() { return (last && last.filters && last.filters.sort) || state.sort || (state.view === 'single' ? 'value' : (state.view === 'ups' ? 'status' : '')); }
    function effectiveDir() { return (last && last.filters && last.filters.dir) || state.dir || 'asc'; }

    function onSortClick(column) {
        if (effectiveSort() === column) {
            state.sort = column;
            state.dir = effectiveDir() === 'asc' ? 'desc' : 'asc';
        } else {
            state.sort = column;
            // Value columns and every UPS overview column start in the direction the server picks (worst first).
            const valueColumn = column === 'value' || state.view === 'ups' || (state.view === 'matrix' && column !== 'hostname' && column !== 'location');
            state.dir = valueColumn ? null : 'asc';
        }
        loadData().catch(handleFailure);
    }

    /** Builds the header row. A column without "sort" is not sortable. */
    function buildHead(columns) {
        const tr = el('ub-head');
        tr.textContent = '';
        columns.forEach((c) => {
            const th = document.createElement('th');
            if (c.cls) { th.className = c.cls; }
            th.appendChild(document.createTextNode(c.label));
            if (c.help) {
                th.title = c.help;
                th.classList.add('ub-help');
            }
            if (c.sort) {
                th.setAttribute('role', 'button');
                th.setAttribute('data-sort', c.sort);
                const arrow = document.createElement('span');
                arrow.className = 'ub-arrow';
                th.appendChild(arrow);
                th.addEventListener('click', () => onSortClick(c.sort));
            }
            tr.appendChild(th);
        });
    }

    function updateHeaders() {
        const sort = effectiveSort();
        const dir = effectiveDir();
        document.querySelectorAll('#ub-head th[data-sort]').forEach((th) => {
            const active = th.getAttribute('data-sort') === sort;
            th.querySelector('.ub-arrow').textContent = active ? (dir === 'asc' ? ' ▲' : ' ▼') : '';
            th.setAttribute('aria-sort', active ? (dir === 'asc' ? 'ascending' : 'descending') : 'none');
        });
    }

    // ---- rendering ----

    function fmt(n) { return String(Number(Number(n).toFixed(2))); }

    /** Same format as the server: "0 min", "45 min", "1 h 5 min", "2 d 3 h". */
    function formatMinutes(minutes) {
        const total = Math.round(minutes);
        if (total < 60) { return total + ' min'; }
        const days = Math.floor(total / 1440);
        const hours = Math.floor((total % 1440) / 60);
        const rest = total % 60;
        if (days > 0) { return days + ' d' + (hours > 0 ? ' ' + hours + ' h' : ''); }
        return hours + ' h' + (rest > 0 ? ' ' + rest + ' min' : '');
    }

    function ageMinutes(iso) {
        const t = Date.parse(iso);
        return isNaN(t) ? null : Math.max(0, Math.round((Date.now() - t) / 60000));
    }

    function ago(iso) {
        const minutes = ageMinutes(iso);
        if (minutes === null) { return ''; }
        if (minutes < 1) { return T.time.now; }
        if (minutes < 60) { return T.time.minutes.replace(':n', minutes); }
        if (minutes < 1440) { return T.time.hours.replace(':n', Math.round(minutes / 60)); }
        return T.time.days.replace(':n', Math.round(minutes / 1440));
    }

    function isStale(iso) {
        const minutes = ageMinutes(iso);
        return minutes !== null && minutes > cfg.staleMinutes;
    }

    function staleIcon(iso) {
        const icon = document.createElement('i');
        icon.className = 'fa fa-exclamation-triangle';
        icon.setAttribute('aria-hidden', 'true');
        icon.title = T.stale.replace(':minutes', ageMinutes(iso));
        return icon;
    }

    function cell(text) {
        const td = document.createElement('td');
        td.textContent = text;
        return td;
    }

    function safeHref(url) { return typeof url === 'string' && /^(https?:\/\/|\/(?!\/))/.test(url) ? absolute(url) : null; }

    function link(text, url, title) {
        const href = safeHref(url);
        if (!href) { return document.createTextNode(text); }
        const a = document.createElement('a');
        a.href = href;
        a.textContent = text;
        if (title) { a.title = title; }
        return a;
    }

    /** Small chart icon that opens the sensor's graph for the last year. */
    function trendLink(url) {
        const href = safeHref(url);
        if (!href) { return null; }
        const a = document.createElement('a');
        a.href = href;
        a.className = 'ub-trend';
        a.title = T.trend;
        const icon = document.createElement('i');
        icon.className = 'fa fa-line-chart';
        icon.setAttribute('aria-hidden', 'true');
        a.appendChild(icon);
        return a;
    }

    function fillUpdatedCell(td, iso) {
        td.textContent = '';
        td.className = '';
        td.removeAttribute('title');
        if (!iso) { return; }
        td.setAttribute('data-iso', iso);
        td.title = new Date(iso).toLocaleString();
        if (isStale(iso)) {
            td.className = 'text-warning';
            td.appendChild(staleIcon(iso));
            td.appendChild(document.createTextNode(' '));
        }
        td.appendChild(document.createTextNode(ago(iso)));
    }

    function refreshAges() {
        document.querySelectorAll('td[data-iso]').forEach((td) => fillUpdatedCell(td, td.getAttribute('data-iso')));
    }

    function severityClass(severity) {
        return severity === 'critical' ? 'danger' : (severity === 'warning' ? 'warning' : '');
    }

    function renderEmpty(columns) {
        const body = el('ub-body');
        body.textContent = '';
        const tr = document.createElement('tr');
        const td = cell(T.empty);
        td.colSpan = columns;
        td.className = 'text-center text-muted';
        tr.appendChild(td);
        body.appendChild(tr);
    }

    function renderSingle(body) {
        const meta = classMeta(state.klass);
        const valueLabel = meta ? (meta.label + (meta.unit ? ' (' + meta.unit + ')' : '')) : T.columns.value;
        buildHead([
            { sort: 'hostname', label: T.columns.hostname },
            { sort: 'location', label: T.columns.location },
            { sort: 'descr', label: T.columns.sensor },
            { sort: 'value', label: valueLabel },
            { sort: 'lastupdate', label: T.columns.updated }
        ]);

        const tbody = el('ub-body');
        tbody.textContent = '';
        if (!body.rows.length) {
            renderEmpty(5);
        }

        body.rows.forEach((r) => {
            const tr = document.createElement('tr');
            const classNames = [severityClass(r.severity)];
            if (!r.device_up) { classNames.push('text-muted'); }
            tr.className = classNames.join(' ').trim();

            const host = document.createElement('td');
            host.appendChild(link(r.display_name, r.device_url, r.hostname));
            tr.appendChild(host);

            tr.appendChild(cell(r.location || ''));

            const sensor = document.createElement('td');
            sensor.appendChild(link(r.sensor_descr, r.sensor_url, null));
            tr.appendChild(sensor);

            const value = cell(r.value_formatted);
            if (r.severity === 'critical' || r.severity === 'warning') { value.style.fontWeight = 'bold'; }
            attachGraph(value, r.graph_url, r.display_name + ' - ' + r.sensor_descr);
            const trend = trendLink(r.trend_url);
            if (trend) { value.appendChild(trend); }
            tr.appendChild(value);

            const updated = document.createElement('td');
            fillUpdatedCell(updated, r.last_updated);
            tr.appendChild(updated);

            tbody.appendChild(tr);
        });

        renderSummary(body);
    }

    function batteryCell(suspect) {
        const td = document.createElement('td');
        if (suspect === true) {
            const badge = document.createElement('span');
            badge.className = 'label label-warning';
            badge.title = T.suspect.tooltip;
            badge.textContent = T.suspect.yes;
            td.appendChild(badge);
        } else if (suspect === false) {
            td.className = 'text-muted';
            td.textContent = T.suspect.no;
        } else {
            td.className = 'text-muted';
            td.textContent = '–';
        }
        return td;
    }

    function renderMatrix(body) {
        const withBattery = body.classes.some((c) => c.value === 'runtime') && body.classes.some((c) => c.value === 'load');
        const columns = [
            { sort: 'hostname', label: T.columns.hostname },
            { sort: 'location', label: T.columns.location }
        ];
        body.classes.forEach((c) => columns.push({ sort: c.value, label: c.label + (c.unit ? ' (' + c.unit + ')' : '') }));
        if (withBattery) { columns.push({ sort: null, label: T.columns.battery }); }
        buildHead(columns);

        const tbody = el('ub-body');
        tbody.textContent = '';
        if (!body.rows.length) {
            renderEmpty(columns.length);
        }

        body.rows.forEach((r) => {
            const tr = document.createElement('tr');
            if (!r.device_up) { tr.className = 'text-muted'; }

            const host = document.createElement('td');
            host.appendChild(link(r.display_name, r.device_url, r.hostname));
            tr.appendChild(host);
            tr.appendChild(cell(r.location || ''));

            body.classes.forEach((c) => {
                const data = r.cells[c.value];
                const td = document.createElement('td');
                if (!data) {
                    td.className = 'text-muted';
                    td.textContent = '–';
                } else {
                    td.className = severityClass(data.severity);
                    if (data.severity === 'critical' || data.severity === 'warning') { td.style.fontWeight = 'bold'; }
                    if (data.last_updated && isStale(data.last_updated)) {
                        td.appendChild(staleIcon(data.last_updated));
                        td.appendChild(document.createTextNode(' '));
                    }
                    td.appendChild(link(data.value_formatted, data.sensor_url, data.sensor_descr));
                    attachGraph(td, data.graph_url, r.display_name + ' - ' + data.sensor_descr);
                }
                tr.appendChild(td);
            });

            if (withBattery) { tr.appendChild(batteryCell(r.suspect)); }

            tbody.appendChild(tr);
        });

        el('ub-summary').textContent = T.summary.showing.replace(':shown', body.rows.length).replace(':total', body.total);
    }

    function renderSummary(body) {
        const s = body.summary;
        const parts = [T.summary.showing.replace(':shown', body.rows.length).replace(':total', body.total)];
        if (s.min !== null && s.median !== null && s.max !== null) {
            const runtime = body.filters && body.filters['class'] === 'runtime';
            const show = (n) => (runtime ? formatMinutes(n) : fmt(n) + (s.unit ? ' ' + s.unit : ''));
            parts.push(T.summary.min + ' ' + show(s.min));
            parts.push(T.summary.median + ' ' + show(s.median));
            parts.push(T.summary.max + ' ' + show(s.max));
        }
        el('ub-summary').textContent = parts.join(' · ');
    }

    // ---- UPS overview ----

    /** Thin horizontal bar for a percentage (0-100), coloured by severity. */
    function bar(percent, severity, title) {
        const outer = document.createElement('div');
        outer.className = 'ub-bar' + (severity === 'critical' || severity === 'warning' ? ' ub-bar-' + severity : '');
        if (title) { outer.title = title; }
        const inner = document.createElement('span');
        inner.style.width = Math.max(0, Math.min(100, Math.round(percent))) + '%';
        outer.appendChild(inner);
        return outer;
    }

    /**
     * A value cell like in the compare view: severity colour, stale icon, link to the sensor and hover graph.
     * With `withBar` a percentage value (charge, load) also gets a bar. With `label` the cell shows that plain
     * wording instead of the vendor text, which stays in the tooltip.
     */
    function sensorCell(data, title, withBar, label) {
        const td = document.createElement('td');
        if (!data) {
            td.className = 'text-muted';
            td.textContent = '–';
            return td;
        }
        td.className = severityClass(data.severity);
        if (data.severity === 'critical' || data.severity === 'warning') { td.style.fontWeight = 'bold'; }
        if (data.last_updated && isStale(data.last_updated)) {
            td.appendChild(staleIcon(data.last_updated));
            td.appendChild(document.createTextNode(' '));
        }
        td.appendChild(label
            ? link(label, data.sensor_url, data.sensor_descr + ': ' + data.value_formatted)
            : link(data.value_formatted, data.sensor_url, data.sensor_descr));
        if (withBar && typeof data.value === 'number') { td.appendChild(bar(data.value, data.severity, null)); }
        attachGraph(td, data.graph_url, title + ' - ' + data.sensor_descr);
        return td;
    }

    /** The sentence for an issue: "Runtime low: 8 min", "Battery swap overdue by 30 days". */
    function issueText(issue) {
        let text = (T.issues && T.issues[issue.key]) || issue.key;
        if (issue.n !== null && issue.n !== undefined) { text = text.replace(':n', issue.n); }
        return text.replace(':value', issue.value === null || issue.value === undefined ? '' : issue.value);
    }

    /** Why the UPS needs attention: the most severe issues as badges, all of them in the tooltip. */
    function attentionCell(r) {
        const td = document.createElement('td');
        const issues = r.issues || [];
        if (!issues.length) {
            td.className = 'text-muted';
            td.textContent = '–';
            return td;
        }
        td.title = issues.map(issueText).join('\n');
        issues.slice(0, MAX_BADGES).forEach((issue, index) => {
            if (index > 0) { td.appendChild(document.createTextNode(' ')); }
            td.appendChild(badge(issueText(issue), issue.severity === 'critical' ? 'danger' : 'warning', null, true));
        });
        if (issues.length > MAX_BADGES) {
            td.appendChild(document.createTextNode(' '));
            const more = document.createElement('small');
            more.className = 'text-muted';
            more.textContent = T.issues.more.replace(':n', issues.length - MAX_BADGES);
            td.appendChild(more);
        }
        return td;
    }

    /** A rounded label; `soft` ones are tinted instead of solid, for secondary information. */
    function badge(text, kind, title, soft) {
        const span = document.createElement('span');
        span.className = 'label label-' + kind + ' ub-pill' + (soft ? ' ub-pill-soft' : '');
        span.textContent = text;
        if (title) { span.title = title; }
        return span;
    }

    /** Colour of each status pill; "on battery" is the only solid one, with a pulsing dot. */
    const STATUS_KINDS = { on_battery: 'danger', on_mains: 'success', bypass: 'warning', battery_test: 'info', off: 'danger', unreachable: 'default', unknown: 'default' };

    /** Where the load is powered from, in plain words; the tooltip explains it and shows the UPS's own wording. */
    function statusCell(r) {
        const td = document.createElement('td');
        const status = r.status || { key: 'unknown', detail: null };
        const help = (T.status_help && T.status_help[status.key]) || '';
        // e.g. "The load is powered from the mains; the battery is standing by. (MainsVolt 1: 230 V)"
        const title = help + (status.detail ? ' (' + status.detail + ')' : '');
        const pill = badge((T.status && T.status[status.key]) || status.key, STATUS_KINDS[status.key] || 'default', title, status.key !== 'on_battery');
        if (status.key === 'on_battery') {
            const dot = document.createElement('span');
            dot.className = 'ub-dot';
            dot.setAttribute('aria-hidden', 'true');
            pill.insertBefore(dot, pill.firstChild);
        }
        td.appendChild(pill);
        return td;
    }

    /** Battery status sensor (in plain words where known), bad battery packs and the suspect battery verdict. */
    function batteryStatusCell(r) {
        const td = sensorCell(r.battery, r.display_name, false, r.battery_label ? T.battery_state[r.battery_label] : null);
        const extra = [];
        if (r.bad_packs && r.bad_packs.value > 0) {
            extra.push(badge(T.ups.bad_packs.replace(':n', fmt(r.bad_packs.value)), 'danger', r.bad_packs.sensor_descr));
        }
        if (r.suspect === true) {
            extra.push(badge(T.suspect.yes, 'warning', T.ups.suspect));
        }
        if (extra.length && !r.battery) { td.textContent = ''; td.className = ''; }
        extra.forEach((node) => { td.appendChild(document.createTextNode(' ')); td.appendChild(node); });
        return td;
    }

    function swapText(swap) {
        if (swap.days_left === null) { return ''; }
        if (swap.days_left === 0) { return T.ups.due_today; }
        if (swap.days_left < 0) { return T.ups.overdue.replace(':n', -swap.days_left); }
        return T.ups.days_left.replace(':n', swap.days_left);
    }

    function swapSourceText(swap) {
        const text = T.ups['source_' + swap.source] || '';
        return text.replace(':months', last && last.lifetime_months ? last.lifetime_months : '');
    }

    function swapCell(swap) {
        const td = document.createElement('td');
        td.title = swapSourceText(swap);
        if (!swap.due) {
            td.className = 'text-muted';
            td.textContent = '–';
            return td;
        }
        td.className = severityClass(swap.severity);
        if (swap.severity === 'critical' || swap.severity === 'warning') { td.style.fontWeight = 'bold'; }
        td.appendChild(document.createTextNode(swap.due + ' '));
        const kind = { critical: 'danger', warning: 'warning', ok: 'success' }[swap.severity] || 'default';
        td.appendChild(badge(swapText(swap), kind, null, true));
        if (typeof swap.life_used === 'number') {
            td.appendChild(bar(swap.life_used, swap.severity, T.ups.life_used.replace(':n', swap.life_used)));
        }
        return td;
    }

    /** The swap date, with the install date (and the pencil to change it) on a small line below. */
    function swapAndInstalledCell(r, canEdit) {
        const td = swapCell(r.swap);
        const installed = r.swap.source === 'manual' || r.swap.source === 'ups_last' ? r.swap.installed : null;
        const line = document.createElement('div');
        line.className = 'ub-sub';
        line.appendChild(document.createTextNode(T.ups.col_installed + ': ' + (installed || '–')));
        if (canEdit) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-link btn-xs ub-edit';
            button.title = T.ups.set_installed;
            button.setAttribute('aria-label', T.ups.set_installed + ': ' + r.display_name);
            const icon = document.createElement('i');
            icon.className = 'fa fa-pencil';
            icon.setAttribute('aria-hidden', 'true');
            button.appendChild(icon);
            button.addEventListener('click', () => editInstalled(r));
            line.appendChild(button);
        }
        td.appendChild(line);
        return td;
    }

    function editInstalled(r) {
        const current = r.swap.source === 'manual' ? r.swap.installed : '';
        const value = window.prompt(T.ups.installed_prompt + ' ' + r.display_name, current || '');
        if (value === null) { return; }
        postJson(cfg.urls.battery, { device_id: r.device_id, installed: value.trim() })
            .then(() => loadData())
            .catch(handleFailure);
    }

    /** Order of the sensor groups in the details: the battery and power values first, plain counters last. */
    const DETAIL_ORDER = ['runtime', 'charge', 'load', 'voltage', 'current', 'power', 'frequency', 'temperature', 'state', 'count'];
    const DETAIL_SHOWN = 6;

    function isProblem(s) { return s.severity === 'critical' || s.severity === 'warning'; }

    function severityRank(severity) { return { critical: 3, warning: 2, unknown: 1 }[severity] || 0; }

    /** One "name ... value" line in the details. */
    function detailItem(r, s) {
        const li = document.createElement('li');
        li.className = 'ub-item' + (isProblem(s) ? ' ub-item-' + s.severity : '');
        const name = document.createElement('span');
        name.className = 'ub-item-name';
        name.textContent = s.sensor_descr;
        name.title = s.sensor_descr;
        const value = document.createElement('span');
        value.className = 'ub-item-value';
        value.appendChild(link(s.value_formatted, s.sensor_url, null));
        li.appendChild(name);
        li.appendChild(value);
        attachGraph(li, s.graph_url, r.display_name + ' - ' + s.sensor_descr);
        return li;
    }

    /**
     * Row below a UPS with all its sensors: the sensors with a problem on top, then one card per sensor class.
     * Within a card problems come first; long lists show the first few with "show more".
     */
    function detailsRow(r, columns) {
        const tr = document.createElement('tr');
        tr.className = 'ub-details';
        const td = document.createElement('td');
        td.colSpan = columns;

        const problems = r.sensors.filter(isProblem).sort((a, b) => severityRank(b.severity) - severityRank(a.severity));
        if (problems.length) {
            const strip = document.createElement('div');
            strip.className = 'ub-problems';
            const title = document.createElement('strong');
            title.textContent = T.ups.problems + ' (' + problems.length + ')';
            strip.appendChild(title);
            const list = document.createElement('ul');
            problems.forEach((s) => {
                const item = detailItem(r, s);
                const group = document.createElement('small');
                group.className = 'ub-item-group';
                group.textContent = s.label;
                item.insertBefore(group, item.firstChild);
                list.appendChild(item);
            });
            strip.appendChild(list);
            td.appendChild(strip);
        }

        const groups = {};
        r.sensors.forEach((s) => {
            if (!groups[s['class']]) { groups[s['class']] = { label: s.label, sensors: [] }; }
            groups[s['class']].sensors.push(s);
        });
        const rank = (cls) => { const i = DETAIL_ORDER.indexOf(cls); return i === -1 ? DETAIL_ORDER.length : i; };
        const classes = Object.keys(groups).sort((a, b) => rank(a) - rank(b) || groups[a].label.localeCompare(groups[b].label));

        const grid = document.createElement('div');
        grid.className = 'ub-detail-grid';
        classes.forEach((cls) => {
            const group = groups[cls];
            const sensors = group.sensors.slice().sort((a, b) => severityRank(b.severity) - severityRank(a.severity));
            const box = document.createElement('section');
            box.className = 'ub-group';
            const heading = document.createElement('h5');
            heading.textContent = group.label + ' ';
            const count = document.createElement('small');
            count.textContent = '(' + sensors.length + ')';
            heading.appendChild(count);
            box.appendChild(heading);

            const list = document.createElement('ul');
            sensors.forEach((s, index) => {
                const item = detailItem(r, s);
                if (index >= DETAIL_SHOWN) { item.classList.add('ub-hidden'); item.style.display = 'none'; }
                list.appendChild(item);
            });
            box.appendChild(list);

            if (sensors.length > DETAIL_SHOWN) {
                const more = document.createElement('button');
                more.type = 'button';
                more.className = 'btn btn-link btn-xs ub-more';
                const label = T.ups.more_sensors.replace(':n', sensors.length - DETAIL_SHOWN);
                more.textContent = label;
                more.addEventListener('click', () => {
                    const open = more.getAttribute('aria-expanded') !== 'true';
                    more.setAttribute('aria-expanded', open ? 'true' : 'false');
                    list.querySelectorAll('.ub-hidden').forEach((li) => { li.style.display = open ? '' : 'none'; });
                    more.textContent = open ? T.ups.less_sensors : label;
                });
                box.appendChild(more);
            }
            grid.appendChild(box);
        });
        td.appendChild(grid);
        tr.appendChild(td);
        return tr;
    }

    function toggleButton(onClick) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-link btn-xs ub-toggle';
        button.title = T.ups.details;
        button.setAttribute('aria-expanded', 'false');
        const icon = document.createElement('i');
        icon.className = 'fa fa-caret-right fa-fw';
        icon.setAttribute('aria-hidden', 'true');
        button.appendChild(icon);
        button.addEventListener('click', () => {
            const open = button.getAttribute('aria-expanded') !== 'true';
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
            icon.className = (open ? 'fa fa-caret-down' : 'fa fa-caret-right') + ' fa-fw';
            onClick(open);
        });
        return button;
    }

    /**
     * A summary card. With `focus` it is a button: a click shows only the UPSs it counts, a second click
     * (or the "UPSs" card, focus '') shows all again.
     */
    function card(value, label, tone, node, focus, icon) {
        const box = document.createElement('div');
        box.className = 'ub-card' + (tone ? ' ub-' + tone : '');
        const head = document.createElement('div');
        head.className = 'ub-card-head';
        const number = document.createElement('div');
        number.className = 'ub-card-value';
        if (node) { number.appendChild(node); } else { number.textContent = String(value); }
        head.appendChild(number);
        if (icon) {
            const i = document.createElement('i');
            i.className = 'fa fa-' + icon + ' ub-card-icon';
            i.setAttribute('aria-hidden', 'true');
            head.appendChild(i);
        }
        const text = document.createElement('div');
        text.className = 'ub-card-label';
        text.textContent = label;
        box.appendChild(head);
        box.appendChild(text);

        if (focus !== undefined) {
            const active = state.focus === focus && focus !== '';
            box.classList.add('ub-card-button');
            if (active) { box.classList.add('ub-active'); }
            box.setAttribute('role', 'button');
            box.setAttribute('tabindex', '0');
            box.setAttribute('data-focus', focus);
            box.setAttribute('aria-pressed', active ? 'true' : 'false');
            box.title = active || focus === '' ? T.ups.card_clear : T.ups.card_filter;
            const choose = () => {
                state.focus = state.focus === focus ? '' : focus;
                loadData().catch(handleFailure);
            };
            box.addEventListener('click', choose);
            box.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); choose(); } });
        }
        return box;
    }

    function renderCards(body) {
        const c = body.cards;
        const box = el('ub-cards');
        box.textContent = '';
        box.appendChild(card(c.devices, T.ups.card_devices, '', null, '', 'server'));
        box.appendChild(card(c.on_battery, T.ups.card_on_battery, c.on_battery > 0 ? 'danger' : '', null, 'on_battery', 'bolt'));
        box.appendChild(card(c.swap_overdue, T.ups.card_overdue, c.swap_overdue > 0 ? 'danger' : '', null, 'overdue', 'exclamation-circle'));
        box.appendChild(card(c.swap_due, focusLabel('due', body), c.swap_due > 0 ? 'warn' : '', null, 'due', 'calendar'));
        box.appendChild(card(c.battery_alarm, T.ups.card_alarm, c.battery_alarm > 0 ? 'warn' : '', null, 'alarm', 'heartbeat'));
        box.appendChild(card(c.down || 0, T.ups.card_down, c.down > 0 ? 'warn' : '', null, 'down', 'chain-broken'));
        box.appendChild(card(c.swap_unknown, T.ups.card_unknown, '', null, 'unknown', 'question-circle'));
        if (c.lowest_runtime) {
            const lowest = card(null, T.ups.card_lowest + ': ' + c.lowest_runtime.display_name, '', link(c.lowest_runtime.value_formatted, c.lowest_runtime.device_url, c.lowest_runtime.display_name), undefined, 'clock-o');
            box.appendChild(lowest);
        }
        renderTimeline(c.swap_timeline || [], body.warn_days);
    }

    /** Label of the summary card for a card filter. */
    function focusLabel(focus, body) {
        const labels = {
            on_battery: T.ups.card_on_battery,
            overdue: T.ups.card_overdue,
            due: T.ups.card_due.replace(':days', body.warn_days),
            alarm: T.ups.card_alarm,
            down: T.ups.card_down,
            unknown: T.ups.card_unknown
        };
        return labels[focus] || focus;
    }

    /** "Showing x of y", plus the active card filter with a button to clear it. */
    function renderUpsSummary(body) {
        const summary = el('ub-summary');
        summary.textContent = T.summary.showing.replace(':shown', body.rows.length).replace(':total', body.total);
        if (!state.focus) { return; }

        const chip = document.createElement('span');
        chip.className = 'ub-focus';
        const label = document.createElement('span');
        label.className = 'label label-primary';
        label.textContent = focusLabel(state.focus, body);
        const clear = document.createElement('button');
        clear.type = 'button';
        clear.className = 'btn btn-link btn-xs ub-focus-clear';
        clear.title = T.ups.card_clear;
        clear.setAttribute('aria-label', T.ups.card_clear);
        clear.textContent = '×';
        clear.addEventListener('click', () => { state.focus = ''; loadData().catch(handleFailure); });
        chip.appendChild(label);
        chip.appendChild(clear);
        summary.appendChild(chip);
    }

    /** Short month name in the browser's language, with the year for January and the first month. */
    function monthLabel(key, first) {
        const parts = key.split('-');
        const date = new Date(Number(parts[0]), Number(parts[1]) - 1, 1);
        const name = date.toLocaleDateString(document.documentElement.lang || undefined, { month: 'short' });
        return first || parts[1] === '01' ? name + ' ' + parts[0] : name;
    }

    /**
     * Bars for the battery swaps due in each of the next 12 months, to plan battery orders.
     * Months that start inside the warning window are drawn in orange.
     */
    function renderTimeline(months, warnDays) {
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const box = el('ub-timeline');
        box.textContent = '';
        const max = months.reduce((m, item) => Math.max(m, item.count), 0);
        el('ub-timeline-empty').style.display = max === 0 ? '' : 'none';
        box.style.display = max === 0 ? 'none' : '';

        months.forEach((item, index) => {
            const col = document.createElement('div');
            const parts = item.month.split('-');
            const daysUntil = (new Date(Number(parts[0]), Number(parts[1]) - 1, 1) - today) / 86400000;
            col.className = 'ub-tl-col' + (item.count === 0 ? ' ub-tl-empty' : (daysUntil <= (warnDays || 0) ? ' ub-tl-soon' : ''));
            col.title = monthLabel(item.month, true) + ': ' + item.count;
            const count = document.createElement('div');
            count.className = 'ub-tl-count';
            count.textContent = item.count > 0 ? String(item.count) : '';
            const barBox = document.createElement('div');
            barBox.className = 'ub-tl-bar-box';
            const fill = document.createElement('div');
            fill.className = 'ub-tl-bar';
            fill.style.height = (max > 0 ? Math.round(item.count / max * 100) : 0) + '%';
            barBox.appendChild(fill);
            const label = document.createElement('div');
            label.className = 'ub-tl-label';
            label.textContent = monthLabel(item.month, index === 0);
            col.appendChild(count);
            col.appendChild(barBox);
            col.appendChild(label);
            box.appendChild(col);
        });
    }

    function renderUps(body) {
        const columns = [
            { sort: null, label: '' },
            { sort: 'hostname', label: T.columns.hostname },
            { sort: 'status', label: T.ups.col_status, help: T.column_help.status },
            { sort: null, label: T.ups.col_attention, help: T.column_help.attention },
            { sort: 'runtime', label: T.ups.col_runtime, cls: 'ub-num', help: T.column_help.runtime },
            { sort: 'charge', label: T.ups.col_charge, cls: 'ub-num', help: T.column_help.charge },
            { sort: 'load', label: T.ups.col_load, cls: 'ub-num', help: T.column_help.load },
            { sort: 'temperature', label: T.ups.col_temperature, cls: 'ub-num', help: T.column_help.temperature },
            { sort: null, label: T.ups.col_battery, help: T.column_help.battery },
            { sort: null, label: T.ups.col_self_test, help: T.column_help.self_test },
            { sort: 'swap', label: T.ups.col_swap, help: T.column_help.swap }
        ];
        buildHead(columns);
        renderCards(body);

        const tbody = el('ub-body');
        tbody.textContent = '';
        if (!body.rows.length) {
            renderEmpty(columns.length);
            tbody.firstChild.firstChild.textContent = T.ups.empty;
        }

        body.rows.forEach((r) => {
            const tr = document.createElement('tr');
            // A coloured stripe instead of a coloured row: the cells with the problem keep their colour and stay readable.
            const classNames = ['ub-sev-' + r.severity];
            if (!r.device_up) { classNames.push('text-muted'); }
            tr.className = classNames.join(' ');

            let details = null;
            const toggle = document.createElement('td');
            toggle.appendChild(toggleButton((open) => {
                if (open) {
                    details = detailsRow(r, columns.length);
                    tr.parentNode.insertBefore(details, tr.nextSibling);
                } else if (details) {
                    details.remove();
                    details = null;
                }
            }));
            tr.appendChild(toggle);

            // Hostname with the location below it, to keep the table narrow.
            const host = document.createElement('td');
            host.className = 'ub-host';
            host.appendChild(link(r.display_name, r.device_url, r.hostname));
            if (r.location) {
                const location = document.createElement('small');
                location.textContent = r.location;
                host.appendChild(location);
            }
            tr.appendChild(host);
            tr.appendChild(statusCell(r));
            tr.appendChild(attentionCell(r));
            [sensorCell(r.runtime, r.display_name), sensorCell(r.charge, r.display_name, true),
                sensorCell(r.load, r.display_name, true), sensorCell(r.temperature, r.display_name)].forEach((td) => {
                td.classList.add('ub-num');
                tr.appendChild(td);
            });
            tr.appendChild(batteryStatusCell(r));
            tr.appendChild(sensorCell(r.self_test, r.display_name, false, r.self_test_label ? T.self_test_state[r.self_test_label] : null));
            tr.appendChild(swapAndInstalledCell(r, body.can_edit === true));

            tbody.appendChild(tr);
        });

        renderUpsSummary(body);
    }

    // ---- hover graph ----

    function moveGraph(e) {
        if (!graphBox) { return; }
        let left = e.clientX + 16;
        let top = e.clientY + 16;
        if (left + 380 > window.innerWidth) { left = Math.max(0, e.clientX - 396); }
        if (top + 200 > window.innerHeight) { top = Math.max(0, e.clientY - 216); }
        graphBox.style.left = left + 'px';
        graphBox.style.top = top + 'px';
    }

    function showGraph(url, title, e) {
        if (!graphBox) {
            graphBox = document.createElement('div');
            graphBox.style.cssText = 'position:fixed;z-index:2000;background:#fff;color:#333;border:1px solid #ccc;padding:4px;box-shadow:0 2px 8px rgba(0,0,0,.3);pointer-events:none;display:none;';
            const caption = document.createElement('div');
            caption.className = 'small';
            const img = document.createElement('img');
            img.alt = '';
            graphBox.appendChild(caption);
            graphBox.appendChild(img);
            document.body.appendChild(graphBox);
        }
        graphBox.firstChild.textContent = title + ' - ' + T.graph;
        graphBox.lastChild.src = url;
        graphBox.style.display = 'block';
        moveGraph(e);
    }

    function hideGraph() {
        if (graphBox) { graphBox.style.display = 'none'; }
    }

    function attachGraph(node, url, title) {
        const href = safeHref(url);
        if (!href) { return; }
        node.addEventListener('mouseenter', (e) => showGraph(href, title, e));
        node.addEventListener('mousemove', moveGraph);
        node.addEventListener('mouseleave', hideGraph);
    }

    // ---- saved views ----

    function fillViews(selectedName) {
        const select = el('ub-views');
        select.textContent = '';
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = T.views.choose;
        select.appendChild(placeholder);
        savedViews.forEach((v, index) => {
            const o = document.createElement('option');
            o.value = String(index);
            o.textContent = v.name;
            if (v.name === selectedName) { o.selected = true; }
            select.appendChild(o);
        });
        el('ub-view-delete').disabled = select.value === '';
    }

    function loadViews() {
        return getJson(cfg.urls.views).then((body) => {
            savedViews = body.views || [];
            fillViews(null);
        });
    }

    function onViewSelected() {
        const index = el('ub-views').value;
        el('ub-view-delete').disabled = index === '';
        if (index === '') { return; }

        const view = savedViews[Number(index)];
        if (!view) { return; }

        applyQueryObject(view.query);
        syncControls();
        loadOptions().then(loadData).catch(handleFailure);
    }

    function onViewSave() {
        const name = window.prompt(T.views.prompt, '');
        if (!name || !name.trim()) { return; }

        // The kiosk flag is a way of showing the page, not part of a view.
        const payload = {};
        queryParams(null, false).forEach((value, key) => { payload[key] = value; });

        postJson(cfg.urls.saveView, { name: name.trim(), query: payload }).then((body) => {
            savedViews = body.views || [];
            fillViews(name.trim());
            showError(null);
        }).catch(handleFailure);
    }

    function onViewDelete() {
        const view = savedViews[Number(el('ub-views').value)];
        if (!view || !window.confirm(T.views.confirm_delete.replace(':name', view.name))) { return; }

        postJson(cfg.urls.deleteView, { name: view.name }).then((body) => {
            savedViews = body.views || [];
            fillViews(null);
        }).catch(handleFailure);
    }

    // ---- auto refresh and kiosk ----

    /** Seconds between refreshes: the plugin setting, or five minutes in the kiosk view when it is off. */
    function refreshInterval() {
        if (cfg.refreshSeconds > 0) { return cfg.refreshSeconds; }
        return state.kiosk ? KIOSK_REFRESH_SECONDS : 0;
    }

    function setupRefresh() {
        if (refreshTimer) { clearInterval(refreshTimer); refreshTimer = null; }
        const seconds = refreshInterval();
        if (seconds > 0 && (el('ub-refresh').checked || state.kiosk)) {
            refreshTimer = setInterval(() => {
                if (!document.hidden) { loadData().catch(handleFailure); }
            }, seconds * 1000);
        }
    }

    function setKiosk(on) {
        state.kiosk = on;
        el('ups-battery').classList.toggle('ub-kiosk', on);
        updateUrl();
        setupRefresh();
    }

    // ---- wiring ----

    function bind() {
        const onViewMode = (mode) => {
            if (state.view === mode) { return; }
            state.view = mode;
            state.sort = '';
            state.dir = null;
            ensureSelection();
            renderClassBoxes();
            syncControls();
            loadData().catch(handleFailure);
        };
        el('ub-view-ups').addEventListener('change', () => onViewMode('ups'));
        el('ub-view-single').addEventListener('change', () => onViewMode('single'));
        el('ub-view-matrix').addEventListener('change', () => onViewMode('matrix'));

        el('ub-type').addEventListener('change', (e) => {
            state.type = e.target.value;
            state.os = '';
            loadOptions().then(loadData).catch(handleFailure);
        });
        el('ub-class').addEventListener('change', (e) => {
            state.klass = e.target.value;
            state.dir = null;
            ensureSelection();
            syncControls();
            loadData().catch(handleFailure);
        });
        el('ub-os').addEventListener('change', (e) => { state.os = e.target.value; loadData().catch(handleFailure); });
        el('ub-group').addEventListener('change', (e) => { state.group = e.target.value; loadData().catch(handleFailure); });
        el('ub-aggregate').addEventListener('change', (e) => { state.aggregate = e.target.value; loadData().catch(handleFailure); });
        el('ub-limit').addEventListener('change', (e) => { state.limit = e.target.value; loadData().catch(handleFailure); });
        el('ub-suspect').addEventListener('change', (e) => { state.suspect = e.target.checked; loadData().catch(handleFailure); });
        el('ub-attention').addEventListener('change', (e) => { state.attention = e.target.checked; loadData().catch(handleFailure); });

        const onText = (id, key) => {
            el(id).addEventListener('input', (e) => {
                const value = e.target.value.trim();
                clearTimeout(searchTimer);
                searchTimer = setTimeout(() => { state[key] = value; loadData().catch(handleFailure); }, 300);
            });
        };
        onText('ub-q', 'q');
        onText('ub-sensor', 'sensor');

        el('ub-refresh').addEventListener('change', setupRefresh);

        el('ub-kiosk').addEventListener('click', () => setKiosk(true));
        el('ub-kiosk-exit').addEventListener('click', () => setKiosk(false));
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && state.kiosk) { setKiosk(false); } });

        el('ub-views').addEventListener('change', onViewSelected);
        el('ub-view-save').addEventListener('click', onViewSave);
        el('ub-view-delete').addEventListener('click', onViewDelete);
    }

    function init() {
        initState();

        fill(el('ub-aggregate'), ['none', 'min', 'max'].map((v) => ({ value: v, label: T.aggregate[v] })), state.aggregate);
        fill(el('ub-limit'), LIMITS.map((v) => ({ value: v, label: v === '0' ? T.all : v })), state.limit);
        syncControls();

        if (cfg.refreshSeconds > 0) {
            el('ub-refresh').checked = true;
        } else {
            el('ub-refresh-group').style.display = 'none';
        }

        bind();
        setInterval(refreshAges, 30000);

        loadViews().catch(() => { /* saved views are optional */ });
        loadOptions().then(loadData).then(setupRefresh).catch(handleFailure);
    }

    init();
})();
