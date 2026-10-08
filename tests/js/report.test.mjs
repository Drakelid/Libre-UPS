/*
 * Runs resources/js/report.js against the real page markup (resources/views/report.blade.php, with the
 * Blade expressions blanked out) in jsdom, with fetch mocked. Run with: npm test
 */
import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const script = readFileSync(path.join(root, 'resources/js/report.js'), 'utf8');
const view = readFileSync(path.join(root, 'resources/views/report.blade.php'), 'utf8');

const ORIGIN = 'https://librenms.test';
const PAGE = '/plugin/ups-battery/report';

/** Translation stub: every text the script reads is its own dotted key, so the script never hits a missing text. */
function stubTexts() {
    // The error text has a placeholder for the message, like the real translation.
    const texts = { aggregate: { none: 'aggregate.none', min: 'aggregate.min', max: 'aggregate.max' }, error: 'error: :message' };
    for (const match of script.matchAll(/\bT\.([a-z_]+)(?:\.([a-z_]+))?/g)) {
        const [, group, key] = match;
        // "T.error.replace(...)" reads a text, "replace" is the string method and not a key
        if (key === undefined || key === 'replace') {
            texts[group] ??= group; // keeps a text that was set above
        } else {
            if (typeof texts[group] !== 'object') { texts[group] = {}; }
            texts[group][key] = `${group}.${key}`;
        }
    }
    return texts;
}

/** The page body as the browser gets it: the content section of the view without Blade expressions. */
function pageHtml(config) {
    const content = view.split("@section('content')")[1].split('@endsection')[0];
    const json = JSON.stringify(config).replace(/</g, '\\u003c');

    return `<!doctype html><html><head><base href="https://base-url.example/"><meta name="csrf-token" content="token-123"></head><body>${content
        .replace(/\{\{--[\s\S]*?--\}\}/g, '')
        .replace(/<script type="application\/json" id="ub-config">[\s\S]*?<\/script>/, `<script type="application/json" id="ub-config">${json}</script>`)
        .replace(/\{\{[\s\S]*?\}\}/g, 'text')}</body></html>`;
}

const CLASSES = [
    { value: 'runtime', label: 'Runtime', unit: 'Min', count: 3 },
    { value: 'load', label: 'Load', unit: '%', count: 3 },
    { value: 'charge', label: 'Charge', unit: '%', count: 3 },
    { value: 'state', label: 'State', unit: '', count: 1 },
];

const row = (overrides = {}) => ({
    device_id: 1, hostname: 'ups-a', display_name: 'UPS A', device_url: '/device/1', location: 'Site A', os: 'apc',
    device_up: true, sensor_id: 11, sensor_descr: 'Battery runtime', sensor_url: '/device/1/health/metric=runtime',
    graph_url: '/graph?id=11', trend_url: '/graphs/type=sensor_runtime/id=11/from=-1y', value: 4, value_formatted: '4 min',
    unit: 'Min', severity: 'critical', limits: {}, last_updated: new Date(Date.now() - 3 * 60000).toISOString(), ...overrides,
});

