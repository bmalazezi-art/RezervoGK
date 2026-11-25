import { Link, router, useForm } from "@inertiajs/react";
import { t, useLocale } from "../i18n";
import { AuthStatus } from "../components/KeeperAuthShell";
import "../../css/admin-dashboard.css";

type Report = { id: number; reason: string; details: string | null; status: string; createdAt: string };
type History = { id: number; action: string; reason: string; actorName: string | null; createdAt: string };
type Profile = { id: number; name: string; slug: string; bio: string; photo: string; price: number; currency: string; experienceYears: number; languages: string[]; formats: string[]; city: string | null; country: string | null; user: { id: number; name: string; email: string; isSuspended: boolean } | null; isPublished: boolean; isSuspended: boolean; adminHidden: boolean; isPubliclyVisible: boolean; isFeatured: boolean; isVerified: boolean; createdAt: string; updatedAt: string; weeklyHours: { weekday: number; start: string; end: string }[]; upcomingAvailabilities: string[]; upcomingExceptions: string[] };
type Activity = { totalViews: number; views7Days: number; views30Days: number; whatsappClicks: number; phoneClicks: number };
type Props = { profile: Profile; reports: Report[]; moderationHistory: History[]; activity?: Activity };
const date = (value: string) => new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" }).format(new Date(value));
const weekdays = ["E hënë", "E martë", "E mërkurë", "E enjte", "E premte", "E shtunë", "E diel"];

