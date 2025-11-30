import { RouteFunction } from 'ziggy-js';
import 'vue';

declare module 'vue' {
  interface ComponentCustomProperties {
    route: RouteFunction;
  }
}
