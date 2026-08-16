import Vue from 'vue';
import Vuex from 'vuex';
import runs from './modules/runs';
import repoConfigs from './modules/repoConfigs';
import github from './modules/github';
import localRepos from './modules/localRepos';

Vue.use(Vuex);

export default new Vuex.Store({
    modules: { runs, repoConfigs, github, localRepos },
});