export default function AdminGoalkeeperDetails({ profile, reports, moderationHistory, activity }: Props) {
  useLocale();
  const form = useForm({ hidden: !profile.adminHidden, reason: "" });
  const moderate = () => {
    const action = profile.adminHidden ? t("rikthesh") : t("fshehësh");
    if (!window.confirm(t("A dëshiron ta :action listimin e :name?", { action, name: profile.name }))) return;
    const reason = window.prompt(t("Shkruaj arsyen e moderimit (të paktën 10 karaktere)."));
    if (!reason) return;
    form.transform(() => ({ hidden: !profile.adminHidden, reason }));
    form.patch(`/admin/goalkeepers/${profile.id}/moderation`, { preserveScroll: true });
  };
  const highlight = (field: "featured" | "verified", value: boolean) => {
    const reason = window.prompt(t("Shkruaj arsyen (të paktën 10 karaktere)."));
    if (!reason || reason.trim().length < 10) { if (reason !== null) window.alert(t("Arsyeja duhet të ketë të paktën 10 karaktere.")); return; }
    router.patch(`/admin/goalkeepers/${profile.id}/highlight`, { field, value, reason }, { preserveScroll: true });
  };
  const historyLabel: Record<string, string> = { hidden: "Listimi u fsheh", restored: "Listimi u rikthye", featured: "U shfaq në krye", unfeatured: "U hoq nga krye", verified: "U shënua i verifikuar", unverified: "U hoq verifikimi" };
  return <main className="page-main dashboard-page admin-dashboard">
    <div className="dash-top section"><div><span>{t("ADMINISTRIMI")}</span><h1>{profile.name}</h1><p>{profile.city ? `${profile.city}, ${t(profile.country ?? "")}` : t("Pa vendndodhje")}</p></div><Link className="text-link" href="/admin/goalkeepers">{t("Kthehu te listimet")}</Link></div>
    <section className="section admin-user-details"><AuthStatus />
      <article className="panel admin-profile-hero"><img className="admin-profile-photo" src={profile.photo} alt={t("Fotografia e :name", { name: profile.name })} /><div className="admin-profile-summary"><span>{profile.isPubliclyVisible ? t("Publik dhe aktiv") : profile.adminHidden ? t("Fshehur nga administratori") : profile.user?.isSuspended ? t("Llogaria e pezulluar") : profile.isSuspended ? t("Profili i pezulluar") : profile.isPublished ? t("Publikuar, por joaktiv") : t("I papublikuar")}</span><h2>{profile.name}</h2><p>{profile.city ? `${profile.city}, ${t(profile.country ?? "")}` : t("Pa vendndodhje")}</p><div className="admin-highlight-actions"><button className={profile.isFeatured ? "btn btn-primary" : "btn btn-outline"} type="button" onClick={() => highlight("featured", !profile.isFeatured)}>{profile.isFeatured ? t("★ Në krye — hiqe") : t("☆ Shfaqe në krye")}</button><button className={profile.isVerified ? "btn btn-primary" : "btn btn-outline"} type="button" onClick={() => highlight("verified", !profile.isVerified)}>{profile.isVerified ? t("✓ I verifikuar — hiqe") : t("Shëno të verifikuar")}</button></div>{profile.adminHidden ? <button className="btn btn-primary" type="button" onClick={moderate} disabled={form.processing}>{t("Rikthe listimin")}</button> : <button className="btn btn-outline" type="button" onClick={moderate} disabled={form.processing}>{t("Fshih listimin")}</button>}</div></article>
      {activity && <article className="panel"><div className="panel-head"><div><h2>{t("Shikimet dhe kontaktet")}</h2><span>{t("Një vizitor numërohet një herë në ditë për çdo profil.")}</span></div></div><dl className="admin-detail-grid"><div><dt>{t("Shikime · 7 ditët e fundit")}</dt><dd>{activity.views7Days}</dd></div><div><dt>{t("Shikime · 30 ditët e fundit")}</dt><dd>{activity.views30Days}</dd></div><div><dt>{t("Shikime gjithsej")}</dt><dd>{activity.totalViews}</dd></div><div><dt>{t("Klikime WhatsApp")}</dt><dd>{activity.whatsappClicks}</dd></div><div><dt>{t("Klikime Thirr")}</dt><dd>{activity.phoneClicks}</dd></div></dl></article>}
      <article className="panel"><div className="panel-head"><div><h2>{t("Informacioni i profilit")}</h2></div></div><dl className="admin-detail-grid"><div><dt>{t("Përvoja")}</dt><dd>{profile.experienceYears} {t("vite")}</dd></div><div><dt>{t("Çmimi")}</dt><dd>{profile.price.toFixed(2)} {profile.currency}</dd></div><div><dt>{t("Gjuhët")}</dt><dd>{profile.languages.join(", ") || t("Nuk janë dhënë")}</dd></div><div><dt>{t("Formatet")}</dt><dd>{profile.formats.join(", ") || t("Nuk janë dhënë")}</dd></div><div><dt>{t("Krijuar më")}</dt><dd>{date(profile.createdAt)}</dd></div><div><dt>{t("Përditësuar më")}</dt><dd>{date(profile.updatedAt)}</dd></div></dl><p className="admin-profile-bio">{profile.bio}</p></article>
      <article className="panel"><div className="panel-head"><div><h2>{t("Llogaria e lidhur")}</h2></div></div>{profile.user ? <p><Link href={`/admin/users/${profile.user.id}`}>{profile.user.name} · {profile.user.email}</Link>{profile.user.isSuspended && <span className="admin-inline-status"> · {t("Llogaria e pezulluar")}</span>}</p> : <p className="admin-empty">{t("Ky profil nuk është lidhur me një llogari përdoruesi.")}</p>}</article>
      <article className="panel"><div className="panel-head"><div><h2>{t("Orari javor")}</h2></div></div>{profile.weeklyHours.length ? <ul className="admin-simple-list">{profile.weeklyHours.map(hour => <li key={hour.weekday}><span>{t(weekdays[hour.weekday - 1] ?? String(hour.weekday))}</span><strong>{hour.start}–{hour.end}</strong></li>)}</ul> : <p className="admin-empty">{t("Nuk ka orar javor të ruajtur.")}</p>}<h3>{t("Disponueshmëri e ardhshme")}</h3><p>{profile.upcomingAvailabilities.length ? profile.upcomingAvailabilities.join(", ") : t("Nuk ka data të shtuara.")}</p><h3>{t("Përjashtime të ardhshme")}</h3><p>{profile.upcomingExceptions.length ? profile.upcomingExceptions.join(", ") : t("Nuk ka përjashtime të shtuara.")}</p></article>
      <article className="panel"><div className="panel-head"><div><h2>{t("Raportet e profilit")}</h2><span>{reports.length}</span></div></div>{reports.length ? <ul className="admin-record-list">{reports.map(report => <li key={report.id}><div><strong>{t(report.reason)} · {t(report.status)}</strong>{report.details && <span>{report.details}</span>}</div><time dateTime={report.createdAt}>{date(report.createdAt)}</time></li>)}</ul> : <p className="admin-empty">{t("Nuk ka raporte për këtë profil.")}</p>}</article>
      <article className="panel"><div className="panel-head"><div><h2>{t("Historia e moderimit")}</h2><span>{moderationHistory.length}</span></div></div>{moderationHistory.length ? <ul className="admin-record-list">{moderationHistory.map(item => <li key={item.id}><div><strong>{t(historyLabel[item.action] ?? item.action)}</strong><span>{item.reason} · {item.actorName ?? t("Administrator i fshirë")}</span></div><time dateTime={item.createdAt}>{date(item.createdAt)}</time></li>)}</ul> : <p className="admin-empty">{t("Nuk ka veprime moderimi.")}</p>}</article>
    </section>
  </main>;
}
