<template>
    <div class="max-w-2xl">
        <h1 class="text-xl font-bold text-fg-strong mb-1">Connect Repo</h1>
        <p class="text-sm text-fg-muted mb-6">
            Connect a repository from GitHub, or from a checkout already on this machine.
        </p>

        <p v-if="authorizeDeniedMessage" class="text-sm text-warning mb-4">{{ authorizeDeniedMessage }}</p>
        <p v-if="error" class="text-sm text-danger mb-4">{{ error }}</p>
        <p v-if="localApiError" class="text-sm text-danger mb-4">{{ localApiError }}</p>
        <p v-if="removeError" class="text-sm text-danger mb-4">{{ removeError }}</p>

        <details class="group mb-4 rounded-md border border-border bg-surface" open>
            <summary class="flex cursor-pointer select-none list-none items-center justify-between px-4 py-3">
                <span class="text-base font-semibold text-fg-strong">Remote Repository</span>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 text-fg-muted transition group-open:rotate-90">
                    <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd" />
                </svg>
            </summary>

            <div class="border-t border-border p-4">
                <p class="text-xs text-fg-muted mb-3">
                    Connect a GitHub account, then pick which organisation (or your personal
                    account) to select repositories from.
                </p>

                <div v-if="Object.keys(connectedByOrg).length" class="mb-4 rounded-md border border-border bg-surface-alt p-3">
                    <p class="text-xs font-semibold text-fg-strong mb-2">Connected organisations</p>
                    <div v-for="(repos, org) in connectedByOrg" :key="org" class="mb-2 last:mb-0">
                        <p class="text-sm text-fg font-medium">
                            {{ org }}
                            <span class="text-xs font-normal text-fg-muted">
                                — {{ repos.length }} repo{{ repos.length === 1 ? '' : 's' }} selected
                            </span>
                        </p>
                        <p class="text-xs text-fg-muted">{{ repos.map((r) => r.name).join(', ') }}</p>
                    </div>
                </div>

                <div v-if="!connections.length" class="mb-4">
                    <a
                        href="/github/authorize"
                        class="inline-block rounded-sm bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-dark"
                    >
                        Connect GitHub
                    </a>
                </div>

                <p v-if="connectionsLoading" class="text-fg-muted">Loading…</p>

                <div v-else-if="connections.length" class="mb-4">
                    <a href="/github/authorize" class="mb-3 inline-block text-sm font-medium text-primary transition hover:text-primary-dark">
                        + Connect a different GitHub account
                    </a>
                </div>

                <p v-else class="text-sm text-fg-muted">
                    No GitHub accounts connected yet — click "Connect GitHub" above to get started.
                </p>

                <div v-if="connections.length">
                    <p v-if="repositoriesLoading" class="text-fg-muted">Loading repositories…</p>
                    <template v-else>
                        <div v-if="organizations.length" class="mb-2 flex flex-wrap items-center gap-3">
                            <div class="relative" v-click-outside="() => (orgDropdownOpen = false)">
                                <button
                                    type="button"
                                    class="flex min-w-[12rem] max-w-xs items-center justify-between gap-2 rounded-md border border-border bg-surface px-3 py-2 text-sm text-fg shadow-sm transition hover:border-primary/50 focus:outline-none focus:ring-2 focus:ring-primary/30"
                                    @click="orgDropdownOpen = !orgDropdownOpen"
                                >
                                    <span class="truncate">{{ selectedOrgsLabel }}</span>
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 shrink-0 text-fg-muted transition" :class="{ 'rotate-180': orgDropdownOpen }">
                                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                                <ul
                                    v-if="orgDropdownOpen"
                                    class="absolute z-10 mt-1 w-64 overflow-hidden rounded-md border border-border bg-surface py-1 shadow-lg"
                                >
                                    <li
                                        v-for="org in organizations"
                                        :key="org"
                                        class="flex items-center justify-between gap-2 px-3 py-2 text-sm transition hover:bg-surface-alt"
                                    >
                                        <label class="flex flex-1 cursor-pointer items-center gap-2">
                                            <input
                                                v-model="selectedOrgs"
                                                type="checkbox"
                                                :value="org"
                                                class="h-4 w-4 rounded-sm border-border text-primary focus:ring-primary"
                                            />
                                            <span :class="selectedOrgs.includes(org) ? 'font-medium text-fg' : 'text-fg-muted'">{{ org }}</span>
                                        </label>
                                        <button
                                            v-if="(connectedByOrg[org] || []).length"
                                            type="button"
                                            :disabled="removingOrg"
                                            class="shrink-0 text-fg-muted transition hover:text-danger disabled:opacity-50"
                                            title="Remove this organisation and all its connected repos from Boschifai"
                                            @click.stop="confirmRemoveOrg(org)"
                                        >
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-3.5 w-3.5">
                                                <path
                                                    fill-rule="evenodd"
                                                    d="M8.75 1A2.75 2.75 0 0 0 6 3.75v.443c-.795.077-1.584.176-2.365.298a.75.75 0 1 0 .23 1.482l.149-.022.841 10.518A2.75 2.75 0 0 0 7.596 19h4.807a2.75 2.75 0 0 0 2.742-2.53l.841-10.52.149.023a.75.75 0 0 0 .23-1.482 41.03 41.03 0 0 0-2.365-.298V3.75A2.75 2.75 0 0 0 11.25 1h-2.5ZM10 4c.84 0 1.673.025 2.5.075V3.75c0-.69-.56-1.25-1.25-1.25h-2.5c-.69 0-1.25.56-1.25 1.25v.325C8.327 4.025 9.16 4 10 4ZM8.58 7.72a.75.75 0 0 0-1.5.06l.3 7.5a.75.75 0 1 0 1.5-.06l-.3-7.5Zm4.34.06a.75.75 0 1 0-1.5-.06l-.3 7.5a.75.75 0 1 0 1.5.06l.3-7.5Z"
                                                    clip-rule="evenodd"
                                                />
                                            </svg>
                                        </button>
                                    </li>
                                </ul>
                            </div>

                            <span class="text-xs text-fg-muted">
                                {{ connectedCountForSelectedOrgs }} repo(s) connected
                            </span>
                        </div>

                        <p v-if="allExcludedOrganizations.length" class="mb-3 text-xs text-fg-muted">
                            Hidden:
                            <template v-for="(item, i) in allExcludedOrganizations">
                                <button type="button" :key="item.org" class="text-primary hover:underline" @click="restoreOrg(item)">{{ item.org }}</button
                                ><span :key="`sep-${item.org}`" v-if="i < allExcludedOrganizations.length - 1">, </span>
                            </template>
                        </p>

                        <ul class="mb-4 divide-y divide-border rounded-md border border-border bg-surface">
                            <li v-for="repo in filteredRepositories" :key="repo.full_name" class="flex flex-wrap items-center gap-3 px-4 py-3">
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
                                </label>
                                <button
                                    v-if="repo.connected"
                                    type="button"
                                    :disabled="removingId === repo.repo_config_id"
                                    class="text-fg-muted transition hover:text-danger disabled:opacity-50"
                                    title="Remove from Boschifai"
                                    @click="confirmRemove(repo, 'github')"
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
                            <li v-if="!filteredRepositories.length" class="px-4 py-3 text-sm text-fg-muted">No repositories here.</li>
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
            </div>
        </details>

        <details class="group mb-4 rounded-md border border-border bg-surface">
            <summary class="flex cursor-pointer select-none list-none items-center justify-between px-4 py-3">
                <span class="text-base font-semibold text-fg-strong">Local Repository</span>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 text-fg-muted transition group-open:rotate-90">
                    <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd" />
                </svg>
            </summary>

            <div class="border-t border-border p-4">
        <p class="text-xs text-fg-muted mb-3">
            Browse a repo already checked out on this machine — only folders containing a
            <code class="font-mono">.git</code> directory can be selected. Boschifai reads from
            this path directly; it never modifies it.
        </p>

        <nav class="mb-2 flex flex-wrap items-center gap-1 text-xs font-mono text-fg-muted">
            <button type="button" class="text-primary hover:underline" @click="browseLocalPath('')">root</button>
            <template v-for="crumb in pathCrumbs">
                <span :key="`sep-${crumb.path}`">/</span>
                <button type="button" class="text-primary hover:underline" :key="crumb.path" @click="browseLocalPath(crumb.path)">
                    {{ crumb.name }}
                </button>
            </template>
        </nav>

        <p v-if="localLoading" class="text-fg-muted">Loading…</p>
        <ul v-else class="mb-4 max-h-96 divide-y divide-border overflow-y-auto rounded-md border border-border bg-surface">
            <li
                v-for="entry in localEntries"
                :key="entry.path"
                class="flex flex-wrap items-center gap-3 px-4 py-3"
            >
                <input
                    v-if="entry.is_git_repo"
                    :id="`local-${entry.path}`"
                    v-model="selectedLocalPaths"
                    type="checkbox"
                    :value="entry.path"
                    class="h-4 w-4 rounded-sm border-border text-primary focus:ring-primary"
                />
                <span v-else class="inline-block h-4 w-4"></span>
                <button type="button" class="flex-1 text-left text-sm text-fg hover:text-primary" @click="browseLocalPath(entry.path)">
                    {{ entry.name }}
                    <span v-if="entry.is_git_repo" class="ml-2 rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary">
                        git repo
                    </span>
                    <span v-if="entry.connected" class="ml-2 rounded-full bg-success/10 px-2 py-0.5 text-xs font-medium text-success">
                        connected
                    </span>
                </button>
                <button
                    v-if="entry.connected"
                    type="button"
                    :disabled="removingId === entry.repo_config_id"
                    class="text-fg-muted transition hover:text-danger disabled:opacity-50"
                    title="Remove from Boschifai"
                    @click="confirmRemove({ full_name: entry.name, repo_config_id: entry.repo_config_id, path: entry.path }, 'local')"
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
            <li v-if="!localEntries.length" class="px-4 py-3 text-sm text-fg-muted">No subfolders here.</li>
        </ul>

        <button
            type="button"
            :disabled="localSaving || !selectedLocalPaths.length"
            class="rounded-sm bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-dark disabled:opacity-50"
            @click="saveLocalSelection"
        >
            {{ localSaving ? 'Connecting…' : 'Connect selected local repositories' }}
        </button>
        <p v-if="localSaved" class="mt-2 text-sm text-success">
            Connected — these repositories are now available on the requirement page.
        </p>
            </div>
        </details>

        <confirm-dialog
            :open="!!pendingRemove"
            title="Remove this repository from Boschifai?"
            :message="removeConfirmMessage"
            confirm-label="Remove"
            @confirm="removeRepo"
            @cancel="pendingRemove = null"
        />

        <confirm-dialog
            :open="!!pendingRemoveOrg"
            title="Remove this organisation from Boschifai?"
            :message="removeOrgConfirmMessage"
            confirm-label="Remove all"
            @confirm="removeOrg"
            @cancel="pendingRemoveOrg = null"
        />
    </div>
