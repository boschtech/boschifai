import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue2';
import path from 'path';

// Kept separate from vite.config.js: laravel-vite-plugin is only meant for the dev server and
// production build, so tests get just the Vue plugin plus the same aliases the app resolves.
export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            vue: 'vue/dist/vue.esm.js',
            '@': path.resolve(__dirname, 'resources/js'),
        },
    },
    test: {
        environment: 'jsdom',
        include: ['tests/js/**/*.test.js'],
        setupFiles: ['tests/js/setup.js'],
        restoreMocks: true,
        coverage: {
            provider: 'v8',
            // Every frontend file counts, tested or not, so the percentage reflects the whole app.
            include: ['resources/js/**/*.{js,vue}'],
            // Entry/bootstrap wiring only — nothing to unit test.
            exclude: ['resources/js/app.js', 'resources/js/bootstrap.js'],
            reporter: ['text-summary', 'text', 'html', 'json-summary'],
            reportsDirectory: 'coverage/js',
            thresholds: {
                // 85% floor on every metric, set when coverage was lines/statements 98%,
                // functions 97%, branches 94% — raise it as coverage improves; never lower it to
                // get a PR through.
                lines: 85,
                statements: 85,
                functions: 85,
                branches: 85,
            },
        },
    },
});
