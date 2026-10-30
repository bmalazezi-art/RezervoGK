import { router, useForm } from "@inertiajs/react";
import { useState, type FormEvent } from "react";
import { t, useLocale } from "../i18n";
import { AuthStatus } from "../components/KeeperAuthShell";
import "../../css/admin-dashboard.css";

type Admin = { id: number; name: string; email: string; createdAt: string; isSuperAdmin: boolean };
type Candidate = { id: number; name: string; email: string };
type Audit = { id: number; action: string; previousValues: Record<string, unknown> | null; newValues: Record<string, unknown> | null; reason: string; actorName: string | null; targetName: string | null; createdAt: string };
type Props = { settings: { registrationEnabled: boolean; announcement: string }; admins: Admin[]; candidates: Candidate[]; candidateSearch: string; canManageAdminAccess: boolean; audit: Audit[] };
const date = (value: string) => new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" }).format(new Date(value));

export default function AdminSystem({ settings, admins, candidates, candidateSearch, canManageAdminAccess, audit }: Props) {
  useLocale();
  const [candidate, setCandidate] = useState("");
  const [search, setSearch] = useState(candidateSearch);
  const settingsForm = useForm({ registration_enabled: settings.registrationEnabled, announcement: settings.announcement, reason: "" });
  const adminForm = useForm({ is_admin: true, reason: "" });
  const saveSettings = (event: FormEvent) => {
    event.preventDefault();
    if (!window.confirm(t("Konfirmo ndryshimin e cilësimeve publike."))) return;
    settingsForm.patch("/admin/settings", { preserveScroll: true, onSuccess: () => settingsForm.setData("reason", "") });
  };
  const promote = (event: FormEvent) => {
    event.preventDefault();
    if (!candidate || !window.confirm(t("Konfirmo dhënien e qasjes administrative."))) return;
    adminForm.patch(`/admin/admins/${candidate}`, { preserveScroll: true, onSuccess: () => { setCandidate(""); adminForm.reset(); } });
  };
  const changeAdmin = (admin: Admin) => {
    const reason = window.prompt(t("Shkruaj arsyen për heqjen e qasjes administrative."));
    if (reason === null) return;
    if (reason.trim().length < 10) { window.alert(t("Arsyeja duhet të ketë të paktën 10 karaktere.")); return; }
    if (!window.confirm(t("Konfirmo heqjen e qasjes administrative nga :name.", { name: admin.name }))) return;
    router.patch(`/admin/admins/${admin.id}`, { is_admin: false, reason }, { preserveScroll: true });
  };
  return <main className="page-main dashboard-page admin-dashboard"><div className="section dash-top"><div><span>{t("ADMINISTRIMI")}</span><h1>{t("Qasja dhe cilësimet")}</h1><p>{t("Menaxho administratorët ekzistues dhe dy cilësime publike të platformës.")}</p></div></div>
    <section className="section admin-system-page"><AuthStatus />
      <article className="panel"><div className="panel-head"><div><h2>{t("Modeli aktual i qasjes")}</h2><span>{t("Të gjithë administratorët kanë të njëjtat leje sipas sistemit ekzistues.")}</span></div></div><p className="admin-system-note">{t("Qasja ruhet te fusha is_admin. Kjo faqe nuk krijon role shtesë; raportet, analitika, përdoruesit dhe listimet vazhdojnë të përdorin autorizimin ekzistues.")}</p>
        {canManageAdminAccess && <><ul className="admin-record-list">{admins.map(admin => <li key={admin.id}><div><strong>{admin.name}</strong><span>{admin.email} · {t(admin.isSuperAdmin ? "Super Admin" : "Administrator")}</span></div>{!admin.isSuperAdmin && <button className="btn btn-outline" type="button" onClick={() => changeAdmin(admin)}>{t("Hiq qasjen")}</button>}</li>)}</ul>
          {!admins.length && <p className="admin-empty">{t("Nuk u gjet asnjë administrator.")}</p>}
        </>}
        {!canManageAdminAccess && <p className="admin-protected-note">{t("Vetëm Super Admin mund të menaxhojë qasjen administrative.")}</p>}
      </article>
      {canManageAdminAccess && <form className="panel admin-system-form" onSubmit={promote}><div className="panel-head"><div><h2>{t("Jep qasje administrative")}</h2><span>{t("Zgjidh një llogari ekzistuese që nuk është e pezulluar.")}</span></div></div>
        <div className="admin-candidate-search"><label htmlFor="candidate-search">{t("Kërko llogari sipas emrit ose email-it")}</label><div><input id="candidate-search" type="search" value={search} maxLength={100} onChange={event => setSearch(event.target.value)} /><button className="btn btn-outline" type="button" onClick={() => router.get("/admin/settings", { candidate_search: search }, { preserveState: true, preserveScroll: true, replace: true })}>{t("Kërko")}</button></div></div>
        <label>{t("Llogaria")}<select value={candidate} onChange={event => setCandidate(event.target.value)} required><option value="">{t("Zgjidh përdoruesin")}</option>{candidates.map(user => <option value={user.id} key={user.id}>{user.name} · {user.email}</option>)}</select></label>
        <label>{t("Arsyeja (minimumi 10 karaktere)")}<textarea value={adminForm.data.reason} onChange={event => adminForm.setData("reason", event.target.value)} minLength={10} maxLength={500} required /></label>
        {adminForm.errors.reason && <small className="admin-inline-status">{adminForm.errors.reason}</small>}{adminForm.errors.is_admin && <small className="admin-inline-status">{adminForm.errors.is_admin}</small>}<button className="btn btn-primary" disabled={!candidate || adminForm.processing}>{t("Jep qasje")}</button>
      </form>}
      <form className="panel admin-system-form" onSubmit={saveSettings}><div className="panel-head"><div><h2>{t("Cilësimet publike")}</h2><span>{t("Ndryshimet zbatohen në faqen publike dhe regjistrim.")}</span></div></div>
        <label className="admin-system-checkbox"><input type="checkbox" checked={settingsForm.data.registration_enabled} onChange={event => settingsForm.setData("registration_enabled", event.target.checked)} />{t("Lejo regjistrimet e reja")}</label>
        <label>{t("Njoftim publik (opsional)")}<textarea value={settingsForm.data.announcement} onChange={event => settingsForm.setData("announcement", event.target.value)} maxLength={280} rows={3} /></label>
        <small>{settingsForm.data.announcement.length}/280</small>
        <label>{t("Arsyeja e ndryshimit (minimumi 10 karaktere)")}<textarea value={settingsForm.data.reason} onChange={event => settingsForm.setData("reason", event.target.value)} minLength={10} maxLength={500} required /></label>
        {settingsForm.errors.reason && <small className="admin-inline-status">{settingsForm.errors.reason}</small>}<button className="btn btn-primary" disabled={settingsForm.processing}>{t("Ruaj cilësimet")}</button>
      </form>
      <article className="panel"><div className="panel-head"><div><h2>{t("Historia administrative e sistemit")}</h2><span>{audit.length} · {t("veprimet e fundit")}</span></div></div>
        {audit.length ? <ul className="admin-record-list">{audit.map(row => <li key={row.id}><div><strong>{row.action === "admin_granted" ? t("Qasja administrative u dha") : row.action === "admin_revoked" ? t("Qasja administrative u hoq") : row.action === "super_admin_designated" ? t("Super Admin u caktua") : t("Cilësimet u ndryshuan")}{row.targetName ? ` · ${row.targetName}` : ""}</strong><span>{row.reason} · {row.action === "super_admin_designated" ? t("Veprim sistemi / vendosjeje") : row.actorName ?? t("Administrator i fshirë")}</span><small>{JSON.stringify(row.previousValues)} → {JSON.stringify(row.newValues)}</small></div><time dateTime={row.createdAt}>{date(row.createdAt)}</time></li>)}</ul> : <p className="admin-empty">{t("Nuk ka ende veprime administrative.")}</p>}
      </article>
    </section>
  </main>;
}
