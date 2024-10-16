import HomePage from '../pages/Home';
import Update from '../pages/Update';
import PageAbstract from '../pages/PageAbstract';
import { RoutesMatching } from '../types/scriptHandlerTypes';
import { routeHandler } from '../autoUpgrade';

export default class ScriptHandler {
  constructor() {
    const currentRoute = routeHandler.getCurrentRoute();

    if (currentRoute) {
      this.loadScript(currentRoute);
    }
  }

  private currentScript: PageAbstract | undefined;

  private routesMatching: RoutesMatching = {
    'home-page': HomePage,
    'update-page-version-choice': Update
  };

  private loadScript(routeName: string) {
    if (this.routesMatching[routeName]) {
      const pageClass = this.routesMatching[routeName];
      this.currentScript = new pageClass();
      this.currentScript.mount();
    }
  }

  public updateRouteScript(newRoute: string) {
    this.currentScript?.beforeDestroy();
    this.loadScript(newRoute);
  }
}
