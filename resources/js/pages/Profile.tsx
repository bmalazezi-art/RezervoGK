import { t, useLocale } from "../i18n";
import { keeperAvailability, keeperLocation } from "../lib/keeperLabels";
import { formatKeeperPrice } from "../lib/pricing";
import { WeeklySchedule } from "../components/WeeklyHours";
import { Icon, ContactActions } from "../components/marketplace";
import { useEffect, useRef, useState, type FormEvent, type KeyboardEvent } from "react";
import { Head, router, useForm } from "@inertiajs/react";
import { Button } from "../components/marketplace";
import { weekdayShort, displayDate } from "../lib/dates";
import type { PublicKeeper, CalendarDay } from "../types/goalkeeper";
import { navigate as setPage } from "../navigation";
import { copyTextToClipboard, profileShareTargets } from "../lib/profileSharing";
import "../../css/profile-improvements.css";

const supportsNativeShare = () => typeof Reflect.get(navigator, "share") === "function";

export default function Profile({ keeper, calendar, selectedDate, today, maxDate, shareUrl, shareTitle, shareDescription, shareImage }: { keeper: PublicKeeper; calendar: CalendarDay[]; selectedDate: string; today: string; maxDate: string; shareUrl: string; shareTitle: string; shareDescription: string; shareImage: string }) {
  useLocale();
  const [date, setDate] = useState(selectedDate);
  const [shareStatus, setShareStatus] = useState("");
  const [shareMenuOpen, setShareMenuOpen] = useState(false);
  const shareButton = useRef<HTMLButtonElement>(null);
  const shareTargets = profileShareTargets(shareTitle, shareDescription, shareUrl);
  const reportForm = useForm({ reason: "inaccurate", details: "" });
  useEffect(() => setDate(selectedDate), [selectedDate]);
  const copyLink = async (successMessage = t("Profili u kopjua. Tani mund ta shpërndash."), errorMessage = t("Linku nuk u kopjua. Provo përsëri.")) => {
    if (await copyTextToClipboard(shareUrl)) {
      setShareStatus(successMessage);
      return;
    }
    setShareStatus(errorMessage);
  };
  const shareProfile = async () => {
    if (!supportsNativeShare()) { setShareStatus(t("Ndarja nga pajisja nuk mbështetet. Përdor një nga opsionet më sipër.")); return; }
    try {
      await navigator.share({ title: shareTitle, text: [shareTitle, shareDescription].filter(Boolean).join("\n"), url: shareUrl });
      setShareStatus(t("Profili u shpërnda."));
    } catch (error) {
      if (error instanceof DOMException && error.name === "AbortError") return;
      setShareStatus(t("Profili nuk u shpërnda. Kopjo linkun dhe provo përsëri."));
    }
  };
  const openInstagram = () => {
    setShareMenuOpen(false);
    // On phones, let iOS/Android show its native share sheet so Instagram can
    // receive the canonical URL and its normal link preview. The web cannot
    // force a specific installed app to open from navigator.share.
    if (supportsNativeShare()) { void shareProfile(); return; }
    window.open("https://www.instagram.com/", "_blank", "noopener,noreferrer");
    void copyLink(t("Linku u kopjua. Instagram u hap; ngjite në bio ose mesazh."), t("Instagram u hap. Kopjo linkun dhe ngjite në bio ose mesazh."));
  };
  const openMessengerFallback = () => {
    setShareMenuOpen(false);
    // Native share sheets list Messenger when it is available on the device.
    if (supportsNativeShare()) { void shareProfile(); return; }
    window.open("https://www.messenger.com/", "_blank", "noopener,noreferrer");
    void copyLink(t("Linku u kopjua. Ngjite në një bisedë në Messenger."), t("Linku nuk u kopjua. Kopjoje dhe ngjite manualisht në Messenger."));
  };
  const closeShareMenu = (event: KeyboardEvent<HTMLDivElement>) => {
    if (event.key === "Escape") {
      setShareMenuOpen(false);
      shareButton.current?.focus();
    }
  };
  const showDate = (event: FormEvent<HTMLFormElement>) => { event.preventDefault(); router.get(`/keepers/${keeper.id}`, { date }, { preserveScroll: true }); };
  const submitReport = (event: FormEvent<HTMLFormElement>) => { event.preventDefault(); reportForm.post(`/keepers/${keeper.id}/reports`, { preserveScroll: true, onSuccess: () => reportForm.reset() }); };
  return <main className="page-main profile-page">
    <Head title={shareTitle}>
      <meta head-key="description" name="description" content={shareDescription} />
      <link head-key="canonical" rel="canonical" href={shareUrl} />
      <meta head-key="og:type" property="og:type" content="profile" />
      <meta head-key="og:url" property="og:url" content={shareUrl} />
      <meta head-key="og:title" property="og:title" content={shareTitle} />
      <meta head-key="og:description" property="og:description" content={shareDescription} />
      <meta head-key="og:image" property="og:image" content={shareImage} />
      <meta head-key="twitter:card" name="twitter:card" content="summary_large_image" />
    </Head>
    <div className="section profile-back"><button onClick={() => setPage("browse")}><Icon name="back" /> {t("Kthehu te portierët")}</button></div>
    <section className="section profile-hero">
      <div className="profile-image"><img src={keeper.photo} alt={keeper.name} /></div>
      <div className="profile-copy">
        <div className="profile-title"><div><span className="available-text"><i /> {keeperAvailability(keeper)}</span><h1>{keeper.name}</h1><p><Icon name="pin" size={18} /> {keeperLocation(keeper)}</p></div><button className="round-heart"><Icon name="heart" /></button></div>
        <div className="profile-stats"><div><strong>{keeper.experienceYears} {t(keeper.experienceYears === 1 ? "vit" : "vite")}</strong><span>{t("Përvojë e deklaruar")}</span></div><div><strong>{keeper.formats.join(" · ")}</strong><span>{t("Formatet e lojës")}</span></div></div>
        <div className="profile-sharing">
          <div className="profile-share-dropdown" onKeyDown={closeShareMenu}>
            <button ref={shareButton} type="button" className="btn btn-primary profile-share-toggle" aria-expanded={shareMenuOpen} aria-controls="profile-share-options" onClick={() => setShareMenuOpen(open => !open)}><Icon name="share" size={18} /> {t("Shpërndaje profilin")}</button>
            <div id="profile-share-options" className="profile-share-menu" role="group" aria-label={t("Mundësitë e shpërndarjes së profilit")} hidden={!shareMenuOpen}>
              <a className="profile-share-option" href={shareTargets.whatsapp} target="_blank" rel="noopener noreferrer" onClick={() => setShareMenuOpen(false)}><img className="share-brand-logo" src="/icons/share-whatsapp.svg" alt="" aria-hidden="true" width="20" height="20" /> {t("WhatsApp")}</a>
              <a className="profile-share-option" href={shareTargets.viber} onClick={() => { setShareMenuOpen(false); void copyLink(t("Linku u kopjua. Nëse Viber nuk hapet, ngjite në bisedë."), t("Viber u hap. Nëse linku nuk u kopjua, kopjoje dhe ngjite në bisedë.")); }}><img className="share-brand-logo" src="/icons/share-viber.svg" alt="" aria-hidden="true" width="20" height="20" /> {t("Viber")}</a>
              <button className="profile-share-option" type="button" onClick={openMessengerFallback}><img className="share-brand-logo" src="/icons/share-messenger.svg" alt="" aria-hidden="true" width="20" height="20" /> {t("Messenger")}</button>
              <button className="profile-share-option" type="button" onClick={openInstagram}><img className="share-brand-logo" src="/icons/share-instagram.svg" alt="" aria-hidden="true" width="20" height="20" /> {t("Instagram")}</button>
              <button className="profile-share-option" type="button" onClick={() => { setShareMenuOpen(false); void copyLink(); }}><Icon name="share" size={18} /> {t("Kopjo linkun")}</button>
              <button className="profile-share-option" type="button" onClick={() => { setShareMenuOpen(false); void shareProfile(); }}><span className="share-service-mark more-mark" aria-hidden="true">···</span> {t("Më shumë opsione")}</button>
            </div>
          </div>
          <span className="profile-share-status" role="status" aria-live="polite">{shareStatus}</span>
        </div>
        <div className="profile-about"><h3>{t("Rreth meje")}</h3><p>{keeper.bio}</p></div>
        <div className="format-row"><div><span>{t("FORMATET")}</span><strong>{keeper.formats.join(" · ")}</strong></div><div><span>{t("GJUHËT")}</span><strong>{keeper.languages.join(" · ")}</strong></div></div>
      </div>
      <aside className="booking-card contact-card"><span>{t("Çmimi për ndeshje")}</span><strong>{formatKeeperPrice(keeper)} <small>{t("/ ndeshje")}</small></strong><hr /><h3>{t("Kontakto direkt")}</h3><p className="contact-intro">{t("Pyete për datën, orën dhe lokacionin. Nuk të duhet llogari.")}</p><ContactActions keeper={keeper} /><p>{t("Pa tarifa · Merruni vesh drejtpërdrejt për pagesën")}</p><div className="cash-note"><Icon name="shield" /><span><strong>{t("Kontakt i drejtpërdrejtë")}</strong><small>{t("Portieri do të konfirmojë disponueshmërinë me mesazh ose telefon.")}</small></span></div></aside>
    </section>
    <section className="section profile-lower">
      <div className="min-w-0"><h2>{t("Disponueshmëria")}</h2><p>{t("Datat e publikuara nga")} {keeper.name.split(" ")[0]}{t(". Konfirmoni orën direkt me portierin.")}</p>
        <form className="form-grid" onSubmit={showDate}><label>{t("Data")}<input required type="date" value={date} onChange={event => setDate(event.target.value)} min={today} max={maxDate} /></label><Button type="submit" variant="outline">{t("Shiko javën")}</Button></form>
        <div className="week">{calendar.map(day => <button type="button" disabled className={day.available ? "selected" : "off"} key={day.iso} aria-label={`${displayDate(day.iso)}: ${day.available ? t("I lirë") : t("I zënë")}`}><span>{weekdayShort(day.iso)}</span><strong>{day.date}</strong><small>{day.available ? t("I lirë") : t("I zënë")}</small></button>)}</div>
        <div className="chips">{keeper.availabilityDates.slice(0, 8).map(day => <button key={day} className="selected" onClick={() => router.get(`/keepers/${keeper.id}`, { date: day }, { preserveScroll: true })}>{displayDate(day)}</button>)}</div>
        {keeper.availabilityDates.length > 8 && <p>{t("Përdor kalendarin për të parë të gjitha 30 ditët.")}</p>}
        {keeper.availabilityDates.length === 0 && <p>{t("Portieri nuk ka publikuar ende data të lira.")}</p>}
      </div>
      <div className="min-w-0"><WeeklySchedule hours={keeper.weeklyHours} /><div className="reviews"><div className="reviews-title"><h2>{t("Para se të kontaktoni")}</h2></div><p>{t("Të dhënat plotësohen nga portieri. Platforma nuk verifikon identitetin ose përvojën e tij.")}</p><p>{t("Organizatori dhe portieri merren vesh drejtpërdrejt për mënyrën e pagesës dhe shumën. Gjej portier nuk mbledh, proceson apo transferon pagesat për ndeshje.")}</p></div>
        <details className="reviews profile-report-card"><summary><span>{t("Raporto profilin")}</span><small>{t("Ke vërejtur problem? Na ndihmo duke e raportuar.")}</small></summary><div className="profile-report-body"><p>{t("Raportet shqyrtohen nga ekipi i Gjej portier. Zgjidh arsyen që përshkruan më mirë problemin.")}</p><form className="profile-report-form" onSubmit={submitReport}>
          <label htmlFor="profile-report-reason">{t("Arsyeja")}</label><select id="profile-report-reason" value={reportForm.data.reason} onChange={e => reportForm.setData("reason", e.target.value)}><option value="inaccurate">{t("Informacion i pasaktë")}</option><option value="inappropriate">{t("Përmbajtje e papërshtatshme")}</option><option value="spam">{t("Spam ose mashtrim")}</option><option value="other">{t("Tjetër")}</option></select>{reportForm.errors.reason && <p className="profile-report-error" role="alert">{reportForm.errors.reason}</p>}
          <label htmlFor="profile-report-details">{t("Çfarë duhet të dimë? (opsionale)")}</label><textarea id="profile-report-details" maxLength={1000} rows={4} value={reportForm.data.details} onChange={e => reportForm.setData("details", e.target.value)} aria-describedby="profile-report-help profile-report-count" /><div className="profile-report-meta"><small id="profile-report-help">{t("Përshkruaj problemin shkurt. Mos përfshi numër telefoni ose të dhëna private.")}</small><small id="profile-report-count">{reportForm.data.details.length}/1000</small></div>{reportForm.errors.details && <p className="profile-report-error" role="alert">{reportForm.errors.details}</p>}
          <Button type="submit" variant="outline" disabled={reportForm.processing}>{reportForm.processing ? t("Duke dërguar...") : t("Dërgo raportin")}</Button>{reportForm.wasSuccessful && <p className="profile-report-success" role="status">{t("Raporti u dërgua. Faleminderit.")}</p>}
        </form></div></details>
      </div>
    </section>
  </main>;
}

