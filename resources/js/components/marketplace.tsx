import { t, useLocale } from "../i18n";
import { Logo } from "./BrandLogo";
import { keeperAvailability, keeperLocation } from "../lib/keeperLabels";
import LanguageSwitcher from "./LanguageSwitcher";
import { formatKeeperPrice } from "../lib/pricing";
import { Link } from "@inertiajs/react";
import { useState } from "react";
import type { Page } from "../navigation";
import { dateFromToday } from "../lib/dates";
import type { Keeper, CityOption, CountryOption } from "../types/goalkeeper";

export const Icon = ({ name, size = 20 }: { name: string; size?: number }) => {
  const paths: Record<string, React.ReactNode> = {
    search: <><circle cx="11" cy="11" r="7" /><path d="m20 20-4-4" /></>,
    pin: <><path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z" /><circle cx="12" cy="10" r="2.5" /></>,
    calendar: <><rect x="3" y="5" width="18" height="16" rx="2" /><path d="M16 3v4M8 3v4M3 10h18" /></>,
    arrow: <path d="M5 12h14m-5-5 5 5-5 5" />,
    check: <path d="m5 12 4 4L19 6" />,
    star: <path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9Z" />,
    heart: <path d="M20.8 5.8a5.5 5.5 0 0 0-7.8 0L12 6.9l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 22l8.8-8.4a5.5 5.5 0 0 0 0-7.8Z" />,
    shield: <path d="M12 22s8-3.6 8-10V5l-8-3-8 3v7c0 6.4 8 10 8 10Z" />,
    users: <><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" /><circle cx="8.5" cy="7" r="4" /><path d="M17 11a4 4 0 0 0 0-8m6 18v-2a4 4 0 0 0-3-3.9" /></>,
    clock: <><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></>,
    filter: <path d="M4 6h16M7 12h10M10 18h4" />,
    back: <path d="m15 18-6-6 6-6" />,
    menu: <path d="M4 7h16M4 12h16M4 17h16" />,
    share: <><circle cx="18" cy="5" r="3" /><circle cx="6" cy="12" r="3" /><circle cx="18" cy="19" r="3" /><path d="m8.7 10.7 6.6-4.4m-6.6 7.4 6.6 4.4" /></>,
    close: <path d="m6 6 12 12M18 6 6 18" />,
    upload: <><path d="M12 16V4m-5 5 5-5 5 5" /><path d="M5 15v4h14v-4" /></>,
    bell: <><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9" /><path d="M10 21h4" /></>,
    phone: <path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.4 19.4 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 2 .7 2.9a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.2-1.2a2 2 0 0 1 2.1-.5c.9.3 1.9.6 2.9.7a2 2 0 0 1 1.7 2Z" />,
    whatsapp: <><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.8 8.8 0 0 1-3.7-.9L3 20.5l1.5-5.1a8.5 8.5 0 1 1 16.5-3.9Z" /><path d="M8.3 7.8c.2-.5.5-.5.8-.5h.5c.2 0 .4 0 .6.5l.8 1.8c.1.3.1.5-.1.7l-.7.8c-.2.2-.3.4-.1.7.5 1 1.2 1.8 2.1 2.4.8.5 1.4.7 1.8.8.3.1.5.1.7-.1l.9-1.1c.2-.3.5-.3.7-.2l2 .9c.3.1.4.2.4.4 0 .2 0 1.1-.5 1.7-.5.6-1.3 1-2.2 1" /></>,
  };
  return <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">{paths[name]}</svg>;
};

export const Button = ({ children, variant = "primary", className = "", onClick, type = "button", disabled = false }: { children: React.ReactNode; variant?: "primary" | "dark" | "outline" | "ghost"; className?: string; onClick?: () => void; type?: "button" | "submit"; disabled?: boolean }) => (
  <button type={type} disabled={disabled} className={`btn btn-${variant} ${className}`} onClick={onClick}>{children}</button>
);

