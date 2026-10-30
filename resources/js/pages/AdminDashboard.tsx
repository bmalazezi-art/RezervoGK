import { Link } from "@inertiajs/react";
import { t, useLocale } from "../i18n";
import { AuthStatus } from "../components/KeeperAuthShell";
import "../../css/admin-dashboard.css";

type Registration = { id: number; name: string; email: string; createdAt: string };
type Report = { id: number; profileId: number; profileName: string | null; reason: string; createdAt: string };
type GrowthPoint = { date: string; users: number; profiles: number };
type Props = {
  stats: { totalUsers: number; totalProfiles: number; activeProfiles: number; publishedProfiles: number; unpublishedProfiles: number; suspendedUsers: number; hiddenProfiles: number; pendingReports: number; newRegistrations7Days: number; newRegistrations30Days: number };
  profileVisibility: { published: number; unpublished: number };
  growth: GrowthPoint[];
  usersByCountry: { country: string; total: number }[];
  recentRegistrations: Registration[];
  recentReports: Report[];
};

const date = (value: string) => new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" }).format(new Date(value));

function GrowthChart({ points }: { points: GrowthPoint[] }) {
  const width = 720, height = 190, left = 28, right = 12, top = 14, bottom = 28;
  const max = Math.max(1, ...points.flatMap(point => [point.users, point.profiles]));
  const coords = (values: number[]) => values.map((value, index) => {
    const x = left + index * (width - left - right) / Math.max(1, values.length - 1);
    const y = top + (height - top - bottom) * (1 - value / max);
    return `${x},${y}`;
  }).join(" ");
  const labels = [0, 7, 14, 21, points.length - 1].filter((index, position, all) => index >= 0 && all.indexOf(index) === position);
  return <div className="admin-chart-scroll"><svg className="admin-overview-chart" viewBox={`0 0 ${width} ${height}`} role="img" aria-label={t("Regjistrimet ditore të përdoruesve dhe profileve gjatë 30 ditëve të fundit")}>
    {[0, 0.5, 1].map(fraction => <line key={fraction} x1={left} x2={width - right} y1={top + (height - top - bottom) * fraction} y2={top + (height - top - bottom) * fraction} className="admin-chart-gridline" />)}
    <polyline points={coords(points.map(point => point.users))} className="admin-overview-line users" />
    <polyline points={coords(points.map(point => point.profiles))} className="admin-overview-line profiles" />
    {labels.map(index => <text key={points[index]?.date} x={left + index * (width - left - right) / Math.max(1, points.length - 1)} y={height - 5} textAnchor="middle" className="admin-chart-axis">{points[index]?.date.slice(5)}</text>)}
  </svg></div>;
}

