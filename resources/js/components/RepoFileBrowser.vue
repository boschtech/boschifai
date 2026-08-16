<template>
    <transition name="fade">
        <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="close">
            <div class="flex max-h-[80vh] w-full max-w-lg flex-col rounded-lg border border-border bg-surface p-5 shadow-card">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-fg-strong">Browse {{ repoDisplayName }}</h2>
                    <button type="button" class="text-fg-muted transition hover:text-fg" @click="close">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5">
                            <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                        </svg>
                    </button>
                </div>

                <nav class="mb-2 flex flex-wrap items-center gap-1 text-xs font-mono text-fg-muted">
                    <button type="button" class="text-primary hover:underline" @click="browse('')">root</button>
                    <template v-for="crumb in pathCrumbs">
                        <span :key="`sep-${crumb.path}`">/</span>
                        <button type="button" class="text-primary hover:underline" :key="crumb.path" @click="browse(crumb.path)">
                            {{ crumb.name }}
                        </button>
                    </template>
                </nav>

                <p v-if="loading" class="text-sm text-fg-muted">Loading…</p>
                <p v-else-if="error" class="text-sm text-danger">{{ error }}</p>
                <ul v-else class="min-h-0 flex-1 divide-y divide-border overflow-y-auto rounded-md border border-border">
                    <li v-for="entry in entries" :key="entry.path">
                        <button
                            v-if="entry.type === 'dir'"
                            type="button"
                            class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-fg transition hover:bg-surface-alt"
                            @click="browse(entry.path)"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 shrink-0 text-fg-muted">
                                <path d="M2 4.75A2.75 2.75 0 0 1 4.75 2h3.836a2.75 2.75 0 0 1 2.153 1.036l.51.638h4.001A2.75 2.75 0 0 1 18 6.424v8.826A2.75 2.75 0 0 1 15.25 18H4.75A2.75 2.75 0 0 1 2 15.25V4.75Z" />
                            </svg>
                            {{ entry.name }}
                        </button>
                        <button
                            v-else
                            type="button"
                            class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-fg transition hover:bg-primary/10"
                            @click="pick(entry.path)"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 shrink-0 text-fg-muted">
                                <path fill-rule="evenodd" d="M4 2a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6.828a2 2 0 0 0-.586-1.414l-2.828-2.828A2 2 0 0 0 11.172 2H4Zm4 8a1 1 0 0 1 1-1h1a1 1 0 1 1 0 2H9a1 1 0 0 1-1-1Z" clip-rule="evenodd" />
                            </svg>
                            {{ entry.name }}
                        </button>
                    </li>
                    <li v-if="!entries.length" class="px-3 py-2 text-sm text-fg-muted">Empty directory.</li>
                </ul>
            </div>
        </div>
    </transition>
</template>

<script>
export default {
    name: 'RepoFileBrowser',

    props: {
        open: { type: Boolean, default: false },
        repoConfigId: { type: [String, Number], default: null },
        repoDisplayName: { type: String, default: '' },
    },

    data() {
        return {
            path: '',
            entries: [],
            loading: false,
            error: null,
        };
    },

    computed: {
        // Breadcrumb segments built from the current path string, each carrying the cumulative
        // path a click on it should navigate back to — same convention as GithubSettingsPage's
        // local-repo browser.
        pathCrumbs() {
            if (!this.path) return [];
            const segments = this.path.split('/');
            return segments.map((name, index) => ({ name, path: segments.slice(0, index + 1).join('/') }));
        },
    },

    watch: {
        // Re-opening for a different repo (or re-opening at all) always starts at that repo's
        // own root — a stale path/listing from whatever was browsed last time would silently
        // show the wrong repo's files.
        open(isOpen) {
            if (isOpen) this.browse('');
        },
    },

    methods: {
        async browse(path) {
            this.loading = true;
            this.error = null;
            try {
                const { data } = await window.axios.get(`/api/repo-configs/${this.repoConfigId}/browse-files`, { params: { path } });
                this.path = data.path;
                this.entries = data.entries;
            } catch (e) {
                this.error = e.response?.data?.message || e.message;
                this.entries = [];
            } finally {
                this.loading = false;
            }
        },

        pick(filePath) {
            this.$emit('select', filePath);
            this.close();
        },

        close() {
            this.$emit('close');
        },
    },
};
</script>

<style scoped>
.fade-enter-active,
.fade-leave-active {
    transition: opacity 150ms ease;
}
.fade-enter,
.fade-leave-to {
    opacity: 0;
}
</style>
