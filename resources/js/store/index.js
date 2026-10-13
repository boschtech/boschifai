import Vue from 'vue';
import Vuex from 'vuex';
import auth from './modules/auth';
import runs from './modules/runs';
import repoConfigs from './modules/repoConfigs';
import github from './modules/github';
import localRepos from './modules/localRepos';
import usage from './modules/usage';
import workspace from './modules/workspace';
import billing from './modules/billing';

Vue.use(Vuex);

export default new Vuex.Store({
    modules: { auth, runs, repoConfigs, github, localRepos, usage, workspace, billing },
});
