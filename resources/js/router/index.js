import Vue from 'vue';
import VueRouter from 'vue-router';
import HomePage from '../pages/HomePage.vue';
import RunsIndexPage from '../pages/RunsIndexPage.vue';
import RunCreatePage from '../pages/RunCreatePage.vue';
import RunShowPage from '../pages/RunShowPage.vue';
import GithubSettingsPage from '../pages/GithubSettingsPage.vue';
import ToolsRequirementPage from '../pages/ToolsRequirementPage.vue';
import StandaloneActionCreatePage from '../pages/StandaloneActionCreatePage.vue';
import ConfidenceReportsPage from '../pages/ConfidenceReportsPage.vue';
import CoverageReportsPage from '../pages/CoverageReportsPage.vue';
import TestHistoryPage from '../pages/TestHistoryPage.vue';

Vue.use(VueRouter);

const routes = [
    // '/' is a standalone summary/landing page — deliberately NOT the runs list. The header
    // logo and "Home" button both point here; the sidebar's "All Runs" link points at
    // 'runs.index' below instead, which now lives at its own path.
    { path: '/', name: 'home', component: HomePage },
    { path: '/runs', name: 'runs.index', component: RunsIndexPage },
    // Two distinct entry points into the same form/component, one per pipeline flow (see
    // SidebarNav's "Pipeline Actions" section) — each fixes `runType` via a static prop instead
    // of the page showing an in-page mode toggle, since the sidebar link itself IS the mode
    // selector now.
    { path: '/runs/new', name: 'runs.create', component: RunCreatePage, props: { runType: 'requirement' } },
    { path: '/runs/new/coverage', name: 'runs.create.coverage', component: RunCreatePage, props: { runType: 'coverage' } },
    { path: '/runs/:id/edit', name: 'runs.edit', component: RunCreatePage, props: (route) => ({ editingRunId: route.params.id }) },
    { path: '/runs/:id', name: 'runs.show', component: RunShowPage, props: true },
    { path: '/settings/github', name: 'settings.github', component: GithubSettingsPage },
    { path: '/tools-requirement', name: 'tools.requirement', component: ToolsRequirementPage },
    // Standalone Actions section (SidebarNav) — a single Claude invocation against a picked
    // repo, no approval gate, no push (see RunStandaloneActionJob). Both routes share one form
    // component, same "fixed prop per route" pattern as 'runs.create'/'runs.create.coverage'.
    {
        path: '/standalone/building-skills',
        name: 'standalone.building-skills',
        component: StandaloneActionCreatePage,
        props: {
            runType: 'build_skills',
            title: 'Build Skills',
            description: 'Boschifai reads the connected repository and writes a customized project skill document — architecture, conventions, coverage requirements — for you to view here.',
            submitLabel: 'Build skills',
        },
    },
    {
        path: '/standalone/building-knowledge-base',
        name: 'standalone.building-knowledge-base',
        component: StandaloneActionCreatePage,
        props: {
            runType: 'build_knowledge_base',
            title: 'Build Knowledge Base',
            description: 'Boschifai reads the connected repository and writes a general understanding document — tech stack, architecture, test conventions, coverage gaps — for you to view here.',
            submitLabel: 'Build knowledge base',
        },
    },
    // Reporting section (SidebarNav) — all three read from the same runs list (see
    // RunListResource) rather than having their own endpoints.
    { path: '/reporting/confidence-reports', name: 'reporting.confidence', component: ConfidenceReportsPage },
    { path: '/reporting/coverage-reports', name: 'reporting.coverage', component: CoverageReportsPage },
    { path: '/reporting/test-history', name: 'reporting.test-history', component: TestHistoryPage },
];

export default new VueRouter({
    mode: 'history',
    routes,
});
