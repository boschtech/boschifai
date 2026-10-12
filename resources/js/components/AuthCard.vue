<template>
    <div class="flex min-h-screen items-center justify-center bg-bg px-4 py-12">
        <div class="w-full max-w-sm">
            <p class="mb-6 text-center text-2xl font-extrabold tracking-tight text-fg-strong">
                Boschif<span class="text-[#D4AF37]">AI</span>
            </p>
            <div class="rounded-lg border border-border bg-surface p-6 shadow-card">
                <h1 class="mb-4 text-lg font-semibold text-fg-strong">{{ title }}</h1>
                <slot />
            </div>
        </div>
    </div>
</template>

<script>
// Shared frame for the guest pages (login, forgot/reset password), shown without the app's
// header and sidebar.
export default {
    name: 'AuthCard',

    props: {
        title: { type: String, required: true },
    },
};
</script>