const DEFAULT_ROUTES = {
    '/plugin/ups-battery/options': { types: [{ value: 'power', count: 3 }], os: [{ value: 'apc', count: 3 }], groups: [{ id: 1, name: 'Group 1' }], classes: CLASSES },
    // Like the real endpoint, it echoes the sort that was asked for.
    '/plugin/ups-battery/data': (url) => ({
        filters: { class: 'runtime', sort: url.searchParams.get('sort') || 'value', dir: url.searchParams.get('dir') || 'asc' },
        summary: { count: 2, min: 4, median: 22, max: 61, unit: 'Min' },
        total: 2,
        rows: [row(), row({ device_id: 2, hostname: 'ups-b', display_name: 'UPS B', sensor_id: 12, value: 61, value_formatted: '1 h 1 min', severity: 'ok' })],
    }),
    '/plugin/ups-battery/matrix': () => ({
        filters: { classes: ['runtime', 'load', 'charge'], sort: 'runtime', dir: 'asc' },
        classes: CLASSES.slice(0, 3),
        total: 3,
        rows: [
            { device_id: 1, hostname: 'ups-a', display_name: 'UPS A', device_url: '/device/1', location: 'Site A', os: 'apc', device_up: true, suspect: true, cells: { runtime: cell('4 min', 'critical'), load: cell('10 %', 'ok'), charge: cell('100 %', 'ok') } },
            { device_id: 2, hostname: 'ups-b', display_name: 'UPS B', device_url: '/device/2', location: null, os: 'apc', device_up: true, suspect: false, cells: { runtime: cell('40 min', 'ok'), load: cell('10 %', 'ok'), charge: null } },
            { device_id: 3, hostname: 'ups-c', display_name: 'UPS C', device_url: '/device/3', location: null, os: 'apc', device_up: false, suspect: null, cells: { runtime: cell('5 min', 'warning') } },
        ],
    }),
    '/plugin/ups-battery/views': { views: [] },
    '/plugin/ups-battery/ups': (url) => ({
        filters: { sort: url.searchParams.get('sort') || 'status', dir: url.searchParams.get('dir') || 'desc' },
        total: 2,
        warn_days: 90,
        lifetime_months: 48,
        can_edit: true,
        cards: {
            devices: 2, on_battery: 1, swap_overdue: 1, swap_due: 0, swap_unknown: 1, battery_alarm: 1, down: 0,
            lowest_runtime: { display_name: 'UPS A', device_url: '/device/1', value_formatted: '4 min' },
            swap_timeline: ['2026-10', '2026-11', '2026-12', '2027-01', '2027-02', '2027-03', '2027-04', '2027-05', '2027-06', '2027-07', '2027-08', '2027-09']
                .map((month, i) => ({ month, count: i === 2 ? 3 : (i === 5 ? 1 : 0) })),
        },
        rows: [
            upsRow({
                severity: 'critical', on_battery: true, suspect: true,
                output: cell('onBattery', 'warning'),
                bad_packs: { ...cell('1', 'critical'), value: 1 },
                swap: { installed: '2020-01-01', due: '2024-01-01', days_left: -1011, source: 'manual', severity: 'critical', life_used: 169 },
                issues: [
                    { key: 'on_battery', severity: 'critical', n: null },
                    { key: 'bad_packs', severity: 'critical', n: 1 },
                    { key: 'swap_overdue', severity: 'critical', n: 1011 },
                    { key: 'runtime', severity: 'critical', n: null },
                    { key: 'suspect', severity: 'warning', n: null },
                ],
            }),
            upsRow({
                device_id: 2, hostname: 'ups-b', display_name: 'UPS B', device_url: '/device/2', severity: 'ok', on_battery: false,
                runtime: cell('40 min', 'ok'), output: cell('onLine', 'ok'),
                swap: { installed: null, due: null, days_left: null, source: 'none', severity: 'unknown' },
            }),
        ],
    }),
    '/plugin/ups-battery/battery': (url, init) => ({ ...JSON.parse(init.body) }),
};

function upsRow(overrides = {}) {
    return {
        device_id: 1, hostname: 'ups-a', display_name: 'UPS A', device_url: '/device/1', location: 'Site A', os: 'apc', device_up: true,
        severity: 'ok', on_battery: null, suspect: false,
        runtime: cell('4 min', 'critical'), charge: cell('100 %', 'ok'), load: cell('10 %', 'ok'), temperature: cell('31 °C', 'warning'),
        battery: cell('noBatteryNeedsReplacing', 'ok'), bad_packs: null, output: null, self_test: cell('ok', 'ok'),
        swap: { installed: null, due: null, days_left: null, source: 'none', severity: 'unknown', life_used: null },
        issues: [],
        sensors: [
            { class: 'voltage', label: 'Voltage', sensor_descr: 'Input', value_formatted: '230 V', severity: 'ok', sensor_url: '/s/1', graph_url: '/g/1' },
            { class: 'voltage', label: 'Voltage', sensor_descr: 'Output', value_formatted: '229 V', severity: 'ok', sensor_url: '/s/2', graph_url: '/g/2' },
            { class: 'frequency', label: 'Frequency', sensor_descr: 'Input', value_formatted: '50 Hz', severity: 'warning', sensor_url: '/s/3', graph_url: '/g/3' },
        ],
        ...overrides,
    };
}

function cell(formatted, severity) {
    return { sensor_id: 1, sensor_descr: 'x', sensor_url: '/s', graph_url: '/g', trend_url: '/t', value: 1, value_formatted: formatted, unit: '', severity, last_updated: new Date().toISOString() };
}

const wait = (ms = 60) => new Promise((resolve) => setTimeout(resolve, ms));

// The page script keeps timers running (age ticker, auto-refresh), which would keep node alive after the tests.
const windows = [];
after(() => { windows.forEach((window) => window.close()); });

