<template>
    <div class="relative" v-click-outside="close" @keydown.esc="close">
        <button
            :id="id"
            type="button"
            :disabled="disabled"
            :aria-label="ariaLabel"
            aria-haspopup="listbox"
            :aria-expanded="String(open)"
            class="flex min-w-[8rem] max-w-xs items-center justify-between gap-2 rounded-md border border-border bg-surface px-3 py-2 text-sm text-fg shadow-sm transition hover:border-primary/50 focus:outline-none focus:ring-2 focus:ring-primary/30 disabled:opacity-50"
            @click="open = !open"
        >
            <span class="truncate">{{ selected ? selected.label : placeholder }}</span>
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 shrink-0 text-fg-muted transition" :class="{ 'rotate-180': open }">
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
            </svg>
        </button>
        <ul v-if="open" role="listbox" class="absolute z-10 mt-1 w-full min-w-[10rem] overflow-hidden rounded-md border border-border bg-surface py-1 shadow-lg">
            <li
                v-for="option in options"
                :key="option.value"
                role="option"
                :aria-selected="String(option.value === value)"
                class="flex cursor-pointer items-center justify-between gap-2 px-3 py-2 text-sm transition hover:bg-surface-alt"
                @click.stop="choose(option.value)"
            >
                <span :class="option.value === value ? 'font-medium text-fg' : 'text-fg-muted'">{{ option.label }}</span>
                <svg v-if="option.value === value" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 shrink-0 text-primary">
                    <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" />
                </svg>
            </li>
        </ul>
    </div>
</template>

<script>
// Single-choice counterpart of the organisation picker on GithubSettingsPage (same button and
// list styling), so menus look like the rest of the app instead of the browser's native <select>.
// Emits `input` only when the choice actually changes.
export default {
    name: 'SelectDropdown',

    directives: {
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

    props: {
        // v-model target: the chosen option's `value`.
        value: { type: [String, Number], default: null },
        // [{ value, label }, ...]
        options: { type: Array, required: true },
        placeholder: { type: String, default: 'Select…' },
        disabled: { type: Boolean, default: false },
        id: { type: String, default: null },
        ariaLabel: { type: String, default: null },
    },

    data() {
        return { open: false };
    },

    computed: {
        selected() {
            return this.options.find((option) => option.value === this.value);
        },
    },

    methods: {
        choose(optionValue) {
            this.open = false;
            if (optionValue !== this.value) this.$emit('input', optionValue);
        },

        close() {
            this.open = false;
        },
    },
};
</script>
