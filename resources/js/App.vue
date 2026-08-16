<template>
    <div class="min-h-screen bg-bg">
        <header ref="header" class="sticky top-0 z-10 border-b border-border bg-surface/90 backdrop-blur">
            <div class="mx-auto max-w-[1440px] px-6 py-4 flex items-center justify-between">
                <router-link :to="{ name: 'home' }" class="text-2xl font-extrabold tracking-tight text-fg-strong">
                    Boschif<span class="text-[#D4AF37]">AI</span>
                </router-link>
                <div class="flex items-center gap-3">
                    <theme-toggle />
                    <router-link
                        :to="{ name: 'home' }"
                        class="rounded-sm border border-border bg-surface px-4 py-2 text-sm font-medium text-fg transition hover:bg-surface-alt"
                    >
                        Home
                    </router-link>
                    <router-link
                        :to="{ name: 'settings.github' }"
                        class="rounded-sm border border-border bg-surface px-4 py-2 text-sm font-medium text-fg transition hover:bg-surface-alt"
                    >
                        Connect Repo
                    </router-link>
                </div>
            </div>
        </header>

        <div class="mx-auto max-w-[1440px] flex items-start">
            <sidebar-nav />

            <main class="min-w-0 flex-1 px-6 py-8">
                <router-view />
            </main>
        </div>
    </div>
</template>

<script>
import ThemeToggle from './components/ThemeToggle.vue';
import SidebarNav from './components/SidebarNav.vue';

export default {
    name: 'App',

    components: { ThemeToggle, SidebarNav },

    mounted() {
        // SidebarNav's sticky offset needs to match this header's REAL rendered height, not a
        // guessed pixel value — the header's row height depends on its tallest child (button
        // padding/border vs. the logo's line-height), which is fragile to hand-compute and would
        // silently drift out of sync if header content ever changes. Measured here and exposed
        // as a CSS var (with a sensible fallback baked into SidebarNav for the first paint before
        // this runs) rather than duplicating Tailwind's box-model math.
        this.updateHeaderHeight();
        window.addEventListener('resize', this.updateHeaderHeight);
    },

    beforeDestroy() {
        window.removeEventListener('resize', this.updateHeaderHeight);
    },

    methods: {
        updateHeaderHeight() {
            const height = this.$refs.header?.offsetHeight;
            if (height) {
                document.documentElement.style.setProperty('--app-header-height', `${height}px`);
            }
        },
    },
};
</script>