/** Opens the page with the script running. `search` is the query string of the address, e.g. "?kiosk=1". */
async function boot({ search = '', initial = {}, routes = {}, refreshSeconds = 0, defaultView = undefined } = {}) {
    const config = {
        defaults: { type: 'power', class: 'runtime', limit: 25 },
        initial,
        matrixDefaults: ['runtime', 'load', 'charge'],
        defaultView,
        refreshSeconds,
        staleMinutes: 30,
        urls: {
            page: PAGE, data: '/plugin/ups-battery/data', matrix: '/plugin/ups-battery/matrix', options: '/plugin/ups-battery/options',
            views: '/plugin/ups-battery/views', saveView: '/plugin/ups-battery/views', deleteView: '/plugin/ups-battery/views/delete',
            ups: '/plugin/ups-battery/ups', battery: '/plugin/ups-battery/battery',
        },
        i18n: stubTexts(),
    };

    const dom = new JSDOM(pageHtml(config), { url: ORIGIN + PAGE + search, runScripts: 'outside-only', pretendToBeVisual: true });
    windows.push(dom.window);
    const calls = [];
    const handlers = { ...DEFAULT_ROUTES, ...routes };

    dom.window.fetch = async (url, init = {}) => {
        // Like a browser, a path is resolved against the document's base URL (LibreNMS' <base href>).
        const parsed = new URL(url, dom.window.document.baseURI);
        calls.push({ origin: parsed.origin, path: parsed.pathname, params: parsed.searchParams, method: init.method ?? 'GET', body: init.body, headers: init.headers ?? {} });
        const handler = handlers[parsed.pathname];
        if (handler === undefined) { return { ok: false, status: 404, json: async () => ({ message: 'not found' }) }; }
        const body = typeof handler === 'function' ? handler(parsed, init) : handler;
        return { ok: true, status: 200, json: async () => body };
    };

    dom.window.eval(script);
    await wait();

    const document = dom.window.document;
    const last = (pathname) => calls.filter((c) => c.path === pathname).at(-1);
    const rows = () => [...document.querySelectorAll('#ub-body tr')];

    return { dom, window: dom.window, document, calls, last, rows, $: (id) => document.getElementById(id) };
}

const fire = (window, node, type) => node.dispatchEvent(new window.Event(type, { bubbles: true }));

test('the page markup contains everything the script looks up', async () => {
    const { document } = await boot();
    const ids = [...script.matchAll(/\bel\('([a-z-]+)'\)/g)].map((m) => m[1]);

    assert.ok(ids.length > 20);
    for (const id of ids) { assert.ok(document.getElementById(id), `missing element #${id}`); }
});

test('single view: loads the filter options and then the data for the default metric', async () => {
    const page = await boot();

    assert.deepEqual(page.calls.map((c) => c.path).slice(0, 3).sort(), ['/plugin/ups-battery/data', '/plugin/ups-battery/options', '/plugin/ups-battery/views']);
    const data = page.last('/plugin/ups-battery/data');
    assert.equal(data.params.get('class'), 'runtime');
    assert.equal(data.params.get('type'), 'power');
    assert.equal(data.params.get('limit'), '25');
    assert.equal(data.params.get('aggregate'), 'none');
    assert.equal(page.$('ub-type').value, 'power');
    assert.equal(page.$('ub-class').value, 'runtime');
});

test('single view: shows a row per sensor with severity colour, link and trend icon', async () => {
    const page = await boot();
    const [first, second] = page.rows();

    assert.equal(page.rows().length, 2);
    assert.ok(first.classList.contains('danger'));
    assert.ok(!second.classList.contains('danger'));
    assert.equal(first.querySelector('a').getAttribute('href'), ORIGIN + '/device/1');
    assert.equal(first.querySelector('a').textContent, 'UPS A');
    assert.ok(first.textContent.includes('4 min'));
    assert.equal(first.querySelector('a.ub-trend').getAttribute('href'), ORIGIN + '/graphs/type=sensor_runtime/id=11/from=-1y');
    assert.ok(second.textContent.includes('1 h 1 min'));
});

test('requests and links stay on the page origin when LibreNMS\' <base href> names another host', async () => {
    const page = await boot();

    assert.ok(page.calls.length >= 3);
    assert.deepEqual([...new Set(page.calls.map((c) => c.origin))], [ORIGIN]);
    for (const a of page.document.querySelectorAll('#ub-body a, #ub-csv, #ub-csv-all')) {
        assert.equal(new URL(a.href).origin, ORIGIN, a.getAttribute('href'));
    }
    assert.equal(page.window.location.origin, ORIGIN);
});

test('single view: shows the zero runtime the server sends instead of an empty cell', async () => {
    const page = await boot({
        routes: { '/plugin/ups-battery/data': () => ({ filters: { class: 'runtime', sort: 'value', dir: 'asc' }, summary: { count: 1, min: 0, median: 0, max: 0, unit: 'Min' }, total: 1, rows: [row({ value: 0, value_formatted: '0 min' })] }) },
    });

    assert.ok(page.rows()[0].textContent.includes('0 min'));
    assert.ok(page.$('ub-summary').textContent.includes('summary.min 0 min'));
});

