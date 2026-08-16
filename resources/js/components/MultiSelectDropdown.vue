<template>
    <div class="relative" v-click-outside="() => (open = false)">
        <div
            class="flex min-h-[2.375rem] w-full flex-wrap items-center gap-1.5 rounded-sm border border-border bg-surface px-2 py-1.5 shadow-sm transition hover:border-primary/50 focus-within:ring-2 focus-within:ring-primary/30 cursor-pointer"
            role="button"
            tabindex="0"
            @click="open = !open"
            @keydown.enter.prevent="open = !open"
            @keydown.space.prevent="open = !open"
        >
            <span
                v-for="option in selectedOptions"
                :key="option.value"
                class="flex items-center gap-1 rounded-sm bg-primary/10 py-0.5 pl-2 pr-1 text-xs font-medium text-primary-dark"
            >
                {{ option.label }}
                <button
                    type="button"
                    class="rounded-sm p-0.5 text-primary-dark/70 transition hover:bg-primary/20 hover:text-primary-dark"
                    :aria-label="`Remove ${option.label}`"
                    @click.stop="remove(option.value)"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-3 w-3">
                        <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                    </svg>
                </button>
            </span>
            <span v-if="!value.length" class="text-sm text-fg-muted">{{ placeholder }}</span>
            <svg
                xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"
                class="ml-auto h-4 w-4 shrink-0 text-fg-muted transition" :class="{ 'rotate-180': open }"
            >
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
            </svg>
        </div>
        <!-- .stop on each option's click below: without it, the click still bubbles past this
             list up to `document`, where the click-outside listener runs — a real bug caught
             live, since selecting an option removes it from `unselectedOptions` right away,
             leaving the list looking like it had "closed" after every single pick. -->
        <ul v-if="open" class="absolute z-10 mt-1 max-h-48 w-full overflow-y-auto rounded-md border border-border bg-surface py-1 shadow-lg">
            <li
                v-for="option in unselectedOptions"
                :key="option.value"
                class="cursor-pointer px-3 py-2 text-sm text-fg transition hover:bg-surface-alt"
                @click.stop="select(option.value)"
            >
                {{ option.label }}
            </li>
            <li v-if="!options.length" class="px-3 py-2 text-xs text-fg-muted">{{ emptyMessage }}</li>
            <li v-else-if="!unselectedOptions.length" class="px-3 py-2 text-xs text-fg-muted">All options selected.</li>
        </ul>
    </div>
</template>

<script>
// Selected items render as removable chips inside the control itself; the open list only ever
// shows options NOT already selected — picking one moves it out of the list and into the chips,
// rather than leaving it in place with a checkmark next to it.
export default {
    name: 'MultiSelectDropdown',

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
        // v-model target: an array of selected option `value`s.
        value: { type: Array, default: () => [] },
        // [{ value, label }, ...]
        options: { type: Array, required: true },
        placeholder: { type: String, default: 'Select…' },
        emptyMessage: { type: String, default: 'No options available.' },
    },

    data() {
        return { open: false };
    },

    computed: {
        // In selection order (the order the user clicked them), not option-list order — reads
        // more naturally as "what I've added" than an arbitrary re-sort back to list position.
        selectedOptions() {
            return this.value
                .map((v) => this.options.find((option) => option.value === v))
                .filter(Boolean);
        },

        unselectedOptions() {
            return this.options.filter((option) => !this.value.includes(option.value));
        },
    },

    methods: {
        select(optionValue) {
            this.$emit('input', [...this.value, optionValue]);
        },

        remove(optionValue) {
            this.$emit('input', this.value.filter((v) => v !== optionValue));
        },
    },
};
</script>
