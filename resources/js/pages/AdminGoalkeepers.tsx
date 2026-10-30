import { Link, router } from "@inertiajs/react";
import { useState, type FormEvent } from "react";
import { t, useLocale } from "../i18n";
import { AuthStatus } from "../components/KeeperAuthShell";
import "../../css/admin-dashboard.css";

type ProfileRow = { id: number; name: string; photo: string; city: string | null; country: string | null; createdAt: string; updatedAt: string; isPublished: boolean; isSuspended: boolean; adminHidden: boolean; userSuspended: boolean; isPubliclyVisible: boolean; openReports: number };
type Filters = { search: string; country_id: string | number; city_id: string | number; visibility: string; reported: string; sort: string; direction: string };
type Props = { profiles: { data: ProfileRow[]; total: number; prev_page_url: string | null; next_page_url: string | null }; filters: Filters; countries: { id: number; name: string }[]; cities: { id: number; country_id: number; name: string }[] };
const date = (value: string) => new Intl.DateTimeFormat(undefined, { dateStyle: "medium" }).format(new Date(value));

export default function AdminGoalkeepers({ profiles, filters, countries, cities }: Props) {
  useLocale();
  const [form, setForm] = useState(filters);
  const submit = (event: FormEvent) => { event.preventDefault(); router.get("/admin/goalkeepers", form, { preserveState: true, replace: true }); };
  const sortBy = (sort: string) => router.get("/admin/goalkeepers", { ...filters, sort, direction: filters.sort === sort && filters.direction === "asc" ? "desc" : "asc" }, { preserveState: true, replace: true });
  const visibility = (profile: ProfileRow) => profile.isPubliclyVisible ? t("Publik") : profile.adminHidden ? t("Fshehur nga administratori") : profile.userSuspended ? t("Llogaria e pezulluar") : profile.isSuspended ? t("Pezulluar") : profile.isPublished ? t("Joaktiv") : t("I papublikuar");
  return <main className="page-main dashboard-page admin-dashboard">
    <div className="dash-top section"><div><span>{t("ADMINISTRIMI")}</span><h1>{t("Listimet e portierëve")}</h1><p>{t("Kërko profilet dhe kontrollo dukshmërinë e tyre në marketplace.")}</p></div></div>
    <section className="section admin-users-list"><AuthStatus />
      <form className="panel admin-filters" onSubmit={submit}>
        <label>{t("Kërko sipas emrit")}<input value={form.search} onChange={event => setForm({ ...form, search: event.target.value })} maxLength={100} /></label>
        <label>{t("Shteti")}<select value={form.country_id} onChange={event => setForm({ ...form, country_id: event.target.value, city_id: "" })}><option value="">{t("Të gjitha shtetet")}</option>{countries.map(country => <option key={country.id} value={country.id}>{t(country.name)}</option>)}</select></label>
        <label>{t("Qyteti")}<select value={form.city_id} onChange={event => setForm({ ...form, city_id: event.target.value })}><option value="">{t("Të gjitha qytetet")}</option>{cities.map(city => <option key={city.id} value={city.id}>{city.name}</option>)}</select></label>
        <label>{t("Dukshmëria")}<select value={form.visibility} onChange={event => setForm({ ...form, visibility: event.target.value })}><option value="">{t("Të gjitha statuset")}</option><option value="public">{t("Publik")}</option><option value="admin_hidden">{t("Fshehur nga administratori")}</option><option value="unpublished">{t("I papublikuar")}</option><option value="profile_suspended">{t("Profili i pezulluar")}</option><option value="account_suspended">{t("Llogaria e pezulluar")}</option></select></label>
        <label>{t("Raportet e hapura")}<select value={form.reported} onChange={event => setForm({ ...form, reported: event.target.value })}><option value="">{t("Të gjitha")}</option><option value="reported">{t("Me raporte të hapura")}</option><option value="clear">{t("Pa raporte të hapura")}</option></select></label>
        <div className="admin-filter-actions"><button className="btn btn-primary" type="submit">{t("Filtro listimet")}</button><button className="btn btn-outline" type="button" onClick={() => router.get("/admin/goalkeepers")}>{t("Pastro filtrat")}</button></div>
      </form>
      <div className="panel"><div className="panel-head"><div><h2>{t("Profilet e portierëve")}</h2><span>{profiles.total.toLocaleString()} {t("gjithsej")}</span></div></div>
        {profiles.data.length ? <div className="admin-table-wrap"><table className="admin-user-table admin-goalkeeper-table"><thead><tr><th>{t("Foto")}</th><th><button type="button" onClick={() => sortBy("name")}>{t("Portieri") } ↕</button></th><th>{t("Vendndodhja")}</th><th>{t("Dukshmëria")}</th><th>{t("Raporte")}</th><th><button type="button" onClick={() => sortBy("created_at")}>{t("Regjistruar")} ↕</button></th><th><button type="button" onClick={() => sortBy("updated_at")}>{t("Përditësuar")} ↕</button></th></tr></thead><tbody>{profiles.data.map(profile => <tr key={profile.id}><td><img className="admin-keeper-thumb" src={profile.photo} alt="" /></td><td><Link href={`/admin/goalkeepers/${profile.id}`}>{profile.name}</Link></td><td>{profile.city ? `${profile.city}, ${t(profile.country ?? "")}` : t("Pa vendndodhje")}</td><td>{visibility(profile)}</td><td>{profile.openReports}</td><td>{date(profile.createdAt)}</td><td>{date(profile.updatedAt)}</td></tr>)}</tbody></table></div> : <p className="admin-empty">{t("Nuk ka profile që përputhen me filtrat.")}</p>}
        <nav className="admin-pagination" aria-label={t("Faqet e profileve")}>{profiles.prev_page_url && <Link href={profiles.prev_page_url}>{t("Më të rejat")}</Link>}{profiles.next_page_url && <Link href={profiles.next_page_url}>{t("Më të vjetrat")}</Link>}</nav>
      </div>
    </section>
  </main>;
}
