import { Link, router, useForm } from "@inertiajs/react";
import { t, useLocale } from "../i18n";
import { AuthStatus } from "../components/KeeperAuthShell";
import { Button } from "../components/marketplace";
import "../../css/admin-dashboard.css";

type Props = {
  report: { id: number; reason: string; details: string | null; status: string; createdAt: string; updatedAt: string; reporter: string };
  profile: { id: number; name: string; city: string | null; country: string | null; adminHidden: boolean; isPublished: boolean; isSuspended: boolean; user: { id: number; name: string; email: string; isSuspended: boolean } | null } | null;
  activity: { id: number; event: string; fromStatus: string | null; toStatus: string | null; note: string | null; actorName: string | null; createdAt: string }[];
  listingHistory: { id: number; action: string; reason: string; actorName: string | null; createdAt: string }[];
  statuses: string[];
  basePath?: string;
};
const date = (value: string) => new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" }).format(new Date(value));
const statusLabel = (status: string) => status === "open" ? t("Hapur") : status === "reviewed" ? t("Shqyrtuar") : t("Mbyllur");
const reasonLabel = (reason: string) => ({ inaccurate: t("Informacion i pasaktë"), inappropriate: t("Përmbajtje e papërshtatshme"), spam: t("Spam ose mashtrim"), other: t("Tjetër") }[reason] ?? reason);

