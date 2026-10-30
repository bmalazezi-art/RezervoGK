import { Link, router } from "@inertiajs/react";
import { useState, type FormEvent } from "react";
import { t, useLocale } from "../i18n";
import { AuthStatus } from "../components/KeeperAuthShell";
import "../../css/admin-dashboard.css";

type UserRow = { id: number; name: string; email: string; createdAt: string; accountType: string; isSuspended: boolean; country: string | null; city: string | null };
type Filters = { search: string; account_type: string; registered_from: string; registered_to: string; country_id: string | number; city_id: string | number; sort: string; direction: string };
type Props = { users: { data: UserRow[]; prev_page_url: string | null; next_page_url: string | null; total: number }; filters: Filters; countries: { id: number; name: string }[]; cities: { id: number; country_id: number; name: string }[] };
const date = (value: string) => new Intl.DateTimeFormat(undefined, { dateStyle: "medium" }).format(new Date(value));

export default function AdminUsers({ users, filters, countries, cities }: Props) {
  useLocale();
  const [form, setForm] = useState(filters);
  const submit = (event: FormEvent) => {
    event.preventDefault();
    router.get("/admin/users", form, { preserveState: true, replace: true });
  };
  const sortBy = (sort: string) => router.get("/admin/users", { ...filters, sort, direction: filters.sort === sort && filters.direction === "asc" ? "desc" : "asc" }, { preserveState: true, replace: true });
  const accountTypes: Record<string, string> = { admin: t("Administrator"), goalkeeper: t("Portier"), user: t("Përdorues") };
  return <main className="page-main dashboard-page admin-dashboard">
    <div className="dash-top section"><div><span>{t("ADMINISTRIMI")}</span><h1>{t("Menaxhimi i përdoruesve")}</h1><p>{t("Kërko, filtro dhe shqyrto llogaritë e regjistruara.")}</p></div><Link className="text-link" href="/admin">{t("Kthehu te paneli")}</Link></div>
    <section className="section admin-users-list"><AuthStatus />
      <form className="panel admin-filters" onSubmit={submit}>
        <label>{t("Kërko emër ose email")}<input value={form.search} onChange={event => setForm({ ...form, search: event.target.value })} maxLength={100} /></label>
        <label>{t("Lloji i llogarisë")}<select value={form.account_type} onChange={event => setForm({ ...form, account_type: event.target.value })}><option value="">{t("Të gjitha llogaritë")}</option><option value="goalkeeper">{t("Portier")}</option><option value="user">{t("Përdorues")}</option><option value="admin">{t("Administrator")}</option></select></label>
        <label>{t("Regjistruar nga")}<input type="date" value={form.registered_from} onChange={event => setForm({ ...form, registered_from: event.target.value })} /></label>
        <label>{t("Regjistruar deri më")}<input type="date" value={form.registered_to} onChange={event => setForm({ ...form, registered_to: event.target.value })} /></label>
        <label>{t("Shteti")}<select value={form.country_id} onChange={event => setForm({ ...form, country_id: event.target.value, city_id: "" })}><option value="">{t("Të gjitha shtetet")}</option>{countries.map(country => <option key={country.id} value={country.id}>{t(country.name)}</option>)}</select></label>
        <label>{t("Qyteti")}<select value={form.city_id} onChange={event => setForm({ ...form, city_id: event.target.value })}><option value="">{t("Të gjitha qytetet")}</option>{cities.map(city => <option key={city.id} value={city.id}>{city.name}</option>)}</select></label>
        <div className="admin-filter-actions"><button className="btn btn-primary" type="submit">{t("Filtro përdoruesit")}</button><button className="btn btn-outline" type="button" onClick={() => router.get("/admin/users")}>{t("Pastro filtrat")}</button></div>
      </form>
      <div className="panel"><div className="panel-head"><div><h2>{t("Llogaritë")}</h2><span>{users.total.toLocaleString()} {t("gjithsej")}</span></div></div>
        {users.data.length ? <div className="admin-table-wrap"><table className="admin-user-table"><thead><tr><th><button type="button" onClick={() => sortBy("name")}>{t("Emri")} ↕</button></th><th><button type="button" onClick={() => sortBy("email")}>{t("Email") } ↕</button></th><th>{t("Lloji")}</th><th>{t("Vendndodhja")}</th><th><button type="button" onClick={() => sortBy("created_at")}>{t("Regjistruar") } ↕</button></th><th>{t("Statusi")}</th></tr></thead><tbody>{users.data.map(user => <tr key={user.id}><td><Link href={`/admin/users/${user.id}`}>{user.name}</Link></td><td>{user.email}</td><td>{accountTypes[user.accountType] ?? user.accountType}</td><td>{user.city ? `${user.city}, ${user.country}` : t("Pa vendndodhje")}</td><td>{date(user.createdAt)}</td><td>{user.isSuspended ? t("Pezulluar") : t("Aktive")}</td></tr>)}</tbody></table></div> : <p className="admin-empty">{t("Nuk ka përdorues që përputhen me filtrat.")}</p>}
        <nav className="admin-pagination" aria-label={t("Faqet e përdoruesve")}>{users.prev_page_url && <Link href={users.prev_page_url}>{t("Më të rinj")}</Link>}{users.next_page_url && <Link href={users.next_page_url}>{t("Më të vjetër")}</Link>}</nav>
      </div>
    </section>
  </main>;
}