export function Header({ page, setPage, authenticated, onLogout }: { page: Page; setPage: (p: Page) => void; authenticated: boolean; onLogout: () => void }) {
  useLocale();
  const [open, setOpen] = useState(false);
  const go = (p: Page) => { setPage(p); setOpen(false); window.scrollTo({ top: 0, behavior: "smooth" }); };
  const confirmLogout = () => {
    setOpen(false);
    if (window.confirm(t("A dëshiron të dalësh nga llogaria?"))) onLogout();
  };
  return <header className="header">
    <div className="nav-wrap">
      <Logo onClick={() => go("home")} />
      <nav className={open ? "nav-links open" : "nav-links"}>
        <button className={page === "browse" ? "active" : ""} onClick={() => go("browse")}>{t("Gjej portier")}</button>
        {authenticated ? <button className={page === "dashboard" ? "active" : ""} onClick={() => go("dashboard")}>{t("Paneli i portierit")}</button> : <button className="mobile-account-link" onClick={() => go("login")}>{t("Kyçu")}</button>}
        <div className="mobile-actions"><Button variant="primary" onClick={() => authenticated ? confirmLogout() : go("register")}>{authenticated ? t("Dil") : t("Bëhu portier")}</Button></div>
      </nav>
      <div className="nav-actions">
        <LanguageSwitcher />
        {!authenticated && <button className="desktop-account-link" onClick={() => go("login")}>{t("Kyçu")}</button>}
        <Button className="desktop-cta" onClick={() => authenticated ? confirmLogout() : go("register")}>{authenticated ? t("Dil") : t("Bëhu portier")}</Button>
        <button className="menu-btn" onClick={() => setOpen(!open)} aria-label={open ? t("Mbyll menynë") : t("Hap menynë")} aria-expanded={open}><Icon name={open ? "close" : "menu"} /></button>
      </div>
    </div>
  </header>;
}

export function SearchBar({ onSearch, cities, countries, countryId = "", onCountryChange, cityId = "", onCityChange, date, onDateChange, minDate, maxDate }: {
  onSearch: () => void;
  cities?: CityOption[];
  countries?: CountryOption[];
  countryId?: string;
  onCountryChange?: (countryId: string) => void;
  cityId?: string;
  onCityChange?: (cityId: string) => void;
  date?: string;
  onDateChange?: (date: string) => void;
  minDate?: string;
  maxDate?: string;
}) {
  useLocale();
  const visibleCities = cities?.filter(city => !countryId || String(city.country_id) === countryId);
  const today = minDate ?? dateFromToday();
  const tomorrowDate = new Date(`${today}T12:00:00`);
  tomorrowDate.setDate(tomorrowDate.getDate() + 1);
  const tomorrow = `${tomorrowDate.getFullYear()}-${String(tomorrowDate.getMonth() + 1).padStart(2, "0")}-${String(tomorrowDate.getDate()).padStart(2, "0")}`;
  return <div className="search-bar">
    {countries && <label><span><Icon name="pin" size={18} /> {t("Vendi")}</span><select value={countryId} onChange={e => onCountryChange?.(e.target.value)}><option value="">{t("Të gjitha vendet")}</option>{countries.map(country => <option key={country.id} value={country.id}>{t(country.name)}</option>)}</select></label>}
    <label><span><Icon name="pin" size={18} /> {t("Qyteti")}</span><select value={cities ? cityId : undefined} defaultValue={cities ? undefined : "Prishtinë"} onChange={e => onCityChange?.(e.target.value)}>{cities ? <><option value="">{t("Të gjitha qytetet")}</option>{visibleCities?.map(city => <option key={city.id} value={city.id}>{city.name}</option>)}</> : <><option>Prishtinë</option><option>Tiranë</option><option>Shkup</option><option>Prizren</option></>}</select></label>
    <div className="search-date-field"><label htmlFor="match-date"><span><Icon name="calendar" size={18} /> {t("Data e ndeshjes")}</span></label><input id="match-date" aria-label={t("Data e ndeshjes")} type="date" value={date} defaultValue={date === undefined ? dateFromToday(2) : undefined} onChange={event => onDateChange?.(event.target.value)} min={minDate ?? dateFromToday()} max={maxDate} /><span className="date-shortcuts" role="group" aria-label={t("Zgjedh shpejt datën")}><button type="button" className={!date ? "selected" : ""} aria-pressed={!date} onClick={() => onDateChange?.("")}>{t("Çdo datë")}</button><button type="button" className={date === today ? "selected" : ""} aria-pressed={date === today} onClick={() => onDateChange?.(today)}>{t("Sot")}</button><button type="button" className={date === tomorrow ? "selected" : ""} aria-pressed={date === tomorrow} onClick={() => onDateChange?.(tomorrow)}>{t("Nesër")}</button></span></div>
    <Button onClick={onSearch}><Icon name="search" size={19} /> {t("Kërko")}</Button>
  </div>;
}