test('single view: summary formats runtime as a duration', async () => {
    const page = await boot();

    assert.match(page.$('ub-summary').textContent, /summary\.min 4 min/);
    assert.match(page.$('ub-summary').textContent, /summary\.median 22 min/);
    assert.match(page.$('ub-summary').textContent, /summary\.max 1 h 1 min/);
});

test('texts from the server are never interpreted as html', async () => {
    const page = await boot({
        routes: { '/plugin/ups-battery/data': () => ({ filters: { class: 'runtime' }, summary: { count: 1, min: 1, median: 1, max: 1, unit: '' }, total: 1, rows: [row({ display_name: '<img src=x onerror=alert(1)>', location: '<b>bold</b>', device_url: 'javascript:alert(1)' })] }) },
    });
    const body = page.$('ub-body');

    assert.equal(body.querySelectorAll('img').length, 0);
    assert.equal(body.querySelectorAll('b').length, 0);
    assert.ok(body.textContent.includes('<img src=x onerror=alert(1)>'));
    assert.equal(body.querySelector('a[href^="javascript"]'), null);
});

test('sorting: a click on a column asks for that sort, a second click flips the direction', async () => {
    const page = await boot();
    const head = () => page.document.querySelector('#ub-head th[data-sort="hostname"]');

    head().click();
    await wait();
    assert.equal(page.last('/plugin/ups-battery/data').params.get('sort'), 'hostname');
    assert.equal(page.last('/plugin/ups-battery/data').params.get('dir'), 'asc');

    page.document.querySelector('#ub-head th[data-sort="hostname"]').click();
    await wait();
    assert.equal(page.last('/plugin/ups-battery/data').params.get('dir'), 'desc');
});

test('the sensor name filter is sent after a short pause', async () => {
    const page = await boot();
    const before = page.calls.filter((c) => c.path === '/plugin/ups-battery/data').length;

    page.$('ub-sensor').value = 'Replace battery';
    fire(page.window, page.$('ub-sensor'), 'input');
    await wait(100);
    assert.equal(page.calls.filter((c) => c.path === '/plugin/ups-battery/data').length, before, 'must wait for typing to stop');

    await wait(300);
    assert.equal(page.last('/plugin/ups-battery/data').params.get('sensor'), 'Replace battery');
});

test('export links follow the filters, and "export all" asks for every row', async () => {
    const page = await boot({ search: '?q=site&limit=10', initial: { q: 'site', limit: '10' } });

    const csv = new URL(page.$('ub-csv').getAttribute('href'), ORIGIN);
    const all = new URL(page.$('ub-csv-all').getAttribute('href'), ORIGIN);

    assert.equal(csv.pathname, '/plugin/ups-battery/data');
    assert.equal(csv.searchParams.get('format'), 'csv');
    assert.equal(csv.searchParams.get('limit'), '10');
    assert.equal(csv.searchParams.get('q'), 'site');
    assert.equal(all.searchParams.get('format'), 'csv');
    assert.equal(all.searchParams.get('limit'), '0');
    assert.equal(all.searchParams.get('q'), 'site');
});

test('the address bar mirrors the filters so a view can be shared', async () => {
    const page = await boot({ search: '?q=site', initial: { q: 'site' } });
    const params = new URLSearchParams(page.window.location.search);

    assert.equal(params.get('q'), 'site');
    assert.equal(params.get('class'), 'runtime');
    assert.equal(params.get('kiosk'), null);
});

test('compare view: asks for the matrix with the selected metrics and shows the battery verdict', async () => {
    const page = await boot();

    page.$('ub-view-matrix').checked = true;
    fire(page.window, page.$('ub-view-matrix'), 'change');
    await wait();

    const call = page.last('/plugin/ups-battery/matrix');
    assert.equal(call.params.get('view'), 'matrix');
    assert.equal(call.params.get('classes'), 'runtime,load,charge');

    const headings = [...page.document.querySelectorAll('#ub-head th')].map((th) => th.textContent);
    assert.ok(headings.some((text) => text.startsWith('Runtime')));
    assert.ok(headings.includes('columns.battery'));
    assert.equal(page.document.querySelector('#ub-head th:last-child').getAttribute('data-sort'), null, 'the battery column is not sortable');

    const [worn, fine, unknown] = page.rows();
    assert.ok(worn.lastElementChild.querySelector('.label-warning'), 'suspect device gets a badge');
    assert.equal(fine.lastElementChild.textContent, 'suspect.no');
    assert.equal(unknown.lastElementChild.textContent, '–');
    assert.ok(fine.children[4].classList.contains('text-muted'), 'a metric the device lacks is dimmed');
});

