import './bootstrap';
import Vue from 'vue';
import router from './router';
import store from './store';
import App from './App.vue';
import { installAuthInterceptors } from './lib/authHttp';

installAuthInterceptors(window.axios, {
    // The session ended (expired, or signed out in another tab): forget the user and go to login,
    // coming back to the current page afterwards.
    onUnauthenticated() {
        store.commit('auth/SET_USER', null);
        if (!router.currentRoute.meta.guest) {
            router.push({ name: 'login', query: { redirect: router.currentRoute.fullPath } }).catch(() => {});
        }
    },
});

new Vue({
    router,
    store,
    render: (h) => h(App),
}).$mount('#app');
