import { router } from "@inertiajs/react";
import { useState, type FormEvent } from "react";
import { t, useLocale } from "../i18n";
import { AuthStatus } from "../components/KeeperAuthShell";
import "../../css/admin-dashboard.css";

type Props = {
  filters: { period: string; date_from: string; date_to: string; country_id: string | number };
  countries: { id: number; name: string }[];
  stats: { totalUsers: number; newUsers: number; totalProfiles: number; newProfiles: number; publicProfiles: number; hiddenProfiles: number; unpublishedProfiles: number; suspendedAccounts: number; totalReports: number; unresolvedReports: number };
  growth: { currentUsers: number; previousUsers: number; currentProfiles: number; previousProfiles: number };
  charts: { labels: string[]; userRegistrations: Record<string, number>; profileRegistrations: Record<string, number>; reportVolume: Record<string, Record<string, number>>; profileCountries: { country: string; profiles: number; active: number }[]; reportStatuses: { status: string; total: number }[] };
  timezone: string;
};

function LineChart({ labels, series }: { labels: string[]; series: { name: string; color: string; values: number[] }[] }) {
  const width = 720, height = 210, left = 35, right = 12, top = 16, bottom = 30;
  const values = series.flatMap(row => row.values);
  const max = Math.max(1, ...values);
  const x = (index: number) => left + (labels.length < 2 ? 0 : index * (width - left - right) / (labels.length - 1));
  const y = (value: number) => top + (height - top - bottom) * (1 - value / max);
  return <div className="admin-chart-scroll"><svg className="admin-line-chart" viewBox={`0 0 ${width} ${height}`} role="img" aria-label={series.map(row => row.name).join(", ")}>
    {[0, 0.5, 1].map(portion => <g key={portion}><line x1={left} x2={width - right} y1={top + (height - top - bottom) * portion} y2={top + (height - top - bottom) * portion} className="admin-chart-gridline" /><text x={left - 7} y={top + (height - top - bottom) * portion + 4} textAnchor="end" className="admin-chart-axis">{Math.round(max * (1 - portion))}</text></g>)}
    {series.map(row => <polyline key={row.name} fill="none" stroke={row.color} strokeWidth="3" strokeLinejoin="round" strokeLinecap="round" points={row.values.map((value, index) => `${x(index)},${y(value)}`).join(" ")} />)}
    {series.map(row => row.values.map((value, index) => <circle key={`${row.name}-${index}`} cx={x(index)} cy={y(value)} r="3" fill={row.color} />))}
    {labels.filter((_, index) => index === 0 || index === labels.length - 1 || (labels.length > 15 && index % Math.ceil(labels.length / 6) === 0)).map(label => { const index = labels.indexOf(label); return <text key={label} x={x(index)} y={height - 7} textAnchor="middle" className="admin-chart-axis">{label}</text>; })}
  </svg></div>;
}

function BarRows({ rows, max, active = false }: { rows: { country: string; profiles: number; active: number }[]; max: number; active?: boolean }) {
  return rows.length ? <ul className="admin-analytics-bars">{rows.map(row => { const value = active ? row.active : row.profiles; return <li key={row.country}><span>{t(row.country)}</span><div className="admin-analytics-bar-track"><i className={active ? "is-active" : ""} style={{ width: `${Math.max(value ? 2 : 0, value / Math.max(max, 1) * 100)}%` }} /></div><strong>{value}</strong></li>; })}</ul> : <p className="admin-empty">{t("Nuk ka të dhëna për këtë periudhë.")}</p>;
}

