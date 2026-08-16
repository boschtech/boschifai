<template>
    <nav
        class="sticky w-56 shrink-0 overflow-y-auto border-r border-border bg-surface py-6 pr-4"
        style="top: var(--app-header-height, 73px); height: calc(100vh - var(--app-header-height, 73px))"
    >
        <div v-for="section in sections" :key="section.title" class="mb-6">
            <h2 class="mb-2 rounded-sm bg-primary/10 px-3 py-1.5 text-xs font-bold uppercase tracking-tight text-primary-dark">
                {{ section.title }}
            </h2>
            <ul>
                <li v-for="link in section.links" :key="link.name" class="px-3">
                    <router-link
                        :to="{ name: link.name }"
                        class="nav-link inline-block py-2 text-sm font-medium transition-colors"
                        :class="[isActive(link) ? 'is-active text-primary-dark' : 'text-fg-muted hover:text-primary-dark']"
                    >
                        {{ link.label }}
                    </router-link>
                </li>
            </ul>
        </div>
    </nav>
</template>

<script>
// One entry per pipeline flow the app supports — grouped by what stage of the pipeline they
// belong to, not by page/route mechanics. New flows (e.g. a future non-requirement-driven
// pipeline entry point) get their own section here rather than being tacked onto an existing
// one, so the sidebar stays a map of "what can I start/see," not just a page index.
const SECTIONS = [
    {
        title: 'Runs',
        links: [{ name: 'runs.index', label: 'All Runs' }],
    },
    {
        title: 'Pipeline Actions',
        links: [
            { name: 'runs.create', label: 'Write Requirement' },
            { name: 'runs.create.coverage', label: 'Code Requirement' },
            { name: 'tools.requirement', label: 'Tools Requirement' },
        ],
    },
    {
        title: 'Standalone Actions',
        links: [
            { name: 'standalone.building-skills', label: 'Build Skills' },
            { name: 'standalone.building-knowledge-base', label: 'Build Knowledge Base' },
        ],
    },
    {
        title: 'Reporting',
        links: [
            { name: 'reporting.confidence', label: 'Confidence Reports' },
            { name: 'reporting.coverage', label: 'Coverage Reports' },
            { name: 'reporting.test-history', label: 'Test History' },
        ],
    },
];

export default {
    name: 'SidebarNav',

    data() {
        return { sections: SECTIONS };
    },

    methods: {
        // Exact route-name match rather than vue-router's own exact-active-class: runs.create,
        // runs.create.coverage, and runs.edit all share the same component but are deliberately
        // different routes/links (Edit isn't in this nav at all), so only an exact name match
        // should light up any one of these.
        isActive(link) {
            return this.$route.name === link.name;
        },
    },
};
</script>

<style scoped>
/* Matches bosch-technologies' own header nav-link treatment (css/styles.css's .nav-links a /
   ::after rules): muted by default, highlighted on hover/active, with a thin underline that
   grows in from the left rather than a background box — reused here instead of invented fresh,
   per an explicit ask to make this sidebar's links behave the same way. */
.nav-link {
    position: relative;
}

.nav-link::after {
    content: '';
    position: absolute;
    bottom: 2px;
    left: 0;
    width: 0;
    height: 2px;
    background: #D4AF37;
    transition: width 0.3s ease;
}

.nav-link:hover::after,
.nav-link.is-active::after {
    width: 100%;
}
</style>
