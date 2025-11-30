import { App } from 'vue';
import { route } from 'ziggy-js';

export default {
  install(app: App) {
    // Expose `route` as global property on Vue app instance
    app.config.globalProperties.route = route;
  },
};
