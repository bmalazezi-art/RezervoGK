import { t, useLocale } from "../i18n";
import { type ReactNode } from "react";
import { Head, Link, router, usePage } from "@inertiajs/react";
import { Header, Footer } from "../components/marketplace";
import { navigate, type Page } from "../navigation";

const pages: Record<string, Page> = {
  Home: "home", Browse: "browse", Profile: "profile",
  Login: "login", ForgotPassword: "forgotPassword", ResetPassword: "forgotPassword",
  AdminUsers: "dashboard", AdminUserDetails: "dashboard", AdminGoalkeepers: "dashboard", AdminGoalkeeperDetails: "dashboard", AdminReportDetails: "dashboard", AdminAnalytics: "dashboard", AdminSystem: "dashboard",
  Register: "register", Dashboard: "dashboard", AdminPayments: "dashboard", AdminReports: "dashboard",
};
export default function MarketplaceLayout({ children }: { children: ReactNode }) {
  useLocale();
  const inertia = usePage<{ pageKey?: Page; platformAnnouncement?: string; auth: { user: { id: number; name: string; email: string } | null; canReviewPayments: boolean; canReviewReports: boolean; canManageSystem: boolean } }>();
  const page = inertia.props.pageKey ?? pages[inertia.component];
  return <div className="app"><Head title="Gjej portier"><meta head-key="description" name="description" content={t("Gjej portier, kontakto drejtpërdrejt me WhatsApp ose telefon për ndeshjen tënde.")} /></Head><Header page={page} setPage={navigate} authenticated={!!inertia.props.auth.user} onLogout={() => router.post("/logout")} />
    {inertia.props.auth.canReviewPayments && <div className="section"><button className="text-link" onClick={() => router.visit("/admin/payments")}>{t("Administrimi i pagesave")}</button></div>}
    {inertia.props.auth.canReviewReports && <nav className="section admin-sidebar" aria-label={t("Administrimi")}>
      <Link href="/admin">{t("Përmbledhja")}</Link><Link href="/admin/users">{t("Përdoruesit")}</Link>
      <Link href="/admin/goalkeepers">{t("Listimet e portierëve")}</Link><Link href="/admin/moderation">{t("Qendra e moderimit")}</Link>
      <Link href="/admin/analytics">{t("Analitika")}</Link>
      {inertia.props.auth.canManageSystem && <Link href="/admin/settings">{t("Administrimi i sistemit")}</Link>}
    </nav>}
    {!!inertia.props.platformAnnouncement && <aside className="section platform-announcement" role="status">{inertia.props.platformAnnouncement}</aside>}
    {children}
    {!["register", "dashboard", "login", "forgotPassword"].includes(page) && <Footer setPage={navigate} />}
  </div>;
}
