import { t, useLocale } from "../i18n";
import { defaultPriceLimit, formatPrice, priceCurrencies, type PriceCurrency } from "../lib/pricing";
import { useEffect, useState } from "react";
import { router } from "@inertiajs/react";
import { Button, Icon, SearchBar, KeeperCard } from "../components/marketplace";
import type { BrowseFilters, CityOption, CountryOption, PublicKeeper } from "../types/goalkeeper";
import { openKeeper } from "../navigation";

type BrowseProps = {
  keepers: PublicKeeper[];
  cities: CityOption[];
  countries: CountryOption[];
  formats: string[];
  filters: BrowseFilters;
  today: string;
  maxDate: string;
};

export default function Browse({ keepers, cities, countries, formats, filters, today, maxDate }: BrowseProps) {
  useLocale();
  const [draft, setDraft] = useState(filters);
  const [priceInput, setPriceInput] = useState(String(filters.max_price));
  const [searchCountry, setSearchCountry] = useState(filters.country_id);
  const [searchCity, setSearchCity] = useState(filters.city_id);
  const [searchDate, setSearchDate] = useState(filters.date);
  const [mobileFiltersOpen, setMobileFiltersOpen] = useState(false);
  useEffect(() => { setPriceInput(String(filters.max_price)); setDraft(filters); setSearchCountry(filters.country_id); setSearchCity(filters.city_id); setSearchDate(filters.date); }, [filters]);

  const commit = (next: BrowseFilters) => router.get("/browse", Object.fromEntries(Object.entries(next).filter(([, value]) => value !== "")), {
    preserveState: true, preserveScroll: true, replace: true,
  });
  const apply = (changes: Partial<BrowseFilters>) => {
    const next = { ...draft, ...changes };
    setDraft(next);
    setPriceInput(String(next.max_price));
    if (!mobileFiltersOpen) commit(next);
  };
  const scopePrice = (cityId: string, countryId = "") => {
    const scope = countries.find(country => String(country.id) === countryId) ?? cities.find(city => String(city.id) === cityId);
    const currency = scope?.defaultCurrency ?? "EUR";
    return { currency, max_price: defaultPriceLimit(currency) };
  };
  const reset = () => { setSearchCountry(""); setSearchCity(""); apply({ city_id: "", country_id: "", format: "", max_price: 15, currency: "EUR", date: "" }); };
  const selectedCity = cities.find(city => String(city.id) === filters.city_id);
  const selectedCountry = countries.find(country => String(country.id) === filters.country_id);

  const filterFields = <>
    <div className="filter-title"><h3>{t("Filtrat")}</h3><button onClick={reset}>{t("Pastro")}</button></div>
    <div className="filter-group"><span>{t("Formati i lojës")}</span><div className="chips">{["", ...formats].map(format => <button className={draft.format === format ? "selected" : ""} onClick={() => apply({ format })} key={format}>{format || t("Të gjitha")}</button>)}</div></div>
    <label>{t("Monedha e çmimit")}<select value={draft.currency} onChange={e => { const currency = e.target.value as PriceCurrency; apply({ currency, max_price: defaultPriceLimit(currency) }); }}>{(selectedCountry?.currencies ?? selectedCity?.currencies ?? priceCurrencies).map(currency => <option key={currency} value={currency}>{currency}</option>)}</select></label>
    <label className="range-label"><span>{t("Çmimi maksimal")} <strong>{formatPrice(String(draft.max_price), draft.currency)}</strong></span><input type="range" min="0" max={Math.max(defaultPriceLimit(draft.currency), draft.max_price)} step="0.01" value={draft.max_price} onChange={e => apply({ max_price: Number(e.target.value) })} /><input aria-label={t("Çmimi maksimal në :currency", { currency: draft.currency })} type="number" min="0" max="1000000" step="0.01" value={priceInput} onChange={e => setPriceInput(e.target.value)} onBlur={e => { if (e.target.value && e.target.validity.valid) apply({ max_price: Number(e.target.value) }); else setPriceInput(String(draft.max_price)); }} onKeyDown={e => { if (e.key === "Enter" && e.currentTarget.value && e.currentTarget.reportValidity()) apply({ max_price: Number(e.currentTarget.value) }); }} /><small>{t("Krahasohen vetëm çmimet në")} {draft.currency}{t(". Pa konvertim valutor.")}</small></label>
    <label className="toggle-row"><span><strong>{t("I lirë sot")}</strong><small>{t("Shfaq vetëm të disponueshmit")}</small></span><input type="checkbox" checked={draft.date === today} onChange={event => apply({ date: event.target.checked ? today : "" })} /><i style={{ background: draft.date === today ? "var(--green)" : undefined, transform: draft.date === today ? "rotate(180deg)" : undefined }} /></label>
  </>;

  return <main className="page-main">
    <section className="browse-hero"><div className="eyebrow"><span /> {t("GJEJ PORTIER")}</div><h1>{t("Gjej portierin e duhur për ndeshjen tënde.")}</h1><p>{t("Shfleto profilet dhe datat e publikuara nga portierët pranë teje.")}</p><SearchBar cities={cities} countries={countries} countryId={searchCountry} onCountryChange={id => { setSearchCountry(id); setSearchCity(""); }} cityId={searchCity} onCityChange={setSearchCity} date={searchDate} onDateChange={setSearchDate} minDate={today} maxDate={maxDate} onSearch={() => apply({ city_id: searchCity, country_id: searchCountry, date: searchDate, ...scopePrice(searchCity, searchCountry) })} /></section>
    <section className="browse-layout section">
      <aside className="filters">{filterFields}</aside>
      <div className="results"><div className="results-head"><div><strong>{keepers.length} {keepers.length === 1 ? t("portier") : t("portierë")}</strong><span>{selectedCity ? t("pranë :city", { city: selectedCity.name }) : (selectedCountry ? t(selectedCountry.name) : t("Të gjitha qytetet"))}</span></div><button className="mobile-filter" aria-expanded={mobileFiltersOpen} onClick={() => setMobileFiltersOpen(value => !value)}><Icon name="filter" /> {t("Filtrat")}</button><select disabled aria-label={t("Renditja e portierëve")}><option>{t("Sipas listës")}</option></select></div>
        {mobileFiltersOpen && <div className="mobile-filter-dropdown"><div className="filters">{filterFields}</div><div className="mobile-filter-actions"><Button variant="outline" onClick={() => { setDraft(filters); setPriceInput(String(filters.max_price)); setMobileFiltersOpen(false); }}>{t("Mbyll")}</Button><Button onClick={() => { setMobileFiltersOpen(false); commit(draft); }}>{t("Apliko filtrat")}</Button></div></div>}
        <div className="keeper-grid browse-grid">{keepers.map(keeper => <KeeperCard key={keeper.id} keeper={keeper} requestedDate={filters.date || undefined} onOpen={() => openKeeper(keeper, filters.date)} />)}{keepers.length === 0 && <p role="status">{t("Nuk u gjet asnjë portier me këta filtra.")}</p>}</div>
      </div>
    </section>
  </main>;
}
