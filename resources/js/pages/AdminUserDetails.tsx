import { Link, useForm } from "@inertiajs/react";
import { t, useLocale } from "../i18n";
import { AuthStatus } from "../components/KeeperAuthShell";
import "../../css/admin-dashboard.css";

type Report = { id: number; reason: string; details: string | null; status: string; createdAt: string };
type Profile = { id: number; name: string; country: string | null; city: string | null; isPublished: boolean; isSuspended: boolean; isPubliclyVisible: boolean; weeklyHours: { weekday: number; start: string; end: string }[]; upcomingAvailabilities: string[]; upcomingExceptions: string[] } | null;
type Props = { user: { id: number; name: string; email: string; createdAt: string; updatedAt: string; emailVerifiedAt: string | null; accountType: string; isAdmin: boolean; isSuperAdmin: boolean; isSuspended: boolean }; profile: Profile; reports: Report[]; audit: { id: number; action: string; reason: string; actorName: string | null; createdAt: string }[] };
const date = (value: string) => new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" }).format(new Date(value));
const weekdays = ["E hënë", "E martë", "E mërkurë", "E enjte", "E premte", "E shtunë", "E diel"];

export default function AdminUserDetails({ user, profile, reports, audit }: Props) {
  useLocale();
  const form = useForm({ suspended: !user.isSuspended, reason: "" });
  const changeStatus = () => {
    const action = user.isSuspended ? t("riaktivizosh") : t("pezullosh");
    if (!window.confirm(t("A je i sigurt që dëshiron të :action llogarinë e :name?", { action, name: user.name }))) return;
    const reason = window.prompt(t("Shkruaj arsyen e këtij veprimi (të paktën 10 karaktere)."));
    if (!reason) return;
    form.setData({ suspended: !user.isSuspended, reason });
    form.transform(() => ({ suspended: !user.isSuspended, reason }));
    form.patch(`/admin/users/${user.id}/suspension`, { preserveScroll: true });
  };
  return <main className="page-main dashboard-page admin-dashboard">
    <div className="dash-top section"><div><span>{t("ADMINISTRIMI")}</span><h1>{user.name}</h1><p>{user.email}</p></div><Link className="text-link" href="/admin/users">{t("Kthehu te përdoruesit")}</Link></div>
    <section className="section admin-user-details"><AuthStatus />
      <article className="panel"><div className="panel-head"><div><h2>{t("Informacioni i llogarisë")}</h2><span>{user.isSuspended ? t("Pezulluar") : t("Aktive")}</span></div></div>
        <dl className="admin-detail-grid"><div><dt>{t("Email")}</dt><dd>{user.email}</dd></div><div><dt>{t("Lloji i llogarisë")}</dt><dd>{user.accountType === "admin" ? t("Administrator") : user.accountType === "goalkeeper" ? t("Portier") : t("Përdorues")}</dd></div><div><dt>{t("Regjistruar më")}</dt><dd>{date(user.createdAt)}</dd></div><div><dt>{t("Email i verifikuar")}</dt><dd>{user.emailVerifiedAt ? date(user.emailVerifiedAt) : t("Jo")}</dd></div><div><dt>{t("Përditësuar më")}</dt><dd>{date(user.updatedAt)}</dd></div></dl>
        {user.isAdmin || user.isSuperAdmin ? <p className="admin-protected-note">{user.isSuperAdmin ? t("Llogaria e Super Admin është e mbrojtur nga pezullimi dhe fshirja.") : t("Llogaritë e administratorëve janë të mbrojtura nga pezullimi.")}</p> : <button className={`btn ${user.isSuspended ? "btn-primary" : "btn-outline"}`} type="button" onClick={changeStatus} disabled={form.processing}>{user.isSuspended ? t("Riaktivizo llogarinë") : t("Pezullo llogarinë")}</button>}
      </article>
      <article className="panel"><div className="panel-head"><div><h2>{t("Profili i portierit")}</h2></div></div>
        {profile ? <><dl className="admin-detail-grid"><div><dt>{t("Profili")}</dt><dd><Link href={`/keepers/${profile.id}`} target="_blank">{profile.name}</Link></dd></div><div><dt>{t("Vendndodhja")}</dt><dd>{profile.city ? `${profile.city}, ${profile.country}` : t("Pa vendndodhje")}</dd></div><div><dt>{t("Listimi")}</dt><dd>{profile.isPubliclyVisible ? t("Publik dhe aktiv") : profile.isSuspended ? t("Pezulluar") : profile.isPublished ? t("Publikuar, por jo aktiv") : t("I papublikuar")}</dd></div></dl><h3>{t("Orari javor")}</h3>{profile.weeklyHours.length ? <ul className="admin-simple-list">{profile.weeklyHours.map(hour => <li key={hour.weekday}><span>{t(weekdays[hour.weekday] ?? String(hour.weekday))}</span><strong>{hour.start}–{hour.end}</strong></li>)}</ul> : <p className="admin-empty">{t("Nuk ka orar javor të ruajtur.")}</p>}<h3>{t("Disponueshmëri e ardhshme")}</h3><p>{profile.upcomingAvailabilities.length ? profile.upcomingAvailabilities.join(", ") : t("Nuk ka data të shtuara.")}</p><h3>{t("Përjashtime të ardhshme")}</h3><p>{profile.upcomingExceptions.length ? profile.upcomingExceptions.join(", ") : t("Nuk ka përjashtime të shtuara.")}</p></> : <p className="admin-empty">{t("Ky përdorues nuk ka profil portieri.")}</p>}
      </article>
      <article className="panel"><div className="panel-head"><div><h2>{t("Raportet e lidhura")}</h2><span>{reports.length}</span></div></div>{reports.length ? <ul className="admin-record-list">{reports.map(report => <li key={report.id}><div><strong>{t(report.reason)} · {t(report.status)}</strong>{report.details && <span>{report.details}</span>}</div><time dateTime={report.createdAt}>{date(report.createdAt)}</time></li>)}</ul> : <p className="admin-empty">{t("Nuk ka raporte për këtë profil.")}</p>}</article>
      <article className="panel"><div className="panel-head"><div><h2>{t("Regjistri i veprimeve administrative")}</h2><span>{audit.length}</span></div></div>{audit.length ? <ul className="admin-record-list">{audit.map(item => <li key={item.id}><div><strong>{item.action === "suspended" ? t("Llogaria u pezullua") : t("Llogaria u riaktivizua")}</strong><span>{item.reason} · {item.actorName ?? t("Administrator i fshirë")}</span></div><time dateTime={item.createdAt}>{date(item.createdAt)}</time></li>)}</ul> : <p className="admin-empty">{t("Nuk ka veprime administrative të regjistruara.")}</p>}</article>
    </section>
  </main>;
}