export function ContactActions({ keeper, compact = false }: { keeper: Keeper; compact?: boolean }) {
  useLocale();
  const message = encodeURIComponent(t("Përshëndetje :name! A je i lirë për një ndeshje futbolli? Çmimi në profilin tënd është :price.", { name: keeper.name, price: formatKeeperPrice(keeper) }));
  const recordContact = (event: "whatsapp" | "phone") => {
    const xsrf = document.cookie.split("; ").find(cookie => cookie.startsWith("XSRF-TOKEN="))?.slice("XSRF-TOKEN=".length);
    void fetch(`/keepers/${keeper.id}/analytics`, {
      method: "POST", credentials: "same-origin", keepalive: true,
      headers: { Accept: "application/json", "Content-Type": "application/json", "X-XSRF-TOKEN": decodeURIComponent(xsrf ?? "") },
      body: JSON.stringify({ event }),
    }).catch(() => undefined);
  };
  return <div className={`contact-actions ${compact ? "compact" : ""}`} onClick={(event) => event.stopPropagation()}>
    {(keeper.whatsapp ?? keeper.phone) && <a className="whatsapp-link" href={`https://wa.me/${keeper.whatsapp ?? keeper.phone}?text=${message}`} target="_blank" rel="noreferrer" onClick={() => recordContact("whatsapp")}><Icon name="whatsapp" size={compact ? 17 : 20} /> WhatsApp</a>}
    {keeper.phone && <a className="call-link" href={`tel:+${keeper.phone}`} onClick={() => recordContact("phone")}><Icon name="phone" size={compact ? 17 : 20} /> {t("Thirr")}</a>}
  </div>;
}

export function KeeperCard({ keeper, onOpen, requestedDate }: { keeper: Keeper; onOpen: () => void; requestedDate?: string }) {
  useLocale();
  return <article className="keeper-card" onClick={onOpen}>
    <div className="keeper-image">
      <img src={keeper.photo} alt={t(":name, portier në :city", { name: keeper.name, city: keeperLocation(keeper) })} />
      <span className={`available-dot${requestedDate && !keeper.availableOnRequestedDate ? " unavailable" : ""}`}><i /> {keeperAvailability(keeper, requestedDate)}</span>
      <button className="heart" aria-label={t("Ruaje")} onClick={(e) => e.stopPropagation()}><Icon name="heart" size={19} /></button>
    </div>
    <div className="keeper-info">
      <div className="card-top"><div><h3>{keeper.name}</h3><p><Icon name="pin" size={15} /> {keeperLocation(keeper)}</p></div><strong>{formatKeeperPrice(keeper)}<small>{t("/ndeshje")}</small></strong></div>
      <div className="card-meta"><span>{t("Formatet e lojës")}</span><span>{keeper.formats.join(" · ")}</span></div>
      <ContactActions keeper={keeper} compact />
    </div>
  </article>;
}

export function Footer({ setPage }: { setPage: (p: Page) => void }) {
  useLocale();
  return <footer><div className="footer-top"><div><Logo onClick={() => setPage("home")} /><p>{t("Futboll më i mirë, një portier në një kohë.")}</p></div><div><strong>{t("PLATFORMA")}</strong><button onClick={() => setPage("browse")}>{t("Gjej portier")}</button><button onClick={() => setPage("register")}>{t("Bëhu portier")}</button></div><div><strong>{t("MBËSHTETJA")}</strong><button onClick={() => setPage("howItWorks")}>{t("Si funksionon")}</button><button onClick={() => setPage("faq")}>{t("Pyetje të shpeshta")}</button><button onClick={() => setPage("contact")}>{t("Kontakti")}</button></div><div className="footer-country"><strong>{t("AKTIV NË")}</strong><span>{t("🇽🇰 Kosovë")}</span><span>{t("🇦🇱 Shqipëri")}</span><span>{t("🇲🇰 Maqedoni e Veriut")}</span></div><div><strong>{t("POLITIKAT")}</strong><Link className="text-inherit no-underline" href="/privacy">{t("Politika e privatësisë")}</Link><Link className="text-inherit no-underline" href="/terms">{t("Kushtet e përdorimit")}</Link></div></div><div className="footer-bottom"><span>© {new Date().getFullYear()} {t("Gjej portier. Të gjitha të drejtat e rezervuara.")}</span></div></footer>;
}

