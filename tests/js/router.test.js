import { describe, expect, it } from 'vitest';
import router from '@/router';
import store from '@/store';
import RunCreatePage from '@/pages/RunCreatePage.vue';
import RunShowPage from '@/pages/RunShowPage.vue';
import StandaloneActionCreatePage from '@/pages/StandaloneActionCreatePage.vue';

function route(location) {
    return router.resolve(location).route;
}

describe('router', () => {
    it.each([
        ['home', '/'],
        ['runs.index', '/runs'],
        ['runs.create', '/runs/new'],
        ['runs.create.coverage', '/runs/new/coverage'],
        ['settings.github', '/settings/github'],
        ['tools.requirement', '/tools-requirement'],
        ['standalone.building-skills', '/standalone/building-skills'],
        ['standalone.building-knowledge-base', '/standalone/building-knowledge-base'],
        ['reporting.confidence', '/reporting/confidence-reports'],
        ['reporting.coverage', '/reporting/coverage-reports'],
        ['reporting.test-history', '/reporting/test-history'],
        ['reporting.token-usage', '/reporting/token-usage'],
    ])('serves %s at %s with a page component', (name, path) => {
        const resolved = route({ name });

        expect(resolved.path).toBe(path);
        expect(resolved.matched[0].components.default).toBeTruthy();
    });

    it('uses one form for both requirement flows, fixing the run type per route', () => {
        expect(route({ name: 'runs.create' }).matched[0].components.default).toBe(RunCreatePage);
        expect(route({ name: 'runs.create' }).matched[0].props.default).toEqual({ runType: 'requirement' });
        expect(route({ name: 'runs.create.coverage' }).matched[0].props.default).toEqual({ runType: 'coverage' });
    });

    it('passes the run id to the edit form and the run page', () => {
        const edit = route('/runs/abc/edit');
        expect(edit.name).toBe('runs.edit');
        expect(edit.matched[0].props.default(edit)).toEqual({ editingRunId: 'abc' });

        const show = route('/runs/abc');
        expect(show.name).toBe('runs.show');
        expect(show.matched[0].components.default).toBe(RunShowPage);
        expect(show.matched[0].props.default).toBe(true);
    });

    it('configures each standalone action through route props', () => {
        const skills = route({ name: 'standalone.building-skills' }).matched[0];
        const kb = route({ name: 'standalone.building-knowledge-base' }).matched[0];

        expect(skills.components.default).toBe(StandaloneActionCreatePage);
        expect(skills.props.default).toMatchObject({ runType: 'build_skills', title: 'Build Skills', submitLabel: 'Build skills' });
        expect(kb.props.default).toMatchObject({ runType: 'build_knowledge_base', title: 'Build Knowledge Base' });
    });

    it('marks the GitHub settings page as admin-only', () => {
        expect(route({ name: 'settings.github' }).meta.requiresManage).toBe(true);
    });

    it('opens invitation links for everyone and keeps workspace settings for admins', () => {
        const invitation = route('/invitations/tok-1');
        expect(invitation.name).toBe('invitations.show');
        expect(invitation.meta.public).toBe(true);
        expect(invitation.matched[0].props.default).toBe(true);
        expect(route({ name: 'settings.workspace' }).meta.requiresManage).toBe(true);
    });

    it('uses clean history URLs', () => {
        expect(router.mode).toBe('history');
    });
});

describe('store', () => {
    it('registers every namespaced module', () => {
        expect(Object.keys(store._modules.root._children).sort()).toEqual(['auth', 'github', 'localRepos', 'repoConfigs', 'runs', 'usage', 'workspace']);
        expect(store.getters['runs/list']).toEqual([]);
        expect(store.getters['usage/tokensThisMonth']).toBeNull();
    });
});
