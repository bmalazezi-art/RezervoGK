import "../css/app.css";
import "../css/header.css";
import { createInertiaApp, router } from "@inertiajs/react";
import { createRoot } from "react-dom/client";
import { StrictMode, type ComponentType, type ReactNode } from "react";
import { isLocale, setLocale } from "./i18n";
import MarketplaceLayout from "./layouts/MarketplaceLayout";
import { startCloudflareAnalytics, watchAuthSignal, type AnalyticsRouter } from "./lib/cloudflareAnalytics";

type PageModule = { default: ComponentType & { layout?: (page: ReactNode) => ReactNode } };
const pages = import.meta.glob<PageModule>("./pages/*.tsx");

createInertiaApp({
  resolve: async (name) => {
    const load = pages[`./pages/${name}.tsx`];
    if (!load) throw new Error(`Unknown page: ${name}`);
    const module = await load();
    module.default.layout = (page) => <MarketplaceLayout>{page}</MarketplaceLayout>;
    return module;
  },
  setup({ el, App, props }) {
    setLocale(isLocale(props.initialPage.props.locale) ? props.initialPage.props.locale : "sq");
    const analyticsRouter: AnalyticsRouter = {
      onBefore: (callback) => router.on("before", (event) => callback(event.detail.visit)),
      onNavigate: (callback) => router.on("navigate", (event) => callback(event.detail.page)),
      onBeforeUpdate: (callback) => router.on("beforeUpdate", (event) => callback(event.detail.page)),
    };
    watchAuthSignal(analyticsRouter);
    startCloudflareAnalytics(analyticsRouter);
    createRoot(el).render(<StrictMode><App {...props} /></StrictMode>);
  },
});
