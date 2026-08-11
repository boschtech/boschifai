<template>
    <div class="max-w-2xl">
        <h1 class="text-xl font-bold text-fg-strong mb-1">Connect GitHub</h1>
        <p class="text-sm text-fg-muted mb-6">
            Authorize Boschifai's GitHub OAuth App on your account, then pick which of your
            repositories it can clone, push test branches to, and open pull requests against.
        </p>

        <p v-if="authorizeDeniedMessage" class="text-sm text-warning mb-4">{{ authorizeDeniedMessage }}</p>
        <p v-if="error" class="text-sm text-danger mb-4">{{ error }}</p>
        <p v-if="removeError" class="text-sm text-danger mb-4">{{ removeError }}</p>

        <div v-if="!connections.length" class="mb-8">
            <button
                type="button"
                :disabled="connecting"
                class="rounded-sm bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-dark disabled:opacity-50"
                @click="connectGithub"
            >
                {{ connecting ? 'Redirecting…' : 'Connect GitHub' }}
            </button>
        </div>

        <p v-if="loading" class="text-fg-muted">Loading…</p>

        <div v-else-if="connections.length" class="mb-8">
            <h2 class="text-sm font-semibold text-fg-strong mb-2">Connected accounts</h2>

            <div v-if="!promptDismissed" class="mb-4 rounded-md border border-border bg-surface-alt p-4">
                <p class="text-sm text-fg">
                    Don't see the repository you need? It may belong to a different GitHub account or
                    organization than the one(s) connected below.
                </p>
                <div class="mt-3 flex items-center gap-4">
                    <button
                        type="button"
                        :disabled="connecting"
                        class="rounded-sm bg-primary px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-primary-dark disabled:opacity-50"
                        @click="connectGithub"
                    >
                        {{ connecting ? 'Redirecting…' : 'Connect a different GitHub account' }}
                    </button>
                    <button
                        type="button"
                        class="text-sm font-medium text-fg-muted transition hover:text-fg"
                        @click="promptDismissed = true"
                    >
                        No thanks, I'm satisfied with what's connected
                    </button>
                </div>
            </div>
            <button
                v-else
                type="button"
                :disabled="connecting"
                class="mb-4 text-sm font-medium text-primary transition hover:text-primary-dark disabled:opacity-50"
                @click="connectGithub"
            >
                {{ connecting ? 'Redirecting…' : '+ Connect a different GitHub account' }}
            </button>

            <ul class="divide-y divide-border rounded-md border border-border bg-surface">
                <li
                    v-for="connection in connections"
                    :key="connection.id"
                    class="flex items-center justify-between px-4 py-3"
                >
                    <div>
                        <p class="text-sm text-fg">{{ connection.github_login }}</p>
                        <p class="text-xs text-fg-muted">
                            {{ connection.connected_repo_count }} repo(s) connected
                        </p>
                    </div>
                    <button
                        type="button"
                        class="rounded-sm border border-border bg-surface px-3 py-1.5 text-sm font-medium text-fg transition hover:bg-surface-alt"
                        @click="selectConnection(connection.id)"
                    >
                        Select repositories
                    </button>
                </li>
            </ul>
        </div>

        <p v-else-if="!loading" class="text-sm text-fg-muted mb-8">
            No GitHub accounts connected yet — click "Connect GitHub" above to get started.
        </p>

        <div v-if="activeConnectionId">
            <h2 class="text-sm font-semibold text-fg-strong mb-2">Repositories</h2>
            <p class="text-xs text-fg-muted mb-2">
                This lists every repository your GitHub account can access — Boschifai only acts
                on the ones you tick below. Each ticked repo also needs a Docker image that can
                run its test suite (`composer install` + `php artisan test`, matching this
                repo's own conventions) — without one, requirements can't be submitted against it.
            </p>
            <p v-if="repositoriesLoading" class="text-fg-muted">Loading repositories…</p>
            <template v-else>
                <ul class="mb-4 divide-y divide-border rounded-md border border-border bg-surface">
                    <li v-for="repo in repositories" :key="repo.full_name" class="flex flex-wrap items-center gap-3 px-4 py-3">
                        <input
                            :id="`repo-${repo.full_name}`"
                            v-model="selectedRepoNames"
                            type="checkbox"
                            :value="repo.full_name"
                            class="h-4 w-4 rounded-sm border-border text-primary focus:ring-primary"
                        />
                        <label :for="`repo-${repo.full_name}`" class="flex-1 text-sm text-fg">
                            {{ repo.full_name }}
                            <span v-if="repo.connected" class="ml-2 rounded-full bg-success/10 px-2 py-0.5 text-xs font-medium text-success">
                                connected
                            </span>
                            <span v-if="repo.connected && !repo.docker_image" class="ml-2 rounded-full bg-warning/10 px-2 py-0.5 text-xs font-medium text-warning">
                                no test-runner image
                            </span>
                        </label>
                        <input
                            v-if="selectedRepoNames.includes(repo.full_name)"
                            v-model="dockerImages[repo.full_name]"
                            type="text"
                            placeholder="Docker test-runner image, e.g. myorg/myapp:latest"
                            class="w-72 rounded-sm border border-border bg-surface px-2 py-1 text-xs font-mono text-fg transition focus:outline-none focus:border-primary"
                        />
                        <button
                            v-if="repo.connected"
                            type="button"
                            :disabled="removingId === repo.repo_config_id"
                            class="text-fg-muted transition hover:text-danger disabled:opacity-50"
                            title="Remove from Boschifai"
                            @click="confirmRemove(repo)"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4">
                                <path
                                    fill-rule="evenodd"
                                    d="M8.75 1A2.75 2.75 0 0 0 6 3.75v.443c-.795.077-1.584.176-2.365.298a.75.75 0 1 0 .23 1.482l.149-.022.841 10.518A2.75 2.75 0 0 0 7.596 19h4.807a2.75 2.75 0 0 0 2.742-2.53l.841-10.52.149.023a.75.75 0 0 0 .23-1.482 41.03 41.03 0 0 0-2.365-.298V3.75A2.75 2.75 0 0 0 11.25 1h-2.5ZM10 4c.84 0 1.673.025 2.5.075V3.75c0-.69-.56-1.25-1.25-1.25h-2.5c-.69 0-1.25.56-1.25 1.25v.325C8.327 4.025 9.16 4 10 4ZM8.58 7.72a.75.75 0 0 0-1.5.06l.3 7.5a.75.75 0 1 0 1.5-.06l-.3-7.5Zm4.34.06a.75.75 0 1 0-1.5-.06l-.3 7.5a.75.75 0 1 0 1.5.06l.3-7.5Z"
                                    clip-rule="evenodd"
                                />
                            </svg>
                        </button>
                    </li>
                </ul>
                <button
                    type="button"
                    :disabled="saving || !selectedRepoNames.length"
                    class="rounded-sm bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-dark disabled:opacity-50"
                    @click="saveSelection"
                >
                    {{ saving ? 'Saving…' : 'Save selected repositories' }}
                </button>
                <p v-if="saved" class="mt-2 text-sm text-success">
                    Saved — these repositories are now available on the requirement page.
                </p>
            </template>
        </div>

        <confirm-dialog
            :open="!!pendingRemove"
            title="Remove this repository from Boschifai?"
            :message="removeConfirmMessage"
            confirm-label="Remove"
            @confirm="removeRepo"
            @cancel="pendingRemove = null"
        />
    </div>
</template>

<script>
import { mapGetters, mapActions } from 'vuex';
import ConfirmDialog from '../components/ConfirmDialog.vue';

export default {
    name: 'GithubSettingsPage',

    components: { ConfirmDialog },

    data() {
        return {
            connecting: false,
            promptDismissed: false,
            activeConnectionId: null,
            repositoriesLoading: false,
            selectedRepoNames: [],
            // Keyed by full_name — a plain object (not part of the `repositories` list itself)
            // so typing in one input doesn't fight with the list re-rendering on each keystroke.
            dockerImages: {},
            saving: false,
            saved: false,
            pendingRemove: null,
            removingId: null,
            removeError: null,
        };
    },

    computed: {
        ...mapGetters('github', ['connections', 'repositories', 'loading', 'error']),

        // GitHub redirects back here with ?error=... when the user clicks "Cancel" on its own
        // authorization screen — not a bug in this app, so it's shown as a plain notice.
        authorizeDeniedMessage() {
            return this.$route.query.error || null;
        },

        removeConfirmMessage() {
            if (!this.pendingRemove) return '';
            return `"${this.pendingRemove.full_name}"\n\nThis stops Boschifai from using this repository for future requirements — it does not revoke or change your GitHub authorization.`;
        },
    },

    created() {
        this.fetchConnections().then(() => {
            const fromQuery = this.$route.query.connection;
            if (fromQuery) {
                this.selectConnection(Number(fromQuery));
            }
        });
    },

    methods: {
        ...mapActions('github', ['fetchAuthorizeUrl', 'fetchConnections', 'fetchRepositories', 'connectRepositories']),
        ...mapActions('repoConfigs', ['deleteRepoConfig']),

        async connectGithub() {
            this.connecting = true;
            try {
                window.location.href = await this.fetchAuthorizeUrl();
            } catch (e) {
                this.connecting = false;
            }
        },

        async selectConnection(connectionId) {
            this.activeConnectionId = connectionId;
            this.repositoriesLoading = true;
            this.saved = false;
            try {
                const repos = await this.fetchRepositories(connectionId);
                this.selectedRepoNames = (repos || []).filter((r) => r.connected).map((r) => r.full_name);
                const dockerImages = {};
                (repos || []).forEach((r) => {
                    dockerImages[r.full_name] = r.docker_image || '';
                });
                this.dockerImages = dockerImages;
            } finally {
                this.repositoriesLoading = false;
            }
        },

        async saveSelection() {
            this.saving = true;
            this.saved = false;
            try {
                const repositories = this.repositories
                    .filter((r) => this.selectedRepoNames.includes(r.full_name))
                    .map((r) => ({
                        full_name: r.full_name,
                        default_branch: r.default_branch,
                        // Falls back to whatever was already saved if the field was left
                        // untouched, so re-saving a selection never silently wipes out an
                        // image someone configured on an earlier visit.
                        docker_image: this.dockerImages[r.full_name] || r.docker_image || null,
                    }));

                await this.connectRepositories({ connectionId: this.activeConnectionId, repositories });
                this.saved = true;
            } finally {
                this.saving = false;
            }
        },

        confirmRemove(repo) {
            this.removeError = null;
            this.pendingRemove = repo;
        },

        async removeRepo() {
            const repo = this.pendingRemove;
            this.pendingRemove = null;
            this.removingId = repo.repo_config_id;
            try {
                await this.deleteRepoConfig(repo.repo_config_id);
                this.selectedRepoNames = this.selectedRepoNames.filter((name) => name !== repo.full_name);
                await this.fetchRepositories(this.activeConnectionId);
            } catch (e) {
                this.removeError = e.response?.data?.message || e.message;
            } finally {
                this.removingId = null;
            }
        },
    },
};
</script>