export default function AdminDashboard({ stats, profileVisibility, growth, usersByCountry, recentRegistrations, recentReports }: Props) {
  useLocale();
  const maxProfiles = Math.max(1, profileVisibility.published, profileVisibility.unpublished);
  const maxCountry = Math.max(1, ...usersByCountry.map(row => row.total));
  return <main className="page-main dashboard-page admin-dashboard">
    <div className="dash-top section"><div><span>{t("ADMINISTRIMI")}</span><h1>{t("Paneli administrativ")}</h1><p>{t("Përmbledhje e aktivitetit dhe përmbajtjes që kërkon vëmendje.")}</p></div></div>
    <section className="section admin-overview">
      <AuthStatus />
      <div className="admin-overview-metrics">
        {[
          ["Përdorues të regjistruar", stats.totalUsers], ["Profile gjithsej", stats.totalProfiles],
          ["Profile publike aktive", stats.activeProfiles], ["Profile me publikim të zgjedhur", stats.publishedProfiles],
          ["Profile të papublikuara", stats.unpublishedProfiles], ["Llogari të pezulluara", stats.suspendedUsers],
          ["Listime të fshehura", stats.hiddenProfiles], ["Raporte në pritje", stats.pendingReports],
          ["Regjistrime · 7 ditët e fundit", stats.newRegistrations7Days], ["Regjistrime · 30 ditët e fundit", stats.newRegistrations30Days],
        ].map(([label, value]) => <article className="panel admin-overview-metric" key={label}><span>{t(label as string)}</span><strong>{Number(value).toLocaleString()}</strong></article>)}
      </div>
      <div className="admin-grid">
        <section className="panel"><div className="panel-head"><div><h2>{t("Rritja e regjistrimeve")}</h2><span>{t("Përdorues dhe profile gjatë 30 ditëve të fundit")}</span></div><Link className="text-link" href="/admin/analytics">{t("Analitika")}</Link></div>
          {growth.some(point => point.users || point.profiles) ? <><GrowthChart points={growth} /><div className="admin-chart-legend"><span><i className="legend-users" />{t("Përdoruesit")}</span><span><i className="legend-profiles" />{t("Profilet")}</span></div></> : <p className="admin-empty">{t("Nuk ka të dhëna regjistrimesh për 30 ditët e fundit.")}</p>}
        </section>
        <section className="panel"><div className="panel-head"><div><h2>{t("Profile sipas vendit")}</h2><span>{t("Shpërndarja e llogarive me profil portieri")}</span></div></div>
          {usersByCountry.length ? <ul className="admin-country-list admin-country-chart">{usersByCountry.map(row => <li key={row.country}><span>{t(row.country)}</span><div className="admin-visibility-track"><i style={{ width: `${(row.total / maxCountry) * 100}%` }} /></div><strong>{row.total.toLocaleString()}</strong></li>)}</ul> : <p className="admin-empty">{t("Nuk ka ende përdorues.")}</p>}
          <h3 className="admin-visibility-heading">{t("Publikuar kundrejt të papublikuarit")}</h3>
          <div className="admin-visibility-row"><span>{t("Publikuar")}</span><div className="admin-visibility-track"><i style={{ width: `${(profileVisibility.published / maxProfiles) * 100}%` }} /></div><strong>{profileVisibility.published}</strong></div>
          <div className="admin-visibility-row"><span>{t("I papublikuar")}</span><div className="admin-visibility-track"><i className="unpublished" style={{ width: `${(profileVisibility.unpublished / maxProfiles) * 100}%` }} /></div><strong>{profileVisibility.unpublished}</strong></div>
        </section>
        <section className="panel" id="recent-registrations"><div className="panel-head"><div><h2>{t("Regjistrimet e fundit")}</h2><span>{t("6 më të fundit")}</span></div><Link className="text-link" href="/admin/users">{t("Përdoruesit")}</Link></div>
          {recentRegistrations.length ? <ul className="admin-record-list">{recentRegistrations.map(user => <li key={user.id}><div><strong>{user.name}</strong><span>{user.email}</span></div><time dateTime={user.createdAt}>{date(user.createdAt)}</time></li>)}</ul> : <p className="admin-empty">{t("Nuk ka ende regjistrime.")}</p>}
        </section>
        <section className="panel"><div className="panel-head"><div><h2>{t("Raporte për shqyrtim")}</h2><span>{stats.pendingReports.toLocaleString()}</span></div><Link className="text-link" href="/admin/moderation">{t("Hap qendrën e moderimit")}</Link></div>
          {recentReports.length ? <ul className="admin-record-list">{recentReports.map(report => <li key={report.id}><div><strong>{report.profileName ?? t("Profili i fshirë")}</strong><span>{t(report.reason)} · #{report.profileId}</span></div><time dateTime={report.createdAt}>{date(report.createdAt)}</time></li>)}</ul> : <p className="admin-empty">{t("Nuk ka raporte të hapura.")}</p>}
        </section>
        <section className="panel"><div className="panel-head"><div><h2>{t("Veprime të shpejta")}</h2></div></div><nav className="admin-quick-links" aria-label={t("Lidhje administrative")}>
          <Link href="/admin/users"><span>{t("Menaxhimi i përdoruesve")}</span><small>{t("Shiko të gjitha llogaritë")}</small><b aria-hidden="true">→</b></Link>
          <Link href="/admin/goalkeepers"><span>{t("Menaxhimi i listimeve")}</span><small>{t("Shqyrto profilet e portierëve")}</small><b aria-hidden="true">→</b></Link>
          <Link href="/admin/moderation"><span>{t("Qendra e moderimit")}</span><small>{t("Shqyrto raportet dhe listimet")}</small><b aria-hidden="true">→</b></Link>
        </nav></section>
      </div>
    </section>
  </main>;
}
