<template>
    <div>
        <h1 class="text-xl font-bold text-fg-strong mb-1">Tools Requirement</h1>
        <p class="text-sm text-fg-muted mb-6">
            Pulling requirements directly from external tools (e.g. Jira, Monday.com) isn't built yet.
        </p>

        <div class="flex flex-col items-center gap-4 rounded-md border border-border bg-surface p-8 text-center">
            <img :src="imageSrc" alt="Software update in progress" class="max-w-xs w-full" />
            <p class="text-sm text-fg-muted">This feature is coming soon.</p>
        </div>
    </div>
</template>

<script>
export default {
    name: 'ToolsRequirementPage',

    data() {
        return {
            // A bound (not static) src: a static `src="/images/..."` attribute gets parsed by
            // the SFC compiler's asset-url transform into a module import regardless of vite
            // config's `includeAbsolute: false` — this file lives in public/images (served
            // verbatim, see docker/app/router.php's static-file passthrough), not as a bundled
            // module asset, so it must stay a plain runtime string.
            imageSrc: '/images/software-update-in-progress.png',
        };
    },
};
</script>
