/*
 * Runs resources/views/menu.blade.php (the menu entry and its top navigation script) inside LibreNMS' navigation bar
 * markup (resources/views/layouts/menu.blade.php in LibreNMS, reduced to the parts the script relies on). Run with: npm test
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const view = readFileSync(path.join(root, 'resources/views/menu.blade.php'), 'utf8');

const ORIGIN = 'https://librenms.test';
// route() gives an absolute URL on the host of the request
const REPORT = ORIGIN + '/plugin/ups-battery/report';

/** The menu entry as LibreNMS renders it, with the Blade expressions filled in. */
function menuEntry() {
    return view
        .replace(/\{\{--[\s\S]*?--\}\}/g, '')
        .replace(/^@(if|endif)\b.*$/gm, '')
        .replace("{{ route('ups-battery.report') }}", REPORT)
        .replace(/\{\{ trans\([^}]*\}\}/, 'UPS Battery');
}

/** LibreNMS' page head and navigation bar, with the menu entry inside Overview > Plugins like the real page. */
function page(entries = 1) {
    const plugins = `<li>${menuEntry()}</li>`.repeat(entries);
    return `<!doctype html><html><head><base href="https://base-url.example/"></head><body>
    <nav class="navbar navbar-default navbar-sticky-top" role="navigation">
        <div class="collapse navbar-collapse" id="navHeaderCollapse">
            <ul class="nav navbar-nav">
                <li class="dropdown"><a href="/overview">Overview</a>
                    <ul class="dropdown-menu"><li class="dropdown-submenu"><a>Plugins</a>
                        <ul class="dropdown-menu">${plugins}</ul>
                    </li></ul>
                </li>
                <li class="dropdown"><a href="/health">Health</a></li>
            </ul>
            <ul class="nav navbar-nav navbar-right"><li><a>user</a></li></ul>
        </div>
    </nav></body></html>`;
}

/** Resolves once DOMContentLoaded has fired, which is when the script adds the item. */
function load(html, pathname = '/devices') {
    const dom = new JSDOM(html, { url: ORIGIN + pathname, runScripts: 'dangerously' });

    return new Promise((resolve) => {
        dom.window.document.addEventListener('DOMContentLoaded', () => resolve(dom));
    });
}

test('the rendered menu entry has no Blade left in it', () => {
    assert.doesNotMatch(menuEntry(), /@json|@if|@endif|\{\{/);
});

test('adds one top-level item at the end of the left navigation bar', async () => {
    const dom = await load(page());
    const doc = dom.window.document;
    const items = doc.querySelectorAll('#navHeaderCollapse > ul.navbar-nav:not(.navbar-right) > li');
    const last = items[items.length - 1];

    assert.equal(items.length, 3);
    assert.equal(last.id, 'ub-top-nav');
    assert.equal(last.className, '');
    assert.equal(last.querySelector('span').textContent, 'UPS Battery');
    assert.equal(doc.querySelectorAll('.navbar-right #ub-top-nav').length, 0);
    dom.window.close();
});

test('links to the same absolute URL as the Plugins entry, not to the <base href> host', async () => {
    const dom = await load(page());
    const link = dom.window.document.querySelector('#ub-top-nav a');

    assert.equal(link.getAttribute('href'), REPORT);
    assert.equal(link.href, REPORT);
    dom.window.close();
});

test('is never drawn as "active" (a permanent dark background) and is added only once', async () => {
    const dom = await load(page(2), '/plugin/ups-battery/report?class=load');

    assert.equal(dom.window.document.querySelectorAll('#ub-top-nav').length, 1);
    assert.equal(dom.window.document.getElementById('ub-top-nav').className, '');
    assert.equal(dom.window.document.querySelector('#navHeaderCollapse .active'), null);
    dom.window.close();
});

test('does nothing when the navigation bar is not there', async () => {
    const dom = await load(`<!doctype html><html><body><ul><li>${menuEntry()}</li></ul></body></html>`);

    assert.equal(dom.window.document.getElementById('ub-top-nav'), null);
    dom.window.close();
});