test('compare view: leaves out the battery column without runtime and load', async () => {
    const page = await boot({
        routes: { '/plugin/ups-battery/matrix': () => ({ filters: { classes: ['charge'] }, classes: [CLASSES[2]], total: 1, rows: [{ device_id: 1, hostname: 'a', display_name: 'A', device_url: '/d', location: null, os: '', device_up: true, suspect: null, cells: { charge: cell('99 %', 'ok') } }] }) },
    });

    page.$('ub-view-matrix').checked = true;
    fire(page.window, page.$('ub-view-matrix'), 'change');
    await wait();

    assert.ok(![...page.document.querySelectorAll('#ub-head th')].some((th) => th.textContent === 'columns.battery'));
});

test('suspect filter: only available when runtime and load are compared, and sent as suspect=1', async () => {
    const page = await boot({ search: '?view=matrix&classes=runtime,load', initial: { view: 'matrix', classes: 'runtime,load' } });

    assert.equal(page.$('ub-suspect-group').style.display, '');
    assert.equal(page.$('ub-suspect').disabled, false);

    page.$('ub-suspect').checked = true;
    fire(page.window, page.$('ub-suspect'), 'change');
    await wait();
    assert.equal(page.last('/plugin/ups-battery/matrix').params.get('suspect'), '1');

    const box = page.$('ub-classes').querySelector('input[value="load"]');
    box.checked = false;
    fire(page.window, box, 'change');
    await wait();

    assert.equal(page.$('ub-suspect').disabled, true);
    assert.equal(page.$('ub-suspect').checked, false);
    assert.equal(page.last('/plugin/ups-battery/matrix').params.get('suspect'), null);
});

test('suspect filter: hidden in the single metric view', async () => {
    const page = await boot();

    assert.equal(page.$('ub-suspect-group').style.display, 'none');
    assert.equal(page.last('/plugin/ups-battery/data').params.get('suspect'), null);
});

test('kiosk view: hides the controls, keeps kiosk out of the data requests and can be left again', async () => {
    const page = await boot();
    const container = page.$('ups-battery');

    assert.ok(!container.classList.contains('ub-kiosk'));

    page.$('ub-kiosk').click();
    assert.ok(container.classList.contains('ub-kiosk'));
    assert.equal(new URLSearchParams(page.window.location.search).get('kiosk'), '1');
    assert.equal(new URL(page.$('ub-csv').getAttribute('href'), ORIGIN).searchParams.get('kiosk'), null);

    page.document.dispatchEvent(new page.window.KeyboardEvent('keydown', { key: 'Escape' }));
    assert.ok(!container.classList.contains('ub-kiosk'));
    assert.equal(new URLSearchParams(page.window.location.search).get('kiosk'), null);

    page.$('ub-kiosk').click();
    page.$('ub-kiosk-exit').click();
    assert.ok(!container.classList.contains('ub-kiosk'));
});

test('kiosk view: opens straight into kiosk mode from the address', async () => {
    const page = await boot({ search: '?kiosk=1', initial: { kiosk: '1' } });

    assert.ok(page.$('ups-battery').classList.contains('ub-kiosk'));
    assert.equal(page.last('/plugin/ups-battery/data').params.get('kiosk'), null);
});

test('saved views: lists them, and saving leaves the kiosk flag out', async () => {
    const saved = [];
    const page = await boot({
        search: '?kiosk=1&q=site',
        initial: { kiosk: '1', q: 'site' },
        routes: {
            '/plugin/ups-battery/views': (url, init) => {
                if (init && init.method === 'POST') {
                    saved.push(JSON.parse(init.body));
                    return { views: [{ name: 'Mine', query: saved[0].query }] };
                }
                return { views: [{ name: 'Existing', query: { q: 'x' } }] };
            },
        },
    });

    assert.deepEqual([...page.$('ub-views').options].map((o) => o.textContent), ['views.choose', 'Existing']);

    page.window.prompt = () => 'Mine';
    page.$('ub-view-save').click();
    await wait();

    const post = page.last('/plugin/ups-battery/views');
    assert.equal(post.method, 'POST');
    assert.equal(post.headers['X-CSRF-TOKEN'], 'token-123');
    assert.equal(saved[0].name, 'Mine');
    assert.equal(saved[0].query.q, 'site');
    assert.equal('kiosk' in saved[0].query, false);
    assert.deepEqual([...page.$('ub-views').options].map((o) => o.textContent), ['views.choose', 'Mine']);
});

