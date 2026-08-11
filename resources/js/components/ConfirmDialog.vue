<template>
    <transition name="fade">
        <div
            v-if="open"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            @click.self="cancel"
        >
            <div class="w-full max-w-sm rounded-lg border border-border bg-surface p-5 shadow-card">
                <h2 class="text-sm font-semibold text-fg-strong mb-2">{{ title }}</h2>
                <p class="whitespace-pre-line text-sm text-fg-muted mb-5">{{ message }}</p>
                <div class="flex justify-end gap-3">
                    <button
                        type="button"
                        class="rounded-sm border border-border px-3 py-1.5 text-sm font-medium text-fg transition hover:bg-surface-alt"
                        @click="cancel"
                    >
                        {{ cancelLabel }}
                    </button>
                    <button
                        type="button"
                        class="rounded-sm px-3 py-1.5 text-sm font-medium text-white transition hover:opacity-90"
                        :class="danger ? 'bg-danger' : 'bg-primary'"
                        @click="confirm"
                    >
                        {{ confirmLabel }}
                    </button>
                </div>
            </div>
        </div>
    </transition>
</template>

<script>
export default {
    name: 'ConfirmDialog',

    props: {
        open: { type: Boolean, default: false },
        title: { type: String, default: 'Are you sure?' },
        message: { type: String, default: '' },
        confirmLabel: { type: String, default: 'Confirm' },
        cancelLabel: { type: String, default: 'Cancel' },
        danger: { type: Boolean, default: true },
    },

    methods: {
        confirm() {
            this.$emit('confirm');
        },

        cancel() {
            this.$emit('cancel');
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
