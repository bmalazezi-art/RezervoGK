import { t, useLocale } from "../i18n";
import { Button, Icon } from "../components/marketplace";
import { navigate as setPage } from "../navigation";

export default function Pricing() {
  useLocale();
  return <main className="page-main pricing-page"><section className="pricing-hero"><div className="eyebrow"><span /> {t("PA TARIFA PLATFORME")}</div><h1>{t("Më shumë ndeshje.")}<br /><em>{t("Gjithçka falas.")}</em></h1><p>{t("Organizatorët kontaktojnë falas dhe pa llogari. Portierët marrin gjithçka që fitojnë.")}</p></section>
    <section className="section pricing-cards"><article><span className="plan-label">{t("PËR ORGANIZATORËT")}</span><h2>{t("Falas")}</h2><div className="plan-price"><strong>€0</strong><small>{t("Pa tarifa")}</small></div><p>{t("Gjej dhe kontakto portierë pa tarifa ose llogari.")}</p><ul><li><Icon name="check" /> {t("Kërkim pa limit")}</li><li><Icon name="check" /> {t("WhatsApp dhe telefon direkt")}</li><li><Icon name="check" /> {t("Profile dhe data të publikuara")}</li><li><Icon name="check" /> {t("Pagesë në dorë në fushë")}</li></ul><Button variant="dark" onClick={() => setPage("browse")}>{t("Gjej portier")}</Button></article>
      <article className="featured-plan"><span className="popular">{t("FALAS")}</span><span className="plan-label">{t("PËR PORTIERËT")}</span><h2>{t("Profili i portierit")}</h2><div className="plan-price"><strong>€0</strong><small>{t("Pa abonim")}</small></div><p>{t("Gjithçka që të duhet për të luajtur më shpesh.")}</p><ul><li><Icon name="check" /> {t("Profil publik pa pagesë")}</li><li><Icon name="check" /> {t("Kërkesa direkte nga organizatorët")}</li><li><Icon name="check" /> {t("100% e pagesës mbetet e jotja")}</li><li><Icon name="check" /> {t("Menaxhim i disponueshmërisë")}</li><li><Icon name="check" /> {t("Pa afat skadimi për shërbimin falas")}</li></ul><Button onClick={() => setPage("register")}>{t("Fillo falas")}</Button></article></section>
    <section className="pricing-note"><Icon name="shield" size={28} /><div><strong>{t("Pa komision. Asnjëherë.")}</strong><p>{t("Përdorimi i platformës është falas për portierët dhe organizatorët. Çmimi i ndeshjes caktohet nga portieri dhe paguhet në dorë mes jush. Platforma nuk merr komision.")}</p></div></section>
  </main>;
}