test('errors from the server are shown instead of failing silently', async () => {
    const page = await boot({ routes: { '/plugin/ups-battery/data': () => { throw new Error('boom'); } } });

    // The mock throws inside fetch, which the script reports through the error box.
    assert.notEqual(page.$('ub-error').style.display, 'none');
    assert.ok(page.$('ub-error').textContent.includes('boom'));
});

test('an unknown device type in the address falls back to all types', async () => {
    const page = await boot({ search: '?type=nonsense', initial: { type: 'nonsense' } });

    assert.equal(page.$('ub-type').value, '');
});

test('auto-refresh is offered only when the plugin setting allows it', async () => {
    const off = await boot({ refreshSeconds: 0 });
    const on = await boot({ refreshSeconds: 300 });

    assert.equal(off.$('ub-refresh-group').style.display, 'none');
    assert.equal(on.$('ub-refresh').checked, true);
});

// ---- UPS overview ----

test('UPS overview: is the default view and asks for one row per UPS', async () => {
    const page = await boot({ defaultView: 'ups' });
    const ups = page.last('/plugin/ups-battery/ups');

    assert.ok(ups, 'the UPS endpoint was called');
    assert.equal(page.last('/plugin/ups-battery/data'), undefined);
    assert.equal(ups.params.get('view'), 'ups');
    assert.equal(ups.params.get('class'), null);
    assert.equal(ups.params.get('sensor'), null);
    assert.equal(page.$('ub-view-ups').checked, true);
    assert.equal(page.$('ub-class-group').style.display, 'none');
    assert.equal(page.$('ub-sensor-group').style.display, 'none');
    assert.equal(page.$('ub-attention-group').style.display, '');
    assert.equal(page.$('ub-cards').style.display, '');
    assert.ok(new URL(page.$('ub-csv').href).pathname.endsWith('/plugin/ups-battery/ups'));
});

test('UPS overview: shows the summary cards', async () => {
    const page = await boot({ defaultView: 'ups' });
    const cards = [...page.document.querySelectorAll('#ub-cards .ub-card')];

    assert.equal(cards.length, 8);
    assert.equal(cards[0].querySelector('.ub-card-value').textContent, '2');
    assert.ok(cards[1].classList.contains('ub-danger'), 'on battery is red');
    assert.ok(!cards[3].classList.contains('ub-warn'), 'nothing due within the window');
    assert.equal(cards[7].querySelector('a').getAttribute('href'), ORIGIN + '/device/1');
});

test('UPS overview: one row per UPS with power, battery and swap data', async () => {
    const page = await boot({ defaultView: 'ups' });
    const [first, second] = page.rows();
    const cells = (tr) => [...tr.children];

    assert.equal(page.rows().length, 2);
    assert.ok(first.classList.contains('ub-sev-critical'), 'a severity stripe, not a red row');
    assert.ok(!first.classList.contains('danger'));
    assert.ok(second.classList.contains('ub-sev-ok'));
    assert.equal(cells(first)[1].textContent, 'UPS A');
    assert.equal(cells(first)[3].querySelector('.label-danger').textContent, 'ups.on_battery');
    assert.ok(cells(first)[5].classList.contains('danger'), 'runtime cell is red');
    assert.ok(cells(first)[9].textContent.includes('ups.bad_packs'));
    assert.ok(cells(first)[9].textContent.includes('suspect.yes'));
    assert.ok(cells(first)[11].textContent.startsWith('2020-01-01'));
    assert.ok(cells(first)[12].textContent.startsWith('2024-01-01'));
    assert.ok(cells(first)[12].classList.contains('danger'));
    assert.equal(cells(second)[3].querySelector('.label-success').textContent, 'ups.on_mains');
    assert.equal(cells(second)[12].textContent, '–');
});

test('UPS overview: a row opens to show all sensors in one card per class, problems on top', async () => {
    const page = await boot({ defaultView: 'ups' });
    const toggle = page.rows()[0].querySelector('.ub-toggle');

    toggle.click();
    const details = page.document.querySelector('#ub-body tr.ub-details');
    assert.ok(details, 'details row added');
    assert.deepEqual([...details.querySelectorAll('.ub-group h5')].map((h) => h.textContent), ['Voltage (2)', 'Frequency (1)']);
    const first = details.querySelector('.ub-group .ub-item');
    assert.equal(first.querySelector('.ub-item-name').textContent, 'Input');
    assert.equal(first.querySelector('.ub-item-value').textContent, '230 V');

    const problems = details.querySelector('.ub-problems');
    assert.ok(problems.textContent.startsWith('ups.problems (1)'));
    assert.ok(problems.querySelector('.ub-item-warning').textContent.includes('Frequency'));

    toggle.click();
    assert.equal(page.document.querySelector('#ub-body tr.ub-details'), null);
});

