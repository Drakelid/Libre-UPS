@extends('layouts.librenmsv1')

@php
    $t = fn (string $key) => trans('ups-battery::ups-battery.'.$key, [], $locale);
    $url = fn (string $route) => \Drakelid\UpsBattery\Report\Urls::relative(route($route));

    $config = [
        'defaults' => $defaults,
        'initial' => $initial,
        'matrixDefaults' => $matrixDefaults,
        'refreshSeconds' => $refreshSeconds,
        'staleMinutes' => $staleMinutes,
        // Relative URLs keep the page working behind proxies / under a different host name or sub-directory.
        'urls' => [
            'page' => $url('ups-battery.report'),
            'data' => $url('ups-battery.data'),
            'matrix' => $url('ups-battery.matrix'),
            'options' => $url('ups-battery.options'),
            'views' => $url('ups-battery.views'),
            'saveView' => $url('ups-battery.views.save'),
            'deleteView' => $url('ups-battery.views.delete'),
        ],
        'i18n' => trans('ups-battery::ups-battery', [], $locale),
    ];
@endphp

@section('title', $t('title'))

@section('content')
<div class="container-fluid" id="ups-battery">
    <div class="panel panel-default">
        <div class="panel-heading">
            <h3 class="panel-title"><i class="fa fa-battery-half fa-fw" aria-hidden="true"></i> {{ $t('title') }}</h3>
        </div>
        <div class="panel-body">
            <form class="form-inline" id="ub-filters" onsubmit="return false;">
                <div class="form-group">
                    <label class="radio-inline"><input type="radio" name="ub-view" id="ub-view-single" value="single"> {{ $t('view.single') }}</label>
                    <label class="radio-inline"><input type="radio" name="ub-view" id="ub-view-matrix" value="matrix"> {{ $t('view.matrix') }}</label>
                </div>
                <div class="form-group">
                    <label for="ub-type">{{ $t('filters.type') }}</label>
                    <select id="ub-type" class="form-control input-sm"></select>
                </div>
                <div class="form-group" id="ub-class-group">
                    <label for="ub-class">{{ $t('filters.metric') }}</label>
                    <select id="ub-class" class="form-control input-sm"></select>
                </div>
                <div class="form-group" id="ub-classes-group" style="display: none;">
                    <label>{{ $t('filters.metrics') }}</label>
                    <span id="ub-classes"></span>
                </div>
                <div class="form-group">
                    <label for="ub-os">{{ $t('filters.os') }}</label>
                    <select id="ub-os" class="form-control input-sm"></select>
                </div>
                <div class="form-group">
                    <label for="ub-group">{{ $t('filters.group') }}</label>
                    <select id="ub-group" class="form-control input-sm"></select>
                </div>
                <div class="form-group" id="ub-aggregate-group">
                    <label for="ub-aggregate">{{ $t('filters.aggregate') }}</label>
                    <select id="ub-aggregate" class="form-control input-sm"></select>
                </div>
                <div class="form-group">
                    <label for="ub-limit">{{ $t('filters.limit') }}</label>
                    <select id="ub-limit" class="form-control input-sm"></select>
                </div>
                <div class="form-group">
                    <label for="ub-q" class="sr-only">{{ $t('filters.search') }}</label>
                    <input type="search" id="ub-q" class="form-control input-sm" maxlength="100"
                           placeholder="{{ $t('filters.search_placeholder') }}">
                </div>
                <div class="form-group" id="ub-refresh-group">
                    <label class="checkbox-inline"><input type="checkbox" id="ub-refresh"> {{ $t('filters.refresh') }}</label>
                </div>
                <a id="ub-csv" class="btn btn-default btn-sm" href="#">
                    <i class="fa fa-download fa-fw" aria-hidden="true"></i> {{ $t('filters.export') }}
                </a>
            </form>
            <form class="form-inline" id="ub-views-form" style="margin-top: 10px;" onsubmit="return false;">
                <div class="form-group">
                    <label for="ub-views" class="sr-only">{{ $t('views.choose') }}</label>
                    <select id="ub-views" class="form-control input-sm"></select>
                </div>
                <button type="button" id="ub-view-save" class="btn btn-default btn-sm">
                    <i class="fa fa-save fa-fw" aria-hidden="true"></i> {{ $t('views.save') }}
                </button>
                <button type="button" id="ub-view-delete" class="btn btn-default btn-sm" disabled>
                    <i class="fa fa-trash fa-fw" aria-hidden="true"></i> {{ $t('views.delete') }}
                </button>
            </form>
        </div>
    </div>

    <div id="ub-error" class="alert alert-danger" style="display: none;" role="alert"></div>
    <p id="ub-hint" class="text-muted" style="display: none;">{{ $t('view.matrix_hint') }}</p>
    <p id="ub-summary" class="text-muted" aria-live="polite"></p>

    <div class="table-responsive">
        <table id="ub-table" class="table table-condensed table-hover">
            <thead>
                <tr id="ub-head"></tr>
            </thead>
            <tbody id="ub-body"></tbody>
        </table>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    'use strict';

    const cfg = @json($config);
    const T = cfg.i18n;
    const LIMITS = ['10', '25', '50', '100', '0'];
    const MAX_METRICS = 6;
    const SINGLE_SORTS = ['hostname', 'location', 'descr', 'value', 'lastupdate'];
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
        const hasKeys = Object.keys(i).length > 0;

        state.type = '';
        state.klass = d['class'] ? String(d['class']) : 'runtime';
        state.classes = [];
        state.os = '';
        state.group = '';
        state.q = '';
        state.sort = '';
        state.dir = null;
        state.limit = String(d.limit === undefined ? 25 : d.limit);
        state.aggregate = 'none';
        state.view = 'single';
        state.type = d.type === null || d.type === undefined ? '' : String(d.type);

        if (hasKeys) { applyQueryObject(i); }
    }

    /** Copies recognised string values from a query object (URL parameters or a saved view) into the state. */
    function applyQueryObject(q) {
        const s = (key, fallback) => (typeof q[key] === 'string' ? q[key] : fallback);
        const defaults = cfg.defaults || {};

        state.view = s('view', 'single') === 'matrix' ? 'matrix' : 'single';
        state.type = s('type', defaults.type === null || defaults.type === undefined ? '' : String(defaults.type));
        state.klass = s('class', defaults['class'] ? String(defaults['class']) : 'runtime');
        state.classes = splitClasses(s('classes', ''));
        state.os = s('os', '');
        state.group = s('group', '');
        state.q = s('q', '');
        state.sort = s('sort', '');
        state.dir = s('dir', '') || null;
        state.limit = s('limit', String(defaults.limit === undefined ? 25 : defaults.limit));
        state.aggregate = s('aggregate', 'none');

        if (LIMITS.indexOf(state.limit) === -1) { state.limit = '25'; }
        if (['none', 'min', 'max'].indexOf(state.aggregate) === -1) { state.aggregate = 'none'; }
    }

    function queryParams(format) {
        const p = new URLSearchParams();
        const matrix = state.view === 'matrix';
        if (matrix) { p.set('view', 'matrix'); }
        p.set('type', state.type);
        if (matrix) {
            p.set('classes', state.classes.join(','));
        } else {
            p.set('class', state.klass);
            p.set('aggregate', state.aggregate);
        }
        p.set('os', state.os);
        p.set('group', state.group);
        if (state.q) { p.set('q', state.q); }
        if (state.sort) { p.set('sort', state.sort); }
        if (state.dir) { p.set('dir', state.dir); }
        p.set('limit', state.limit);
        if (format) { p.set('format', format); }
        return p;
    }

    function query(format) { return queryParams(format).toString(); }

    function dataUrl(format) {
        return (state.view === 'matrix' ? cfg.urls.matrix : cfg.urls.data) + '?' + query(format);
    }

    function updateUrl() {
        el('ub-csv').setAttribute('href', dataUrl('csv'));
        try { history.replaceState(null, '', cfg.urls.page + '?' + query()); } catch (e) { /* ignore */ }
    }

    // ---- network ----

    function handleResponse(res) {
        return res.json().catch(() => ({})).then((body) => {
            if (!res.ok) { throw new Error(body.message || ('HTTP ' + res.status)); }
            return body;
        });
    }

    function getJson(url, signal) {
        return fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin', signal: signal }).then(handleResponse);
    }

    function postJson(url, payload) {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return fetch(url, {
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

        const validSorts = state.view === 'matrix' ? ['hostname', 'location'].concat(state.classes) : SINGLE_SORTS;
        if (state.sort && validSorts.indexOf(state.sort) === -1) {
            state.sort = '';
            state.dir = null;
        }

        if (state.klass === 'state') { state.aggregate = 'none'; }
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
        loadData().catch(handleFailure);
    }

    function syncControls() {
        el('ub-view-single').checked = state.view === 'single';
        el('ub-view-matrix').checked = state.view === 'matrix';
        el('ub-class-group').style.display = state.view === 'single' ? '' : 'none';
        el('ub-aggregate-group').style.display = state.view === 'single' ? '' : 'none';
        el('ub-classes-group').style.display = state.view === 'matrix' ? '' : 'none';
        el('ub-hint').style.display = state.view === 'matrix' ? '' : 'none';
        el('ub-aggregate').value = state.aggregate;
        el('ub-aggregate').disabled = state.klass === 'state';
        el('ub-limit').value = state.limit;
        el('ub-q').value = state.q;
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
        if ((matrix && state.classes.length === 0) || (!matrix && state.klass === '')) {
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
            if (matrix) { renderMatrix(body); } else { renderSingle(body); }
            updateHeaders();
            el('ub-table').style.opacity = '';
        });
    }

    function effectiveSort() { return (last && last.filters && last.filters.sort) || state.sort || (state.view === 'single' ? 'value' : ''); }
    function effectiveDir() { return (last && last.filters && last.filters.dir) || state.dir || 'asc'; }

    function onSortClick(column) {
        if (effectiveSort() === column) {
            state.sort = column;
            state.dir = effectiveDir() === 'asc' ? 'desc' : 'asc';
        } else {
            state.sort = column;
            const valueColumn = column === 'value' || (state.view === 'matrix' && column !== 'hostname' && column !== 'location');
            state.dir = valueColumn ? null : 'asc';
        }
        loadData().catch(handleFailure);
    }

    function buildHead(columns) {
        const tr = el('ub-head');
        tr.textContent = '';
        columns.forEach((c) => {
            const th = document.createElement('th');
            th.setAttribute('role', 'button');
            th.setAttribute('data-sort', c.sort);
            th.appendChild(document.createTextNode(c.label));
            const arrow = document.createElement('span');
            arrow.className = 'ub-arrow';
            th.appendChild(arrow);
            th.addEventListener('click', () => onSortClick(c.sort));
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

    function safeHref(url) { return typeof url === 'string' && /^(https?:\/\/|\/)/.test(url) ? url : null; }

    function link(text, url, title) {
        const href = safeHref(url);
        if (!href) { return document.createTextNode(text); }
        const a = document.createElement('a');
        a.href = href;
        a.textContent = text;
        if (title) { a.title = title; }
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
            tr.appendChild(value);

            const updated = document.createElement('td');
            fillUpdatedCell(updated, r.last_updated);
            tr.appendChild(updated);

            tbody.appendChild(tr);
        });

        renderSummary(body);
    }

    function renderMatrix(body) {
        const columns = [
            { sort: 'hostname', label: T.columns.hostname },
            { sort: 'location', label: T.columns.location }
        ];
        body.classes.forEach((c) => columns.push({ sort: c.value, label: c.label + (c.unit ? ' (' + c.unit + ')' : '') }));
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

            tbody.appendChild(tr);
        });

        el('ub-summary').textContent = T.summary.showing.replace(':shown', body.rows.length).replace(':total', body.total);
    }

    function renderSummary(body) {
        const s = body.summary;
        const parts = [T.summary.showing.replace(':shown', body.rows.length).replace(':total', body.total)];
        if (s.min !== null && s.median !== null && s.max !== null) {
            const unit = s.unit ? ' ' + s.unit : '';
            parts.push(T.summary.min + ' ' + fmt(s.min) + unit);
            parts.push(T.summary.median + ' ' + fmt(s.median) + unit);
            parts.push(T.summary.max + ' ' + fmt(s.max) + unit);
        }
        el('ub-summary').textContent = parts.join(' · ');
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

        const payload = {};
        queryParams().forEach((value, key) => { payload[key] = value; });

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

    // ---- auto refresh ----

    function setupRefresh() {
        if (refreshTimer) { clearInterval(refreshTimer); refreshTimer = null; }
        if (cfg.refreshSeconds > 0 && el('ub-refresh').checked) {
            refreshTimer = setInterval(() => {
                if (!document.hidden) { loadData().catch(handleFailure); }
            }, cfg.refreshSeconds * 1000);
        }
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
        el('ub-q').addEventListener('input', (e) => {
            const value = e.target.value.trim();
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => { state.q = value; loadData().catch(handleFailure); }, 300);
        });
        el('ub-refresh').addEventListener('change', setupRefresh);

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
</script>
@endpush
