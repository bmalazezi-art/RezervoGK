import { router } from "@inertiajs/react";
import type { Keeper } from "./types/goalkeeper";

export type Page = "home" | "browse" | "profile" | "register" | "dashboard" | "login" | "forgotPassword" | "howItWorks" | "faq" | "contact" | "privacy" | "terms";

export const pageUrls: Record<Page, string> = {
  home: "/", browse: "/browse", profile: "/keepers/1",
  register: "/register", dashboard: "/dashboard",
  login: "/login", forgotPassword: "/forgot-password",
  howItWorks: "/how-it-works", faq: "/faq", contact: "/contact", privacy: "/privacy", terms: "/terms",
};
export function navigate(page: Page) { router.visit(pageUrls[page]); }
export function openKeeper(keeper: Keeper, date?: string) { router.visit(`/keepers/${keeper.id}${date ? `?date=${encodeURIComponent(date)}` : ""}`); }