test('UPS overview: long sensor lists show the problems first and the rest on request', async () => {
    const states = Array.from({ length: 10 }, (_, i) => ({
        class: 'state', label: 'State', sensor_descr: 'Output ' + i, value_formatted: i === 7 ? 'error' : 'normal',
        severity: i === 7 ? 'critical' : 'ok', sensor_url: '/s/' + i, graph_url: '/g/' + i,
    }));
    const many = (url) => {
        const body = DEFAULT_ROUTES['/plugin/ups-battery/ups'](url);
        return { ...body, rows: [{ ...body.rows[1], sensors: states }] };
    };
    const page = await boot({ defaultView: 'ups', routes: { '/plugin/ups-battery/ups': many } });
    page.rows()[0].querySelector('.ub-toggle').click();

    const group = page.document.querySelector('.ub-detail-grid .ub-group');
    const items = [...group.querySelectorAll('.ub-item')];
    const visible = () => items.filter((li) => li.style.display !== 'none').length;
    assert.equal(items.length, 10);
    assert.equal(items[0].querySelector('.ub-item-name').textContent, 'Output 7', 'the critical one comes first');
    assert.equal(visible(), 6);

    const more = group.querySelector('.ub-more');
    assert.equal(more.textContent, 'ups.more_sensors');
    more.click();
    assert.equal(visible(), 10);
    assert.equal(more.textContent, 'ups.less_sensors');
    more.click();
    assert.equal(visible(), 6);
});

test('UPS overview: the battery install date can be set and the list reloads', async () => {
    const page = await boot({ defaultView: 'ups' });
    page.window.prompt = () => ' 2025-03-01 ';
    const before = page.calls.filter((c) => c.path === '/plugin/ups-battery/ups').length;

    page.rows()[1].querySelector('.ub-edit').click();
    await wait();

    const post = page.last('/plugin/ups-battery/battery');
    assert.equal(post.method, 'POST');
    assert.deepEqual(JSON.parse(post.body), { device_id: 2, installed: '2025-03-01' });
    assert.equal(post.headers['X-CSRF-TOKEN'], 'token-123');
    assert.equal(page.calls.filter((c) => c.path === '/plugin/ups-battery/ups').length, before + 1);
});

test('UPS overview: cancelling the date prompt changes nothing, and no edit button without permission', async () => {
    const page = await boot({ defaultView: 'ups' });
    page.window.prompt = () => null;
    page.rows()[0].querySelector('.ub-edit').click();
    await wait();
    assert.equal(page.last('/plugin/ups-battery/battery'), undefined);

    const readOnly = await boot({
        defaultView: 'ups',
        routes: { '/plugin/ups-battery/ups': (url) => ({ ...DEFAULT_ROUTES['/plugin/ups-battery/ups'](url), can_edit: false }) },
    });
    assert.equal(readOnly.document.querySelector('.ub-edit'), null);
});

test('UPS overview: the attention filter and sorting go to the server', async () => {
    const page = await boot({ defaultView: 'ups' });

    page.$('ub-attention').checked = true;
    fire(page.window, page.$('ub-attention'), 'change');
    await wait();
    assert.equal(page.last('/plugin/ups-battery/ups').params.get('attention'), '1');

    page.document.querySelector('#ub-head th[data-sort="swap"]').click();
    await wait();
    const sorted = page.last('/plugin/ups-battery/ups');
    assert.equal(sorted.params.get('sort'), 'swap');
    assert.equal(sorted.params.get('dir'), null, 'the server picks the direction');
    assert.equal(new URL(page.window.location.href).searchParams.get('attention'), '1');
});

test('UPS overview: addresses from before it still open the single view', async () => {
    const page = await boot({ defaultView: 'ups', search: '?class=load', initial: { class: 'load' } });

    assert.equal(page.$('ub-view-single').checked, true);
    assert.equal(page.last('/plugin/ups-battery/data').params.get('class'), 'load');
    assert.equal(page.$('ub-cards').style.display, 'none');
});

test('UPS overview: switching views keeps working', async () => {
    const page = await boot({ defaultView: 'ups' });

    page.$('ub-view-matrix').checked = true;
    fire(page.window, page.$('ub-view-matrix'), 'change');
    await wait();
    assert.ok(page.last('/plugin/ups-battery/matrix'));
    assert.equal(page.$('ub-cards').style.display, 'none');

    page.$('ub-view-ups').checked = true;
    fire(page.window, page.$('ub-view-ups'), 'change');
    await wait();
    assert.equal(page.$('ub-cards').style.display, '');
    assert.equal(page.rows().length, 2);
});

// ---- UPS overview: insight ----

