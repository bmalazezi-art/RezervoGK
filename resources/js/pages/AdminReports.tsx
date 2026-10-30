import { Link, router } from "@inertiajs/react";
import { useState, type FormEvent } from "react";
import { t, useLocale } from "../i18n";
import { AuthStatus } from "../components/KeeperAuthShell";
import "../../css/admin-dashboard.css";

type Report = { id: number; profileId: number; profileName: string; profileHidden: boolean; userName: string | null; reason: string; details: string | null; status: string; createdAt: string; activityCount: number };
type Filters = { search: string; status: string; reason: string; date_from: string; date_to: string; sort: string; direction: string };
type Props = { reports: { data: Report[]; total: number; prev_page_url: string | null; next_page_url: string | null }; filters: Filters; statuses: string[]; reasons: string[]; basePath?: string };
const date = (value: string) => new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" }).format(new Date(value));
const statusLabel = (status: string) => status === "open" ? t("Hapur") : status === "reviewed" ? t("Shqyrtuar") : t("Mbyllur");
const reasonLabel = (reason: string) => ({ inaccurate: t("Informacion i pasaktë"), inappropriate: t("Përmbajtje e papërshtatshme"), spam: t("Spam ose mashtrim"), other: t("Tjetër") }[reason] ?? reason);

export default function AdminReports({ reports, filters, statuses, reasons, basePath = "/admin/reports" }: Props) {
  useLocale();
  const [form, setForm] = useState(filters);
  const submit = (event: FormEvent) => { event.preventDefault(); router.get(basePath, form, { preserveState: true, replace: true }); };
  const sortBy = (sort: string) => router.get(basePath, { ...filters, sort, direction: filters.sort === sort && filters.direction === "asc" ? "desc" : "asc" }, { preserveState: true, replace: true });
  return <main className="page-main dashboard-page admin-dashboard">
    <div className="section dash-top"><div><span>{t("ADMINISTRIMI")}</span><h1>{basePath === "/admin/moderation" ? t("Qendra e moderimit") : t("Raportet e profileve")}</h1><p>{basePath === "/admin/moderation" ? t("Shqyrto raportet dhe listimet") : t("Kërko dhe shqyrto raportet e dërguara për profilet e portierëve.")}</p></div></div>
    <section className="section"><AuthStatus />
      <form className="panel admin-filters" onSubmit={submit}>
        <label>{t("Kërko raport ose portier")}<input value={form.search} onChange={event => setForm({ ...form, search: event.target.value })} maxLength={100} /></label>
        <label>{t("Statusi")}<select value={form.status} onChange={event => setForm({ ...form, status: event.target.value })}><option value="">{t("Të gjitha statuset")}</option>{statuses.map(status => <option key={status} value={status}>{statusLabel(status)}</option>)}</select></label>
        <label>{t("Arsyeja")}<select value={form.reason} onChange={event => setForm({ ...form, reason: event.target.value })}><option value="">{t("Të gjitha arsyet")}</option>{reasons.map(reason => <option key={reason} value={reason}>{reasonLabel(reason)}</option>)}</select></label>
        <label>{t("Nga data")}<input type="date" value={form.date_from} onChange={event => setForm({ ...form, date_from: event.target.value })} /></label>
        <label>{t("Deri në datën")}<input type="date" value={form.date_to} onChange={event => setForm({ ...form, date_to: event.target.value })} /></label>
        <div className="admin-filter-actions"><button className="btn btn-primary" type="submit">{t("Filtro raportet")}</button><button className="btn btn-outline" type="button" onClick={() => router.get(basePath)}>{t("Pastro filtrat")}</button></div>
      </form>
      <div className="panel"><div className="panel-head"><div><h2>{t("Raportet")}</h2><span>{reports.total.toLocaleString()} {t("gjithsej")}</span></div></div>
        {reports.data.length ? <div className="admin-table-wrap"><table className="admin-user-table"><thead><tr><th>{t("Raporti")}</th><th>{t("Portieri")}</th><th>{t("Arsyeja")}</th><th><button type="button" onClick={() => sortBy("status")}>{t("Statusi")} ↕</button></th><th><button type="button" onClick={() => sortBy("created_at")}>{t("Dërguar më")} ↕</button></th></tr></thead><tbody>{reports.data.map(report => <tr key={report.id}><td><Link href={`${basePath}/${report.id}`}>#{report.id}</Link>{report.activityCount > 0 && <small className="admin-report-activity-count">{report.activityCount} {t("veprime")}</small>}</td><td><Link href={`/admin/goalkeepers/${report.profileId}`}>{report.profileName}</Link>{report.userName && <small className="admin-table-subline">{report.userName}</small>}{report.profileHidden && <small className="admin-table-subline">{t("Listimi i fshehur")}</small>}</td><td>{reasonLabel(report.reason)}</td><td><span className={`admin-status-pill is-${report.status}`}>{statusLabel(report.status)}</span></td><td>{date(report.createdAt)}</td></tr>)}</tbody></table></div> : <p className="admin-empty">{t("Nuk ka raporte që përputhen me filtrat.")}</p>}
        <nav className="admin-pagination" aria-label={t("Faqet e raporteve")}>{reports.prev_page_url && <Link href={reports.prev_page_url}>{t("Më të rejat")}</Link>}{reports.next_page_url && <Link href={reports.next_page_url}>{t("Më të vjetrat")}</Link>}</nav>
      </div>
    </section>
  </main>;
}
