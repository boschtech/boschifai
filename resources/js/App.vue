<template>
    <div class="min-h-screen bg-bg">
        <header class="sticky top-0 z-10 border-b border-border bg-surface/90 backdrop-blur">
            <div class="mx-auto max-w-6xl px-6 py-4 flex items-center justify-between">
                <router-link :to="{ name: 'runs.index' }" class="text-2xl font-extrabold tracking-tight text-fg-strong">
                    Boschif<span class="text-[#D4AF37]">AI</span>
                </router-link>
                <div class="flex items-center gap-3">
                    <theme-toggle />
                    <router-link
                        :to="{ name: 'runs.index' }"
                        class="rounded-sm border border-border bg-surface px-4 py-2 text-sm font-medium text-fg transition hover:bg-surface-alt"
                    >
                        Home
                    </router-link>
                    <router-link
                        :to="{ name: 'settings.github' }"
                        class="rounded-sm border border-border bg-surface px-4 py-2 text-sm font-medium text-fg transition hover:bg-surface-alt"
                    >
                        Connect GitHub
                    </router-link>
                    <router-link
                        :to="{ name: 'runs.create' }"
                        class="rounded-sm bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-dark"
                    >
                        New requirement
                    </router-link>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-6xl px-6 py-8">
            <router-view />
        </main>
    </div>
</template>

<script>
import ThemeToggle from './components/ThemeToggle.vue';

export default {
    name: 'App',

    components: { ThemeToggle },
};
</script>
