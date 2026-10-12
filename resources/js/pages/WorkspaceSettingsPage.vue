<template>
    <div class="max-w-3xl">
        <h1 class="text-xl font-bold text-fg-strong mb-1">Workspace</h1>
        <p class="text-sm text-fg-muted mb-6">
            Everyone in this workspace sees its repositories, runs and usage. Admins and owners also manage repositories and members.
        </p>

        <p v-if="loading && !workspace" class="text-sm text-fg-muted">Loading…</p>
        <p v-else-if="error" class="text-sm text-danger" role="alert">{{ error }}</p>

        <template v-else-if="workspace">
            <section class="mb-8">
                <h2 class="text-sm font-semibold text-fg mb-2">Name</h2>
                <form class="flex gap-2" @submit.prevent="saveName">
                    <input id="workspace-name" v-model="name" type="text" required maxlength="100" :class="field" class="max-w-xs" />
                    <button type="submit" :disabled="busy || name.trim() === workspace.name" :class="secondaryButton">Rename</button>
                </form>
                <p v-if="renameError" class="mt-1 text-sm text-danger" role="alert">{{ renameError }}</p>
            </section>

            <section class="mb-8">
                <h2 class="text-sm font-semibold text-fg mb-2">Invite someone</h2>
                <form class="flex flex-wrap gap-2" @submit.prevent="sendInvite">
                    <input id="invite-email" v-model="inviteEmail" type="email" required placeholder="name@agency.co.za" :class="field" class="max-w-xs" />
                    <select-dropdown id="invite-role" v-model="inviteRole" :options="roleOptions" aria-label="Role" />
                    <button type="submit" :disabled="busy" :class="primaryButton">Send invitation</button>
                </form>
                <p v-if="inviteSent" class="mt-1 text-sm text-success" role="status">Invitation sent to {{ inviteSent }}.</p>
                <p v-if="inviteError" class="mt-1 text-sm text-danger" role="alert">{{ inviteError }}</p>
            </section>

            <section class="mb-8">
                <h2 class="text-sm font-semibold text-fg mb-2">Members</h2>
                <ul class="divide-y divide-border rounded-md border border-border bg-surface">
                    <li v-for="member in members" :key="member.id" class="flex flex-wrap items-center gap-3 px-4 py-3" :data-member="member.email">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-fg">{{ member.name }} <span v-if="member.id === me.id" class="text-xs text-fg-muted">(you)</span></p>
                            <p class="text-xs text-fg-muted">{{ member.email }}</p>
                        </div>
                        <select-dropdown
                            v-if="canChange(member)"
                            :value="member.role"
                            :options="roleOptions"
                            :disabled="busy"
                            :aria-label="`Role for ${member.email}`"
                            @input="changeRole(member, $event)"
                        />
                        <span v-else class="text-sm text-fg-muted">{{ roleLabel(member.role) }}</span>
                        <button v-if="canChange(member)" type="button" :disabled="busy" :class="dangerButton" @click="pendingRemoval = member">Remove</button>
                    </li>
                </ul>
                <p v-if="memberError" class="mt-1 text-sm text-danger" role="alert">{{ memberError }}</p>
            </section>

            <section v-if="invitations.length">
                <h2 class="text-sm font-semibold text-fg mb-2">Pending invitations</h2>
                <ul class="divide-y divide-border rounded-md border border-border bg-surface">
                    <li v-for="invitation in invitations" :key="invitation.id" class="flex flex-wrap items-center gap-3 px-4 py-3" :data-invitation="invitation.email">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm text-fg">{{ invitation.email }}</p>
                            <p class="text-xs text-fg-muted">
                                {{ roleLabel(invitation.role) }}<template v-if="invitation.invited_by"> · invited by {{ invitation.invited_by }}</template>
                                · expires {{ formatDate(invitation.expires_at) }}
                            </p>
                        </div>
                        <button
                            v-if="invitation.role !== 'owner' || isOwner"
                            type="button"
                            :disabled="busy"
                            :class="secondaryButton"
                            @click="revoke(invitation)"
                        >
                            Revoke
                        </button>
                    </li>
                </ul>
            </section>
        </template>

        <confirm-dialog
            :open="!!pendingRemoval"
            title="Remove this member?"
            :message="pendingRemoval ? `${pendingRemoval.name} (${pendingRemoval.email}) will lose access to this workspace.` : ''"
            confirm-label="Remove"
            @confirm="removeMember"
            @cancel="pendingRemoval = null"
        />
    </div>