test('UPS overview: a summary card filters the table and a second click shows all again', async () => {
    const page = await boot({ defaultView: 'ups' });
    const overdue = () => page.document.querySelector('#ub-cards [data-focus="overdue"]');

    assert.equal(overdue().getAttribute('role'), 'button');
    overdue().click();
    await wait();
    assert.equal(page.last('/plugin/ups-battery/ups').params.get('focus'), 'overdue');
    assert.equal(overdue().getAttribute('aria-pressed'), 'true');
    assert.ok(overdue().classList.contains('ub-active'));
    assert.equal(new URL(page.window.location.href).searchParams.get('focus'), 'overdue');

    overdue().click();
    await wait();
    assert.equal(page.last('/plugin/ups-battery/ups').params.get('focus'), null);
    assert.equal(overdue().getAttribute('aria-pressed'), 'false');
});

test('UPS overview: the "UPSs" card clears the card filter, and the keyboard works too', async () => {
    const page = await boot({ defaultView: 'ups', search: '?view=ups&focus=down', initial: { view: 'ups', focus: 'down' } });
    assert.equal(page.last('/plugin/ups-battery/ups').params.get('focus'), 'down');

    page.document.querySelector('#ub-cards [data-focus=""]').dispatchEvent(new page.window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
    await wait();
    assert.equal(page.last('/plugin/ups-battery/ups').params.get('focus'), null);
});

test('UPS overview: an unknown card filter in the address is ignored', async () => {
    const page = await boot({ defaultView: 'ups', search: '?view=ups&focus=everything', initial: { view: 'ups', focus: 'everything' } });

    assert.equal(page.last('/plugin/ups-battery/ups').params.get('focus'), null);
});

test('UPS overview: the timeline shows the battery swaps per month', async () => {
    const page = await boot({ defaultView: 'ups' });
    const cols = [...page.document.querySelectorAll('#ub-timeline .ub-tl-col')];

    assert.equal(page.$('ub-timeline-box').style.display, '');
    assert.equal(cols.length, 12);
    assert.equal(cols[2].querySelector('.ub-tl-count').textContent, '3');
    assert.equal(cols[2].querySelector('.ub-tl-bar').style.height, '100%');
    assert.equal(cols[5].querySelector('.ub-tl-bar').style.height, '33%');
    assert.equal(cols[0].querySelector('.ub-tl-count').textContent, '');
    assert.ok(cols[0].querySelector('.ub-tl-label').textContent.includes('2026'), 'the first month shows the year');
    assert.equal(page.$('ub-timeline-empty').style.display, 'none');
});

test('UPS overview: an empty timeline says so instead of showing empty bars', async () => {
    const empty = (url) => {
        const body = DEFAULT_ROUTES['/plugin/ups-battery/ups'](url);
        return { ...body, cards: { ...body.cards, swap_timeline: body.cards.swap_timeline.map((m) => ({ ...m, count: 0 })) } };
    };
    const page = await boot({ defaultView: 'ups', routes: { '/plugin/ups-battery/ups': empty } });

    assert.equal(page.$('ub-timeline').style.display, 'none');
    assert.equal(page.$('ub-timeline-empty').style.display, '');
});

test('UPS overview: the attention column says why a UPS needs a look', async () => {
    const page = await boot({ defaultView: 'ups' });
    const [first, second] = page.rows();
    const attention = first.children[4];
    const badges = [...attention.querySelectorAll('.label')];

    assert.equal(page.document.querySelectorAll('#ub-head th')[4].textContent, 'ups.col_attention');
    assert.equal(badges.length, 3);
    assert.ok(badges[0].classList.contains('label-danger'));
    assert.equal(badges[0].textContent, 'on_battery');
    assert.ok(attention.textContent.includes('issues.more'), 'the rest is summarised');
    assert.equal(attention.title.split('\n').length, 5, 'the tooltip lists every issue');
    assert.equal(second.children[4].textContent, '–');
});

test('UPS overview: charge, load and battery life get a bar', async () => {
    const page = await boot({ defaultView: 'ups' });
    const first = page.rows()[0];
    const charge = first.children[6].querySelector('.ub-bar > span');
    const life = first.children[12].querySelector('.ub-bar');

    assert.ok(charge, 'charge bar');
    assert.ok(first.children[7].querySelector('.ub-bar'), 'load bar');
    assert.equal(first.children[5].querySelector('.ub-bar'), null, 'no bar for runtime');
    assert.ok(life.classList.contains('ub-bar-critical'));
    assert.equal(life.firstChild.style.width, '100%', 'capped at 100 %');
    assert.equal(life.title, 'ups.life_used');
    assert.equal(page.rows()[1].children[12].querySelector('.ub-bar'), null);
});