</template>

<script>
import { mapGetters, mapActions } from 'vuex';
import ConfirmDialog from '../components/ConfirmDialog.vue';

export default {
    name: 'GithubSettingsPage',

    components: { ConfirmDialog },

    directives: {
        // Closes the organisation dropdown on an outside click. Scoped to this component
        // rather than a global directive since it's the only dropdown of this kind so far.
        clickOutside: {
            bind(el, binding) {
                el.__clickOutsideHandler__ = (event) => {
                    if (el !== event.target && !el.contains(event.target)) {
                        binding.value(event);
                    }
                };
                document.addEventListener('click', el.__clickOutsideHandler__);
            },
            unbind(el) {
                document.removeEventListener('click', el.__clickOutsideHandler__);
            },
        },
    },

    data() {
        return {
            connectionsLoading: false,
            // Keyed by connection id — every connected account's repos load and stay loaded
            // side by side (see loadConnection), rather than one replacing the last.
            reposByConnection: {},
            excludedByConnection: {},
            loadingConnectionCount: 0,
            selectedRepoNames: [],
            selectedOrgs: [],
            orgDropdownOpen: false,
            saving: false,
            saved: false,
            pendingRemove: null,
            removeSource: 'github',
            removingId: null,
            removeError: null,
            pendingRemoveOrg: null,
            removingOrg: false,

            selectedLocalPaths: [],
            localSaving: false,
            localSaved: false,
        };
    },

    computed: {
        ...mapGetters('github', ['connections', 'error']),
        ...mapGetters('localRepos', {
            localPath: 'path',
            localEntries: 'entries',
            localLoading: 'loading',
            localApiError: 'error',
        }),
        ...mapGetters('repoConfigs', { repoConfigsList: 'list' }),

        repositoriesLoading() {
            return this.loadingConnectionCount > 0;
        },

        // Every connected account's repos, flattened into one list — each repo carries the
        // connectionId it came from (see loadConnection) so save/remove/exclude know which
        // account's token to act through.
        allRepositories() {
            return Object.values(this.reposByConnection).flat();
        },

        // Every org an excluded-org entry belongs to, paired with the connection that owns it —
        // excludeOrganization/restoreOrganization are connection-scoped, and once an org is
        // excluded it no longer appears in allRepositories, so the connectionId has to be
        // tracked alongside it rather than looked up from the repo list.
        allExcludedOrganizations() {
            return Object.entries(this.excludedByConnection).flatMap(([connectionId, orgs]) =>
                orgs.map((org) => ({ org, connectionId: Number(connectionId) }))
            );
        },

        // GitHub redirects back here with ?error=... when the user clicks "Cancel" on its own
        // authorization screen — not a bug in this app, so it's shown as a plain notice.
        authorizeDeniedMessage() {
            return this.$route.query.error || null;
        },

        // Every distinct owner (organisation, or the user's own personal account) across every
        // connected account's repos — derived from full_name rather than a separate API call,
        // since GitHub's repo-listing response already carries this.
        organizations() {
            const owners = new Set(this.allRepositories.map((r) => r.full_name.split('/')[0]));
            return Array.from(owners).sort();
        },

        // More than one organisation can be "active" at once — repos from every checked org
        // show together in the list below, so connecting repos across several orgs (or several
        // connected accounts) in one go doesn't require switching back and forth.
        filteredRepositories() {
            if (!this.selectedOrgs.length) return this.allRepositories;
            return this.allRepositories.filter((r) => this.selectedOrgs.includes(r.full_name.split('/')[0]));
        },

        selectedOrgsLabel() {
            if (!this.selectedOrgs.length) return 'Select organisations';
            if (this.selectedOrgs.length <= 2) return this.selectedOrgs.join(', ');
            return `${this.selectedOrgs.length} organisations selected`;
        },

        // Groups every currently-connected GitHub repo by organisation, so "which repos are
        // selected under which org" is visible without having to reselect each one.
        connectedByOrg() {
            const groups = {};
            this.repoConfigsList
                .filter((r) => r.connected_via_github && r.github_owner)
                .forEach((r) => {
                    (groups[r.github_owner] ||= []).push(r);
                });
            return groups;
        },

        connectedCountForSelectedOrgs() {
            return this.selectedOrgs.reduce((total, org) => total + (this.connectedByOrg[org] || []).length, 0);
        },

        removeOrgConfirmMessage() {
            if (!this.pendingRemoveOrg) return '';
            const repos = this.connectedByOrg[this.pendingRemoveOrg] || [];
            return `"${this.pendingRemoveOrg}" — ${repos.length} repo${repos.length === 1 ? '' : 's'}: ${repos.map((r) => r.name).join(', ')}\n\nThis stops Boschifai from using ALL of these repositories for future requirements — it does not revoke or change your GitHub authorization.`;
        },

        // Turns the current browse path ("sinov8/rams") into clickable breadcrumb segments,
        // each carrying the cumulative path a click on it should navigate back to.
        pathCrumbs() {
            if (!this.localPath) return [];
            let acc = '';
            return this.localPath.split('/').map((name) => {
                acc = acc ? `${acc}/${name}` : name;
                return { name, path: acc };
            });
        },

        removeConfirmMessage() {
            if (!this.pendingRemove) return '';
            const revokeNote = this.removeSource === 'github'
                ? ' — it does not revoke or change your GitHub authorization.'
                : '.';
            return `"${this.pendingRemove.full_name}"\n\nThis stops Boschifai from using this repository for future requirements${revokeNote}`;
        },
    },

    created() {
        this.fetchRepoConfigs();
        this.connectionsLoading = true;
        this.fetchConnections()
            .then((connections) => Promise.all((connections || []).map((c) => this.loadConnection(c.id))))
            .finally(() => {
                this.connectionsLoading = false;
            });
        this.browseLocalPath('');
    },

    methods: {
        ...mapActions('github', ['fetchConnections', 'fetchRepositories', 'connectRepositories', 'excludeOrganization', 'restoreOrganization']),
        ...mapActions('repoConfigs', ['fetchRepoConfigs', 'deleteRepoConfig']),
        ...mapActions('localRepos', { browseLocal: 'browse', connectLocalRepositories: 'connectRepositories' }),

        // Loads (or reloads) exactly one connected account's repos into its own slot in
        // reposByConnection, without disturbing any other account already loaded — this is
        // what lets several GitHub accounts stay "connected" and browsable at once instead of
        // each new one replacing the last.
        async loadConnection(connectionId) {
            this.saved = false;
            this.loadingConnectionCount += 1;
            try {
                const { repositories, excludedOrganizations } = await this.fetchRepositories(connectionId);
                this.$set(
                    this.reposByConnection,
                    connectionId,
                    (repositories || []).map((r) => ({ ...r, connectionId }))
                );
                this.$set(this.excludedByConnection, connectionId, excludedOrganizations || []);

                const selected = new Set(this.selectedRepoNames);
                const orgs = new Set(this.selectedOrgs);
                (repositories || []).forEach((r) => {
                    if (r.connected) selected.add(r.full_name);
                    // Nothing hidden by default — every organisation a newly-loaded account can
                    // see starts selected, so its repos show up in the list right away.
                    orgs.add(r.full_name.split('/')[0]);
                });
                this.selectedRepoNames = Array.from(selected);
                this.selectedOrgs = Array.from(orgs);
            } catch (e) {
                // fetchRepositories already surfaced the error via the store; nothing else to do.
            } finally {
                this.loadingConnectionCount -= 1;
            }
        },

        async restoreOrg({ org, connectionId }) {
            this.removeError = null;
            try {
                await this.restoreOrganization({ connectionId, organization: org });
                await this.loadConnection(connectionId);
            } catch (e) {
                this.removeError = e.response?.data?.message || e.message;
            }
        },

        async saveSelection() {
            this.saving = true;
            this.saved = false;
            try {
                const byConnection = {};
                this.allRepositories
                    .filter((r) => this.selectedRepoNames.includes(r.full_name))
                    .forEach((r) => {
                        (byConnection[r.connectionId] ||= []).push({
                            full_name: r.full_name,
                            default_branch: r.default_branch,
                        });
                    });

                await Promise.all(
                    Object.entries(byConnection).map(([connectionId, repositories]) =>
                        this.connectRepositories({ connectionId: Number(connectionId), repositories })
                    )
                );
                await Promise.all(Object.keys(byConnection).map((connectionId) => this.loadConnection(Number(connectionId))));
                await this.fetchConnections();
                this.saved = true;
            } finally {
                this.saving = false;
            }
        },

        confirmRemove(repo, source) {
            this.removeError = null;
            this.pendingRemove = repo;
            this.removeSource = source;
        },

        async removeRepo() {
            const repo = this.pendingRemove;
            const source = this.removeSource;
            this.pendingRemove = null;
            this.removingId = repo.repo_config_id;
            try {
                await this.deleteRepoConfig(repo.repo_config_id);
                if (source === 'github') {
                    this.selectedRepoNames = this.selectedRepoNames.filter((name) => name !== repo.full_name);
                    await this.loadConnection(repo.connectionId);
                } else {
                    this.selectedLocalPaths = this.selectedLocalPaths.filter((path) => path !== repo.path);
                    await this.browseLocalPath(this.localPath);
                }
            } catch (e) {
                this.removeError = e.response?.data?.message || e.message;
            } finally {
                this.removingId = null;
            }
        },

        confirmRemoveOrg(org) {
            this.removeError = null;
            this.pendingRemoveOrg = org;
        },

        async removeOrg() {
            const org = this.pendingRemoveOrg;
            this.pendingRemoveOrg = null;
            // Looked up now, before the exclude call removes every trace of this org from
            // allRepositories — there'd be nothing left to find it from afterwards.
            const connectionId = this.allRepositories.find((r) => r.full_name.split('/')[0] === org)?.connectionId;
            this.removingOrg = true;
            try {
                await this.excludeOrganization({ connectionId, organization: org });
                this.selectedRepoNames = this.selectedRepoNames.filter((name) => name.split('/')[0] !== org);
                this.selectedOrgs = this.selectedOrgs.filter((o) => o !== org);
                // Refetches with the org now filtered server-side — it and its repos disappear
                // from the picker entirely, not just from the connected count.
                await this.loadConnection(connectionId);
                await this.fetchConnections();
            } catch (e) {
                this.removeError = e.response?.data?.message || e.message;
            } finally {
                this.removingOrg = false;
            }
        },

        async browseLocalPath(path) {
            this.localSaved = false;
            try {
                await this.browseLocal(path);
            } catch (e) {
                return;
            }
            const selected = [...this.selectedLocalPaths];
            this.localEntries.forEach((entry) => {
                if (entry.connected && !selected.includes(entry.path)) {
                    selected.push(entry.path);
                }
            });
            this.selectedLocalPaths = selected;
        },

        async saveLocalSelection() {
            this.localSaving = true;
            this.localSaved = false;
            try {
                const repositories = this.selectedLocalPaths.map((path) => ({ path }));
                await this.connectLocalRepositories(repositories);
                await this.browseLocalPath(this.localPath);
                this.localSaved = true;
            } finally {
                this.localSaving = false;
            }
        },
    },
};
</script>