</template>

<script>
import { mapGetters } from 'vuex';
import ConfirmDialog from '../components/ConfirmDialog.vue';
import SelectDropdown from '../components/SelectDropdown.vue';

const LABELS = { owner: 'Owner', admin: 'Admin', member: 'Member' };

function message(e) {
    const errors = e.response?.data?.errors;
    return (errors && Object.values(errors).flat()[0]) || e.response?.data?.message || 'Something went wrong. Please try again.';
}

export default {
    name: 'WorkspaceSettingsPage',

    components: { ConfirmDialog, SelectDropdown },

    data() {
        return {
            name: '',
            inviteEmail: '',
            inviteRole: 'member',
            busy: false,
            inviteSent: null,
            inviteError: null,
            renameError: null,
            memberError: null,
            pendingRemoval: null,
            field: 'w-full rounded-sm border border-border bg-surface px-3 py-2 text-sm text-fg transition focus:outline-none focus:border-primary',
            primaryButton: 'rounded-sm bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-dark disabled:opacity-50',
            secondaryButton: 'rounded-sm border border-border bg-surface px-4 py-2 text-sm font-medium text-fg transition hover:bg-surface-alt disabled:opacity-50',
            dangerButton: 'rounded-sm border border-border bg-surface px-3 py-2 text-sm font-medium text-fg transition hover:bg-surface-alt hover:text-danger disabled:opacity-50',
        };
    },

    computed: {
        ...mapGetters('workspace', ['workspace', 'members', 'invitations', 'loading', 'error']),
        ...mapGetters('auth', { me: 'user', current: 'currentWorkspace' }),

        isOwner() {
            return this.current?.role === 'owner';
        },

        // Only owners can grant (or take away) the owner role — the server enforces the same.
        grantableRoles() {
            return this.isOwner ? ['member', 'admin', 'owner'] : ['member', 'admin'];
        },

        roleOptions() {
            return this.grantableRoles.map((role) => ({ value: role, label: this.roleLabel(role) }));
        },
    },

    watch: {
        workspace: {
            immediate: true,
            handler(workspace) {
                if (workspace) this.name = workspace.name;
            },
        },
    },

    created() {
        this.$store.dispatch('workspace/fetchWorkspace');
    },

    methods: {
        roleLabel(role) {
            return LABELS[role] ?? role;
        },

        formatDate(iso) {
            return new Date(iso).toLocaleDateString();
        },

        canChange(member) {
            return member.role !== 'owner' || this.isOwner;
        },

        async run(errorKey, action) {
            this.busy = true;
            this[errorKey] = null;
            try {
                await action();
                return true;
            } catch (e) {
                this[errorKey] = message(e);
                return false;
            } finally {
                this.busy = false;
            }
        },

        async saveName() {
            await this.run('renameError', () => this.$store.dispatch('workspace/rename', this.name.trim()));
        },

        async sendInvite() {
            const email = this.inviteEmail.trim();
            this.inviteSent = null;
            if (await this.run('inviteError', () => this.$store.dispatch('workspace/invite', { email, role: this.inviteRole }))) {
                this.inviteSent = email;
                this.inviteEmail = '';
            }
        },

        async revoke(invitation) {
            await this.run('memberError', () => this.$store.dispatch('workspace/revokeInvitation', invitation.id));
        },

        // The menu always shows the member's saved role, so a refused change needs no undo here.
        async changeRole(member, role) {
            await this.run('memberError', () => this.$store.dispatch('workspace/changeRole', { memberId: member.id, role }));
        },

        async removeMember() {
            const member = this.pendingRemoval;
            this.pendingRemoval = null;
            await this.run('memberError', () => this.$store.dispatch('workspace/removeMember', member.id));
        },
    },
};
</script>
