import Vue from 'vue';
import VueRouter from 'vue-router';
import RunsIndexPage from '../pages/RunsIndexPage.vue';
import RunCreatePage from '../pages/RunCreatePage.vue';
import RunShowPage from '../pages/RunShowPage.vue';
import GithubSettingsPage from '../pages/GithubSettingsPage.vue';

Vue.use(VueRouter);

const routes = [
    { path: '/', name: 'runs.index', component: RunsIndexPage },
    { path: '/runs/new', name: 'runs.create', component: RunCreatePage },
    { path: '/runs/:id/edit', name: 'runs.edit', component: RunCreatePage, props: (route) => ({ editingRunId: route.params.id }) },
    { path: '/runs/:id', name: 'runs.show', component: RunShowPage, props: true },
    { path: '/settings/github', name: 'settings.github', component: GithubSettingsPage },
];

export default new VueRouter({
    mode: 'history',
    routes,
});
