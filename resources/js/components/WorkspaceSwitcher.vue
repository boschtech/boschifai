<template>
    <div v-if="current" class="flex items-center">
        <label v-if="workspaces.length > 1" class="sr-only" for="workspace-switcher">Workspace</label>
        <select
            v-if="workspaces.length > 1"
            id="workspace-switcher"
            :value="current.id"
            :disabled="switching"
            class="rounded-sm border border-border bg-surface px-3 py-2 text-sm font-medium text-fg transition hover:bg-surface-alt focus:outline-none focus:border-primary disabled:opacity-50"
            @change="switchTo(Number($event.target.value))"
        >
            <option v-for="workspace in workspaces" :key="workspace.id" :value="workspace.id">{{ workspace.name }}</option>
        </select>
        <span v-else class="rounded-sm border border-border bg-surface px-4 py-2 text-sm font-medium text-fg" title="Workspace">
            {{ current.name }}
        </span>
    </div>
</template>

<script>
import { mapGetters } from 'vuex';

// Everything the app shows belongs to the current workspace. Switching reloads the app so no
// list, page or cache from the previous workspace can linger on screen.
export default {
    name: 'WorkspaceSwitcher',

    props: {
        // Injected so tests can observe the reload instead of navigating the test runner.
        reload: { type: Function, default: () => window.location.assign('/') },
    },

    data() {
        return { switching: false };
    },

    computed: {
        ...mapGetters('auth', { current: 'currentWorkspace', workspaces: 'workspaces' }),
    },

    methods: {
        async switchTo(workspaceId) {
            if (workspaceId === this.current.id) return;
            this.switching = true;
            try {
                await this.$store.dispatch('auth/switchWorkspace', workspaceId);
                this.reload();
            } finally {
                this.switching = false;
            }
        },
    },
};
</script>
