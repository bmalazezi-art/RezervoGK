import "../css/app.css";
import "../css/header.css";
import { createInertiaApp } from "@inertiajs/react";
import { createRoot } from "react-dom/client";
import { StrictMode, type ComponentType, type ReactNode } from "react";
import { setLocale } from "./i18n";
import MarketplaceLayout from "./layouts/MarketplaceLayout";

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
    setLocale(props.initialPage.props.locale === "en" ? "en" : "sq");
    createRoot(el).render(<StrictMode><App {...props} /></StrictMode>);
  },
});