export default function AdminAnalytics({ filters, countries, stats, growth, charts, timezone }: Props) {
  useLocale();
  const [form, setForm] = useState(filters);
  const apply = (event: FormEvent) => { event.preventDefault(); router.get("/admin/analytics", form, { preserveState: true, replace: true }); };
  const period = form.period;
  const priorGrowth = (current: number, previous: number) => previous === 0 ? (current === 0 ? "0%" : `+${current}`) : `${Math.round((current - previous) / previous * 100)}%`;
  const reportLabels: Record<string, string> = { open: t("Hapur"), reviewed: t("Shqyrtuar"), dismissed: t("Mbyllur") };
  const maxCountries = Math.max(1, ...charts.profileCountries.map(row => row.profiles));
  const maxReports = Math.max(1, ...Object.values(charts.reportVolume).map(row => (row.open ?? 0) + (row.reviewed ?? 0) + (row.dismissed ?? 0)));
  return <main className="page-main dashboard-page admin-dashboard">
    <div className="section dash-top"><div><span>{t("ADMINISTRIMI")}</span><h1>{t("Analitika e platformës")}</h1><p>{t("Përmbledhje e regjistrimeve, listimeve dhe raportimeve.")}</p></div></div>
    <section className="section admin-analytics-page"><AuthStatus />
      <form className="panel admin-filters admin-analytics-filters" onSubmit={apply}>
        <label>{t("Periudha")}<select value={period} onChange={event => setForm({ ...form, period: event.target.value })}><option value="7">{t("7 ditët e fundit")}</option><option value="30">{t("30 ditët e fundit")}</option><option value="90">{t("90 ditët e fundit")}</option><option value="all">{t("Gjithë periudha")}</option><option value="custom">{t("Interval i caktuar")}</option></select></label>
        {period === "custom" && <><label>{t("Nga data (UTC)")}<input type="date" value={form.date_from} onChange={event => setForm({ ...form, date_from: event.target.value })} required /></label><label>{t("Deri në datën (UTC)")}<input type="date" value={form.date_to} onChange={event => setForm({ ...form, date_to: event.target.value })} required /></label></>}
        <label>{t("Shteti i profilit")}<select value={form.country_id} onChange={event => setForm({ ...form, country_id: event.target.value })}><option value="">{t("Të gjitha shtetet")}</option>{countries.map(country => <option key={country.id} value={country.id}>{t(country.name)}</option>)}</select></label>
        <div className="admin-filter-actions"><button type="submit" className="btn btn-primary">{t("Përditëso analitikën")}</button></div>
      </form>
      <p className="admin-analytics-timezone">{t("Datat dhe grupimi kohor përdorin zonën UTC.")}</p>
      <div className="admin-analytics-metrics">
        {[[t("Përdorues të regjistruar"), stats.totalUsers], [t("Regjistrime përdoruesish në periudhë"), stats.newUsers], [t("Profile gjithsej"), stats.totalProfiles], [t("Profile të krijuara në periudhë"), stats.newProfiles], [t("Listime publike aktive"), stats.publicProfiles], [t("Listime të fshehura nga administratori"), stats.hiddenProfiles], [t("Profile të papublikuara"), stats.unpublishedProfiles], [t("Llogari të pezulluara"), stats.suspendedAccounts], [t("Raporte në periudhë"), stats.totalReports], [t("Raporte të hapura në periudhë"), stats.unresolvedReports]].map(([label, value]) => <article className="panel admin-analytics-metric" key={label}><span>{label}</span><strong>{Number(value).toLocaleString()}</strong></article>)}
      </div>
      <div className="admin-analytics-grid">
        <article className="panel admin-analytics-chart"><div className="panel-head"><div><h2>{t("Regjistrimet në kohë")}</h2><span>{t("Përdorues dhe profile")}</span></div></div><div className="admin-chart-legend"><span><i className="legend-users" />{t("Përdorues")}</span><span><i className="legend-profiles" />{t("Profile portierësh")}</span></div><LineChart labels={charts.labels} series={[{ name: t("Përdorues"), color: "#0d3430", values: charts.labels.map(label => charts.userRegistrations[label] ?? 0) }, { name: t("Profile portierësh"), color: "#8eae32", values: charts.labels.map(label => charts.profileRegistrations[label] ?? 0) }]} /></article>
        <article className="panel admin-analytics-chart"><div className="panel-head"><div><h2>{t("Profile sipas shtetit")}</h2><span>{t("Aktive publike krahasuar me totalin")}</span></div></div><BarRows rows={charts.profileCountries} max={maxCountries} /><div className="admin-chart-legend"><span><i className="legend-profiles" />{t("Profile gjithsej")}</span><span><i className="legend-active" />{t("Aktive publike")}</span></div><BarRows rows={charts.profileCountries} max={maxCountries} active /></article>
        <article className="panel admin-analytics-chart"><div className="panel-head"><div><h2>{t("Raporte në kohë")}</h2><span>{t("Vëllimi sipas statusit")}</span></div></div><div className="admin-chart-legend">{Object.entries(reportLabels).map(([key, label]) => <span key={key}><i className={`legend-report-${key}`} />{label}</span>)}</div><div className="admin-report-bars">{charts.labels.length ? charts.labels.map(label => { const row = charts.reportVolume[label] ?? {}; return <div className="admin-report-bar-column" key={label} title={`${label}: ${Object.entries(reportLabels).map(([key, text]) => `${text} ${row[key] ?? 0}`).join(", ")}`}><div className="admin-report-bar-stack"><i className="report-open" style={{ height: `${(row.open ?? 0) / maxReports * 100}%` }} /><i className="report-reviewed" style={{ height: `${(row.reviewed ?? 0) / maxReports * 100}%` }} /><i className="report-dismissed" style={{ height: `${(row.dismissed ?? 0) / maxReports * 100}%` }} /></div>{(charts.labels.length < 16 || charts.labels.indexOf(label) % Math.ceil(charts.labels.length / 6) === 0) && <small>{label}</small>}</div>; }) : <p className="admin-empty">{t("Nuk ka raporte në këtë periudhë.")}</p>}</div></article>
        <article className="panel admin-analytics-chart"><div className="panel-head"><div><h2>{t("Shpërndarja e raporteve")}</h2><span>{t("Sipas statusit aktual")}</span></div></div>{charts.reportStatuses.map(row => <div className="admin-report-status-row" key={row.status}><span className={`admin-status-pill is-${row.status}`}>{reportLabels[row.status] ?? row.status}</span><strong>{row.total.toLocaleString()}</strong></div>)}</article>
        <article className="panel admin-analytics-growth"><div className="panel-head"><div><h2>{t("Rritja e fundit")}</h2><span>{period === "all" ? t("30 ditët e fundit kundrejt 30 ditëve para tyre") : t("Periudha e zgjedhur kundrejt periudhës pararendëse")}</span></div></div><div><span>{t("Regjistrime përdoruesish")}</span><strong>{growth.currentUsers.toLocaleString()}</strong><small>{priorGrowth(growth.currentUsers, growth.previousUsers)} · {t("periudha pararendëse")}: {growth.previousUsers.toLocaleString()}</small></div><div><span>{t("Profile të reja")}</span><strong>{growth.currentProfiles.toLocaleString()}</strong><small>{priorGrowth(growth.currentProfiles, growth.previousProfiles)} · {t("periudha pararendëse")}: {growth.previousProfiles.toLocaleString()}</small></div></article>
      </div>
    </section>
  </main>;
}
