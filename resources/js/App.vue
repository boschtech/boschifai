<template>
    <!-- Guest pages (login, forgot/reset password) and the invitation link have their own full-page layout. -->
    <router-view v-if="fullPage" />

    <div v-else class="min-h-screen bg-bg">
        <header ref="header" class="sticky top-0 z-10 border-b border-border bg-surface/90 backdrop-blur">
            <div class="mx-auto max-w-[1440px] px-6 py-4 flex items-center justify-between">
                <router-link :to="{ name: 'home' }" class="text-2xl font-extrabold tracking-tight text-fg-strong">
                    Boschif<span class="text-[#D4AF37]">AI</span>
                </router-link>
                <div class="flex items-center gap-3">
                    <workspace-switcher />
                    <token-usage-badge />
                    <theme-toggle />
                    <router-link
                        :to="{ name: 'home' }"
                        class="rounded-sm border border-border bg-surface px-4 py-2 text-sm font-medium text-fg transition hover:bg-surface-alt"
                    >
                        Home
                    </router-link>
                    <router-link
                        v-if="canManage"
                        :to="{ name: 'settings.github' }"
                        class="rounded-sm border border-border bg-surface px-4 py-2 text-sm font-medium text-fg transition hover:bg-surface-alt"
                    >
                        Connect Repo
                    </router-link>
                    <template v-if="user">
                        <router-link
                            :to="{ name: 'account' }"
                            title="Account"
                            class="rounded-sm border border-border bg-surface px-4 py-2 text-sm font-medium text-fg transition hover:bg-surface-alt"
                        >
                            {{ user.name }}
                        </router-link>
                        <button
                            type="button"
                            class="rounded-sm border border-border bg-surface px-4 py-2 text-sm font-medium text-fg transition hover:bg-surface-alt hover:text-danger"
                            @click="signOut"
                        >
                            Sign out
                        </button>
                    </template>
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
import { mapGetters } from 'vuex';
import ThemeToggle from './components/ThemeToggle.vue';
import SidebarNav from './components/SidebarNav.vue';
import TokenUsageBadge from './components/TokenUsageBadge.vue';
import WorkspaceSwitcher from './components/WorkspaceSwitcher.vue';

export default {
    name: 'App',

    components: { ThemeToggle, SidebarNav, TokenUsageBadge, WorkspaceSwitcher },

    computed: {
        ...mapGetters('auth', ['user', 'canManage']),

        fullPage() {
            return Boolean(this.$route.meta.guest || this.$route.meta.public);
        },
    },

    watch: {
        // Coming from a full-page screen (e.g. just signed in): the header only now exists to measure.
        fullPage(isFullPage) {
            if (!isFullPage) this.$nextTick(this.updateHeaderHeight);
        },
    },

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
        async signOut() {
            await this.$store.dispatch('auth/logout');
            this.$router.push({ name: 'login' }).catch(() => {});
        },

        updateHeaderHeight() {
            const height = this.$refs.header?.offsetHeight;
            if (height) {
                document.documentElement.style.setProperty('--app-header-height', `${height}px`);
            }
        },
    },
};
</script>
