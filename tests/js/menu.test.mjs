/*
 * Runs the top navigation script of resources/views/menu.blade.php against LibreNMS' navigation bar markup
 * (resources/views/layouts/menu.blade.php in LibreNMS, reduced to the parts the script relies on). Run with: npm test
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const view = readFileSync(path.join(root, 'resources/views/menu.blade.php'), 'utf8');

/** The script as the browser gets it, with the two @json() expressions filled in. */
function menuScript() {
    const source = view.split('<script>')[1].split('</script>')[0];
    return source
        .replace(/@json\(\\Drakelid[^\n]*?route\('ups-battery\.report'\)\)\)/, JSON.stringify('/plugin/ups-battery/report'))
        .replace(/@json\(trans\([^\n]*?\)\)/, JSON.stringify('UPS Battery'));
}

/** LibreNMS' navigation bar, with the plugin's menu entry inside Overview > Plugins like the real page. */
function navbar() {
    const script = `<script>${menuScript()}</script>`;
    return `<nav class="navbar navbar-default navbar-sticky-top" role="navigation">
        <div class="collapse navbar-collapse" id="navHeaderCollapse">
            <ul class="nav navbar-nav">
                <li class="dropdown"><a href="/overview">Overview</a>
                    <ul class="dropdown-menu"><li class="dropdown-submenu"><a>Plugins</a>
                        <ul class="dropdown-menu"><li><a href="/plugin/ups-battery/report">UPS Battery</a>${script}</li></ul>
                    </li></ul>
                </li>
                <li class="dropdown"><a href="/health">Health</a></li>
            </ul>
            <ul class="nav navbar-nav navbar-right"><li><a>user</a></li></ul>
        </div>
    </nav>`;
}

/** Resolves once DOMContentLoaded has fired, which is when the script adds the item. */
function load(body, url = 'https://librenms.test/') {
    const dom = new JSDOM(`<!doctype html><html><body>${body}</body></html>`, { url, runScripts: 'dangerously' });

    return new Promise((resolve) => {
        dom.window.document.addEventListener('DOMContentLoaded', () => resolve(dom));
    });
}

test('the Blade expressions in the script are the ones the test fills in', () => {
    assert.doesNotMatch(menuScript(), /@json|\{\{/);
});

test('adds one top-level item at the end of the left navigation bar', async () => {
    const dom = await load(navbar(), 'https://librenms.test/devices');
    const doc = dom.window.document;
    const items = doc.querySelectorAll('#navHeaderCollapse > ul.navbar-nav:not(.navbar-right) > li');
    const last = items[items.length - 1];

    assert.equal(items.length, 3);
    assert.equal(last.id, 'ub-top-nav');
    assert.equal(last.className, '');
    assert.equal(last.querySelector('a').getAttribute('href'), '/plugin/ups-battery/report');
    assert.equal(last.querySelector('span').textContent, 'UPS Battery');
    assert.equal(doc.querySelectorAll('.navbar-right #ub-top-nav').length, 0);
    dom.window.close();
});

test('marks the item active on the plugin pages and adds it only once', async () => {
    const twice = navbar() + `<script>${menuScript()}</script>`;
    const dom = await load(twice, 'https://librenms.test/plugin/ups-battery/report?class=load');

    assert.equal(dom.window.document.querySelectorAll('#ub-top-nav').length, 1);
    assert.equal(dom.window.document.getElementById('ub-top-nav').className, 'active');
    dom.window.close();
});

test('does nothing when the navigation bar is not there', async () => {
    const dom = await load(`<script>${menuScript()}</script>`);

    assert.equal(dom.window.document.getElementById('ub-top-nav'), null);
    dom.window.close();
});
