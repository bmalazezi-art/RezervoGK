import { Link, router } from "@inertiajs/react";
import { t, useLocale } from "../i18n";
import "../../css/admin-dashboard.css";

type Place = { country: string | null; city: string | null; searches: number; zeroResults: number; goalkeepersNow: number | null };
type TopProfile = { id: number; name: string; url: string; views: number; whatsapp: number; phone: number };
type Props = {
  period: string;
  demand: { searches: number; zeroResults: number; places: Place[]; formats: { format: string; searches: number }[] };
  contacts: { views: number; whatsapp: number; phone: number; top: TopProfile[] };
};

const percent = (part: number, whole: number) => whole > 0 ? `${Math.round(part / whole * 100)}%` : "–";

export default function AdminInsights({ period, demand, contacts }: Props) {
  useLocale();
  const place = (row: Place) => row.city ? `${row.city}${row.country ? `, ${t(row.country)}` : ""}` : row.country ? `${t(row.country)} · ${t("të gjitha qytetet")}` : t("Pa filtra vendi");
  const maxFormat = Math.max(1, ...demand.formats.map(row => row.searches));

  return <main className="page-main dashboard-page admin-dashboard">
    <div className="dash-top section"><div><span>{t("ADMINISTRIMI")}</span><h1>{t("Kërkesa dhe kontaktet")}</h1><p>{t("Ku kërkojnë organizatorët, ku nuk gjejnë portier dhe sa kontakte marrin profilet.")}</p></div>
      <label className="admin-insights-period">{t("Periudha")}<select value={period} onChange={event => router.get("/admin/insights", { period: event.target.value }, { preserveState: true, replace: true })}><option value="7">{t("7 ditët e fundit")}</option><option value="30">{t("30 ditët e fundit")}</option><option value="90">{t("90 ditët e fundit")}</option></select></label>
    </div>
    <section className="section admin-insights">
      <div className="admin-stat-grid">
        <article className="panel admin-stat"><span>{t("Kërkime")}</span><strong>{demand.searches}</strong></article>
        <article className="panel admin-stat"><span>{t("Kërkime pa asnjë portier")}</span><strong>{demand.zeroResults}</strong><small>{percent(demand.zeroResults, demand.searches)}</small></article>
        <article className="panel admin-stat"><span>{t("Kontakte (WhatsApp + thirrje)")}</span><strong>{contacts.whatsapp + contacts.phone}</strong><small>{t("WhatsApp :whatsapp · Thirrje :phone", { whatsapp: contacts.whatsapp, phone: contacts.phone })}</small></article>
      </div>

      <article className="panel"><div className="panel-head"><div><h2>{t("Ku mungojnë portierët")}</h2><span>{t("Vendet me më shumë kërkime pa rezultat. Këtu ia vlen të gjesh portierë.")}</span></div></div>
        {demand.places.length ? <div className="admin-table-wrap"><table className="admin-user-table"><thead><tr><th>{t("Vendi")}</th><th>{t("Kërkime")}</th><th>{t("Pa rezultat")}</th><th>{t("Portierë tani")}</th></tr></thead><tbody>{demand.places.map((row, index) => <tr key={index} className={row.zeroResults > 0 ? "is-gap" : ""}><td>{place(row)}</td><td>{row.searches}</td><td>{row.zeroResults} <small>({percent(row.zeroResults, row.searches)})</small></td><td>{row.goalkeepersNow ?? "–"}</td></tr>)}</tbody></table></div> : <p className="admin-empty">{t("Ende nuk ka kërkime në këtë periudhë.")}</p>}
      </article>

      <article className="panel"><div className="panel-head"><div><h2>{t("Formatet që kërkohen")}</h2></div></div>
        {demand.formats.length ? <ul className="admin-analytics-bars">{demand.formats.map(row => <li key={row.format}><span>{row.format}</span><div className="admin-analytics-bar-track"><i style={{ width: `${Math.max(2, row.searches / maxFormat * 100)}%` }} /></div><strong>{row.searches}</strong></li>)}</ul> : <p className="admin-empty">{t("Organizatorët ende nuk kanë filtruar sipas formatit.")}</p>}
      </article>

      <article className="panel"><div className="panel-head"><div><h2>{t("Kontaktet sipas profilit")}</h2><span>{t("Shikime të profileve: :views. Kontaktet numërohen nga kartat dhe nga profilet.", { views: contacts.views })}</span></div></div>
        {contacts.top.length ? <div className="admin-table-wrap"><table className="admin-user-table"><thead><tr><th>{t("Portieri")}</th><th>{t("Shikime")}</th><th>WhatsApp</th><th>{t("Thirrje")}</th></tr></thead><tbody>{contacts.top.map(row => <tr key={row.id}><td><Link href={`/admin/goalkeepers/${row.id}`}>{row.name}</Link> <a className="admin-muted-link" href={row.url} target="_blank" rel="noreferrer">↗</a></td><td>{row.views}</td><td>{row.whatsapp}</td><td>{row.phone}</td></tr>)}</tbody></table></div> : <p className="admin-empty">{t("Ende nuk ka shikime profilesh në këtë periudhë.")}</p>}
      </article>
      <p className="admin-system-note">{t("Kërkimet ruhen vetëm si numra ditorë sipas vendit dhe formatit, pa IP, llogari apo të dhëna personale. Kërkimet e administratorëve dhe të robotëve nuk numërohen.")}</p>
    </section>
  </main>;
}
