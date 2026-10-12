import axios from 'axios';
import { configureAuthHttp } from './lib/authHttp';

window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
configureAuthHttp(window.axios);
