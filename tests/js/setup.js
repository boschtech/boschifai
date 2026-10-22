import Vue from 'vue';
import { afterEach, beforeEach, vi } from 'vitest';

Vue.config.productionTip = false;
Vue.config.devtools = false;

// jsdom has no CSS.escape, which every real browser provides (DocumentViewer uses it to look up
// heading ids). Minimal stand-in: backslash-escape anything that isn't a plain identifier
// character, plus a leading digit — enough for the slug-style ids the app generates.
if (!globalThis.CSS?.escape) {
    globalThis.CSS = {
        ...globalThis.CSS,
        escape: (value) => String(value)
            .replace(/[^a-zA-Z0-9_\u00A0-\uFFFF-]/g, (ch) => `\\${ch}`)
            .replace(/^(\d)/, '\\3$1 '),
    };
}

// Node 26 has its own global localStorage, which hides jsdom's and is undefined unless Node is
// started with --localstorage-file — so every test failed on `localStorage.clear()` below. Put
// jsdom's back; on Node versions without the built-in, jsdom's is already there.
if (!globalThis.localStorage) {
    Object.defineProperty(globalThis, 'localStorage', {
        value: jsdom.window.localStorage,
        configurable: true,
        writable: true,
    });
}

// The app sets window.axios in bootstrap.js; tests get a fresh fake per test so no request
// ever leaves the process and each test states exactly what the API returns.
beforeEach(() => {
    window.axios = {
        get: vi.fn(() => Promise.resolve({ data: {} })),
        post: vi.fn(() => Promise.resolve({ data: {} })),
        delete: vi.fn(() => Promise.resolve({ data: {} })),
    };
    localStorage.clear();
});

afterEach(() => {
    vi.useRealTimers();
});