export default function AdminReportDetails({ report, profile, activity, listingHistory, statuses, basePath = "/admin/reports" }: Props) {
  useLocale();
  const form = useForm({ status: report.status, reason: "", note: "" });
  const noteForm = useForm({ note: "" });
  const addNote = (event: React.FormEvent) => { event.preventDefault(); noteForm.transform(data => ({ note: data.note })); noteForm.patch(`${basePath}/${report.id}`, { preserveScroll: true, onSuccess: () => noteForm.reset() }); };
  const moderateListing = () => {
    if (!profile) return;
    const hidden = !profile.adminHidden;
    if (!window.confirm(hidden ? t("Konfirmo fshehjen e listimit. Profili dhe raportet do të ruhen.") : t("Konfirmo rikthimin e listimit."))) return;
    const reason = window.prompt(t("Shkruaj arsyen e moderimit (të paktën 10 karaktere)."));
    if (!reason) return;
    router.post(`${basePath}/${report.id}/listing-moderation`, { hidden, reason }, { preserveScroll: true });
  };
  return <main className="page-main dashboard-page admin-dashboard">
    <div className="section dash-top"><div><span>{t("ADMINISTRIMI")}</span><h1>{t("Raporti #:id", { id: report.id })}</h1><p>{t("Detajet e raportit dhe historia e shqyrtimit.")}</p></div><Link className="text-link" href={basePath}>{t("Kthehu te raportet")}</Link></div>
    <section className="section admin-user-details"><AuthStatus />
      <article className="panel"><div className="panel-head"><div><h2>{reasonLabel(report.reason)}</h2><span className={`admin-status-pill is-${report.status}`}>{statusLabel(report.status)}</span></div></div><dl className="admin-detail-grid"><div><dt>{t("Raportuesi")}</dt><dd>{report.reporter}</dd></div><div><dt>{t("Dërguar më")}</dt><dd>{date(report.createdAt)}</dd></div><div><dt>{t("Përditësuar më")}</dt><dd>{date(report.updatedAt)}</dd></div><div><dt>{t("Statusi aktual")}</dt><dd>{statusLabel(report.status)}</dd></div></dl><h3>{t("Detajet e raportuesit")}</h3><p className="admin-report-details-text">{report.details || t("Raportuesi nuk ka shtuar detaje.")}</p></article>
      <article className="panel"><div className="panel-head"><div><h2>{t("Profili i raportuar")}</h2></div></div>{profile ? <><dl className="admin-detail-grid"><div><dt>{t("Portieri")}</dt><dd><Link href={`/admin/goalkeepers/${profile.id}`}>{profile.name}</Link></dd></div><div><dt>{t("Vendndodhja")}</dt><dd>{profile.city ? `${profile.city}, ${t(profile.country ?? "")}` : t("Pa vendndodhje")}</dd></div><div><dt>{t("Dukshmëria")}</dt><dd>{profile.adminHidden ? t("Fshehur nga administratori") : profile.isPublished && !profile.isSuspended && !profile.user?.isSuspended ? t("Publik") : t("Jo publik")}</dd></div>{profile.user && <div><dt>{t("Llogaria e lidhur")}</dt><dd><Link href={`/admin/users/${profile.user.id}`}>{profile.user.name}</Link></dd></div>}</dl>{profile.adminHidden ? <Button variant="primary" onClick={moderateListing}>{t("Rikthe listimin")}</Button> : <Button variant="outline" onClick={moderateListing}>{t("Fshih listimin")}</Button>}<p className="admin-moderation-hint">{t("Fshehja nuk fshin profilin, llogarinë ose raportet dhe nuk ndryshon preferencën e publikimit.")}</p></> : <p className="admin-empty">{t("Profili i lidhur nuk është më i disponueshëm.")}</p>}</article>
      <article className="panel"><div className="panel-head"><div><h2>{t("Përditëso statusin")}</h2></div></div><form className="admin-report-status-form" onSubmit={event => { event.preventDefault(); if (form.data.status === report.status) return; if (!form.data.reason.trim()) { form.setError("reason", t("Shkruaj arsyen e ndryshimit të statusit.")); return; } if (!window.confirm(t("Konfirmo ndryshimin e statusit të këtij raporti."))) return; form.patch(`${basePath}/${report.id}`, { preserveScroll: true }); }}><label>{t("Statusi")}<select value={form.data.status} onChange={event => form.setData("status", event.target.value)}>{statuses.map(status => <option key={status} value={status}>{statusLabel(status)}</option>)}</select></label><label>{t("Arsyeja e ndryshimit")}<textarea required minLength={10} maxLength={500} value={form.data.reason} onChange={event => form.setData("reason", event.target.value)} /><small>{form.errors.reason}</small></label><Button type="submit" variant="outline" disabled={form.processing || form.data.status === report.status}>{t("Ruaj statusin")}</Button></form></article>
      <article className="panel"><div className="panel-head"><div><h2>{t("Shënim i brendshëm")}</h2></div></div><form className="admin-report-note-form" onSubmit={addNote}><label>{t("Shënimet janë të dukshme vetëm për administratorët.")}<textarea required minLength={5} maxLength={2000} value={noteForm.data.note} onChange={event => noteForm.setData("note", event.target.value)} /></label><Button type="submit" variant="outline" disabled={noteForm.processing || noteForm.data.note.trim().length < 5}>{t("Shto shënim")}</Button></form></article>
      <article className="panel"><div className="panel-head"><div><h2>{t("Historia e moderimit të raportit")}</h2><span>{activity.length}</span></div></div>{activity.length ? <ul className="admin-record-list">{activity.map(item => <li key={item.id}><div><strong>{item.event === "note_added" ? t("Shënim i shtuar") : t("Statusi u ndryshua: :from → :to", { from: statusLabel(item.fromStatus ?? ""), to: statusLabel(item.toStatus ?? "") })}</strong>{item.note && <span>{item.note}</span>}<span>{item.actorName ?? t("Administrator i fshirë")}</span></div><time dateTime={item.createdAt}>{date(item.createdAt)}</time></li>)}</ul> : <p className="admin-empty">{t("Nuk ka ende aktivitet administrativ.")}</p>}</article>
      <article className="panel"><div className="panel-head"><div><h2>{t("Historia e listimit")}</h2><span>{listingHistory.length}</span></div></div>{listingHistory.length ? <ul className="admin-record-list">{listingHistory.map(item => <li key={item.id}><div><strong>{item.action === "hidden" ? t("Listimi u fsheh") : t("Listimi u rikthye")}</strong><span>{item.reason} · {item.actorName ?? t("Administrator i fshirë")}</span></div><time dateTime={item.createdAt}>{date(item.createdAt)}</time></li>)}</ul> : <p className="admin-empty">{t("Nuk ka veprime moderimi të listimit.")}</p>}</article>
    </section>
  </main>;
}
