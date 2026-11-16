import { t, useLocale } from "../i18n";
import { Button, Icon, SearchBar, KeeperCard } from "../components/marketplace";
import { photos } from "../data/demo";
import { useState } from "react";
import { router } from "@inertiajs/react";
import type { CityOption, CountryOption, PublicKeeper } from "../types/goalkeeper";
import { navigate as setPage, openKeeper } from "../navigation";

export default function Home({ keepers, cities, countries, today, initialDate, maxDate }: { keepers: PublicKeeper[]; cities: CityOption[]; countries: CountryOption[]; today: string; initialDate: string; maxDate: string }) {
  useLocale();
  const defaultCity = cities.find(item => item.name === "Prishtinë");
  const [city, setCity] = useState("");
  const [country, setCountry] = useState(String(defaultCity?.country_id ?? ""));
  const [date, setDate] = useState(initialDate);
  return <main>
    <section className="hero">
      <div className="hero-photo"><img src={photos.hero} alt={t("Ndeshje futbolli nën dritat e stadiumit")} /><div className="hero-shade" /></div>
      <div className="hero-content">
        <div className="eyebrow light"><span /> {t("PORTIERI QË TË DUHET, KUR TË DUHET")}</div>
        <h1>{t("Gjej portierin.")}<br /><em>{t("Luaj ndeshjen.")}</em></h1>
        <p>{t("Gjej portierë pranë teje dhe pyeti për ndeshjen e radhës. Kontakto direkt, pa llogari, dhe merruni vesh drejtpërdrejt për pagesën.")}</p>
        <SearchBar cities={cities} countries={countries} countryId={country} onCountryChange={id => { setCountry(id); setCity(""); }} cityId={city} onCityChange={setCity} date={date} onDateChange={setDate} minDate={today} maxDate={maxDate} onSearch={() => router.get("/browse", { ...(country ? { country_id: country } : {}), ...(city ? { city_id: city } : {}), ...(date ? { date } : {}) })} />
        <div className="hero-proof"><Icon name="users" /><div><strong>{t("Pa llogari për organizatorët")}</strong><span>{t("Kontakt direkt në WhatsApp ose telefon")}</span></div></div>
      </div>
      <div className="scroll-note">{t("ZBULO")} <span /></div>
    </section>

    <section className="section featured">
      <div className="section-head">
        <div><div className="eyebrow"><span /> {t("NJOHU ME PORTIERËT")}</div><h2>{t("Gati për ndeshjen tënde")}</h2><p>{t("Shiko profilet dhe datat e publikuara nga portierët.")}</p></div>
        <button className="text-link" onClick={() => setPage("browse")}>{t("Shiko të gjithë")} <Icon name="arrow" size={18} /></button>
      </div>
      <div className="keeper-grid">{keepers.slice(0, 3).map(k => <KeeperCard key={k.id} keeper={k} onOpen={() => openKeeper(k)} />)}</div>
    </section>

    <section className="how-section">
      <div className="section how-inner">
        <div className="section-head"><div><div className="eyebrow light"><span /> {t("SI FUNKSIONON")}</div><h2>{t("Tri hapa. Një ndeshje")}<br />{t("më e mirë.")}</h2></div><p>{t("Kërko sipas qytetit dhe datës. Kontakto portierin dhe konfirmoni ndeshjen mes jush.")}</p></div>
        <div className="steps">
          {[["01", "Kërko", "Zgjidh qytetin, datën dhe formatin e ndeshjes."], ["02", "Kontakto", "Shkruaj në WhatsApp ose telefono, pa krijuar llogari."], ["03", "Luaj", "Organizatori dhe portieri merren vesh drejtpërdrejt për mënyrën e pagesës dhe shumën. Gjej portier nuk mbledh, proceson apo transferon pagesat për ndeshje."]].map(([n, title, d], i) => <div className="step" key={n}><div className="step-icon"><span>{n}</span><Icon name={i === 0 ? "search" : i === 1 ? "whatsapp" : "check"} size={27} /></div><h3>{t(title)}</h3><p>{t(d)}</p>{i < 2 && <i className="step-line" />}</div>)}
        </div>
      </div>
    </section>

    <section className="section trust">
      <div className="trust-copy"><div className="eyebrow"><span /> {t("E NDËRTUAR PËR FUTBOLLIN TONË")}</div><h2>{t("Më shumë lojë.")}<br />{t("Më pak organizim.")}</h2><p>{t("Nga Prishtina në Tiranë e deri në Shkup—Portieri lidh ekipet me lojtarët që bëjnë diferencën.")}</p>
        <div className="trust-points"><div><Icon name="shield" /><span><strong>{t("Profile të plotësuara nga portierët")}</strong><small>{t("Datat dhe çmimet i caktojnë vetë")}</small></span></div><div><Icon name="users" /><span><strong>{t("Komunitet lokal")}</strong><small>{t("Kontakt pa ndërmjetësim")}</small></span></div></div>
      </div>
      <div className="stats" aria-label={t("Kushtet e shërbimit")}><div><strong>€0</strong><span>{t("Për organizatorët")}</span></div><div><strong>€0</strong><span>{t("Për portierët")}</span></div><div><strong>€0</strong><span>{t("Pa abonim ose tarifë platforme.")}</span></div><div><strong>0%</strong><span>{t("Komision nga pagesa e ndeshjes")}</span></div></div>
    </section>

    <section className="section cta-banner"><div><div className="eyebrow light"><span /> {t("PËR PORTIERËT")}</div><h2>{t("Talenti yt meriton")}<br />{t("më shumë ndeshje.")}</h2><p>{t("Krijo profilin, cakto çmimin e ndeshjes dhe fillo të marrësh kërkesa. Shërbimi i platformës është falas.")}</p><Button onClick={() => setPage("register")}>{t("Krijo profilin falas")} <Icon name="arrow" /></Button></div><div className="cta-price"><span>{t("Shërbim plotësisht falas")}</span><strong>€0<small>{t("Pa abonim")}</small></strong><em>{t("Pa komision për ndeshje")}</em></div></section>
  </main>;
}

