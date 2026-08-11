<template>
    <button
        type="button"
        class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-border bg-surface text-fg-muted transition hover:text-primary"
        :title="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
        @click="toggle"
    >
        <!-- Sun icon (shown in dark mode — click to go light) -->
        <svg v-if="isDark" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5">
            <path d="M10 2a.75.75 0 0 1 .75.75v1.5a.75.75 0 0 1-1.5 0v-1.5A.75.75 0 0 1 10 2ZM10 15a.75.75 0 0 1 .75.75v1.5a.75.75 0 0 1-1.5 0v-1.5A.75.75 0 0 1 10 15ZM10 7a3 3 0 1 0 0 6 3 3 0 0 0 0-6ZM15.657 5.404a.75.75 0 1 0-1.06-1.06l-1.061 1.06a.75.75 0 0 0 1.06 1.06l1.06-1.06ZM6.464 14.596a.75.75 0 1 0-1.06-1.06l-1.06 1.06a.75.75 0 1 0 1.06 1.06l1.06-1.06ZM18 10a.75.75 0 0 1-.75.75h-1.5a.75.75 0 0 1 0-1.5h1.5A.75.75 0 0 1 18 10ZM5 10a.75.75 0 0 1-.75.75h-1.5a.75.75 0 0 1 0-1.5h1.5A.75.75 0 0 1 5 10ZM15.657 14.596a.75.75 0 0 1-1.06 1.06l-1.061-1.06a.75.75 0 1 1 1.06-1.06l1.06 1.06ZM6.464 5.404a.75.75 0 0 1-1.06 1.06l-1.06-1.06a.75.75 0 0 1 1.06-1.06l1.06 1.06Z" />
        </svg>
        <!-- Moon icon (shown in light mode — click to go dark) -->
        <svg v-else xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5">
            <path fill-rule="evenodd" d="M7.455 2.004a.75.75 0 0 1 .26.77 7 7 0 0 0 9.958 7.967.75.75 0 0 1 1.067.853A8.5 8.5 0 1 1 6.647 1.921a.75.75 0 0 1 .808.083Z" clip-rule="evenodd" />
        </svg>
    </button>
</template>

<script>
// Same storage key ('boschifai-theme') and rule (explicit choice, else system preference) as
// the inline no-FOUC script in resources/views/app.blade.php — keep both in sync.
const STORAGE_KEY = 'boschifai-theme';

export default {
    name: 'ThemeToggle',

    data() {
        return {
            // Read the class the blade-inline script already applied, rather than
            // re-deriving from localStorage/matchMedia here — a second, possibly-diverging
            // source of truth would risk the toggle's initial icon disagreeing with the
            // theme actually applied to the page.
            isDark: document.documentElement.classList.contains('dark'),
        };
    },

    methods: {
        toggle() {
            this.isDark = !this.isDark;
            document.documentElement.classList.toggle('dark', this.isDark);
            localStorage.setItem(STORAGE_KEY, this.isDark ? 'dark' : 'light');
        },
    },
};
</script>
