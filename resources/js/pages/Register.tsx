import { t, useLocale } from "../i18n";
import type { PriceCurrency } from "../lib/pricing";
import { useEffect, useRef, useState, type FormEvent } from "react";
import { router, useForm } from "@inertiajs/react";
import { preparePhoto } from "../lib/photoResize";
import { Button, Icon } from "../components/marketplace";
import KeeperAuthShell, { FieldError } from "../components/KeeperAuthShell";
import type { CityOption, CountryOption } from "../types/goalkeeper";
import { ExtraCityPicker } from "../components/CityPicker";

type Registration = {
  name: string; phone: string; country_id: string; city_id: string; extra_city_ids: string[]; bio: string;
  price: string; currency: PriceCurrency; formats: string[]; photo: File | null;
  email: string; password: string; password_confirmation: string; accept_terms: boolean;
};

const ACCOUNT_FIELDS = ["email", "password", "password_confirmation", "accept_terms"];

export default function Register({ countries, cities, formats }: {
  countries: CountryOption[]; cities: CityOption[]; formats: string[];
}) {
  useLocale();
  const country = countries.find(item => item.name === "Kosovë") ?? countries[0];
  const form = useForm<Registration>({
    name: "", phone: "", country_id: String(country?.id ?? ""),
    city_id: "", extra_city_ids: [],
    bio: "", price: country?.defaultCurrency === "EUR" ? "10" : "", currency: country?.defaultCurrency ?? "EUR", formats: formats.filter(code => ["5v5", "6v6"].includes(code)),
    photo: null, email: "", password: "", password_confirmation: "", accept_terms: false,
  });
  const [step, setStep] = useState(1);
  const [returnedToProfile, setReturnedToProfile] = useState(false);
  const [preparingPhoto, setPreparingPhoto] = useState(false);
  const [requestError, setRequestError] = useState("");
  const submitting = useRef(false);
  const retryAfterExpiry = useRef(false);
  const retriedAfterExpiry = useRef(false);
  // An expired session (419), too many attempts (429) or a dropped connection used to open Inertia's full-screen
  // error page, and refreshing it wiped everything the keeper had typed. Keep the form and say what to do instead;
  // an expired session is renewed and the sign-up is sent once more on its own.
  useEffect(() => {
    const stopInvalid = router.on("invalid", event => {
      if (!submitting.current) return;
      event.preventDefault();
      const status = event.detail.response.status;
      if (status === 419 && !retriedAfterExpiry.current) { retryAfterExpiry.current = true; return; }
      setRequestError(status === 429 ? "Shumë përpjekje brenda një minute. Prit një minutë dhe kliko sërish «Krijo profilin falas». Të dhënat e tua janë ruajtur."
        : status === 419 ? "Sesioni skadoi. Rifresko faqen dhe provo përsëri."
          : "Diçka shkoi keq. Provo përsëri pas pak. Të dhënat e tua janë ruajtur.");
    });
    const stopException = router.on("exception", event => {
      if (!submitting.current) return;
      event.preventDefault();
      setRequestError("Lidhja u ndërpre. Kontrollo internetin dhe kliko sërish «Krijo profilin falas». Të dhënat e tua janë ruajtur.");
    });
    return () => { stopInvalid(); stopException(); };
  }, []);
  const extraCityError = Object.entries(form.errors as Record<string, string | undefined>).filter(([key]) => key === "extra_city_ids" || key.startsWith("extra_city_ids.")).map(([, message]) => message).join(" ") || undefined;
  const photoInput = useRef<HTMLInputElement>(null);
  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (step === 1) { if (preparingPhoto) return; setReturnedToProfile(false); setStep(2); return; }
    retriedAfterExpiry.current = false;
    send();
  };
  const send = () => {
    setRequestError("");
    form.post("/register", {
      forceFormData: true,
      replace: true,
      onStart: () => { submitting.current = true; },
      onFinish: () => {
        submitting.current = false;
        if (!retryAfterExpiry.current) return;
        retryAfterExpiry.current = false;
        retriedAfterExpiry.current = true;
        // Loading the page again starts a fresh session and sets a new CSRF cookie; then send the same data once more.
        fetch("/register", { credentials: "same-origin", headers: { Accept: "text/html" } }).then(() => send(), () => setRequestError("Sesioni skadoi. Rifresko faqen dhe provo përsëri."));
      },
      onError: errors => {
        // Only send the user back to the profile step when a profile field needs fixing, keep what they typed,
        // and say so at the top: a silent jump back looked like the form had started over.
        const keys = Object.keys(errors);
        if (keys.some(key => ["password", "password_confirmation"].includes(key))) form.reset("password", "password_confirmation");
        if (keys.some(key => !ACCOUNT_FIELDS.includes(key))) {
          setReturnedToProfile(true);
          setStep(1);
          window.setTimeout(() => document.querySelector(".register-form [role=alert]")?.scrollIntoView({ behavior: "smooth", block: "center" }), 50);
        }
      },
    });
  };
  return <KeeperAuthShell>
    <span>{t("HAPI")} {step} {t("NGA 2")}</span><h2>{step === 1 ? t("Krijo profilin tënd") : t("Krijo llogarinë tënde")}</h2><p>{step === 1 ? t("Na trego pak për veten si portier.") : t("Përdor email-in për t'u kyçur në panelin tënd.")}</p>
    <form onSubmit={submit}>
      {step === 1 ? <>
        {returnedToProfile && Object.keys(form.errors).length > 0 && <p className="register-errors" role="status">{t("Disa të dhëna duhen korrigjuar. Shiko fushat e shënuara më poshtë, pastaj vazhdo. Fjalëkalimin nuk ke nevojë ta shkruash sërish.")}</p>}
        <div className="photo-upload"><button type="button" onClick={() => photoInput.current?.click()}><Icon name="upload" /><span>{form.data.photo?.name ?? t("Ngarko fotografinë")}<small>{preparingPhoto ? t("Duke përgatitur fotografinë...") : t("JPG ose PNG. Fotot e mëdha zvogëlohen automatikisht.")}</small></span></button><input ref={photoInput} type="file" accept="image/jpeg,image/png" hidden onChange={async event => { const chosen = event.target.files?.[0] ?? null; form.clearErrors("photo"); if (!chosen) { form.setData("photo", null); return; } setPreparingPhoto(true); form.setData("photo", await preparePhoto(chosen)); setPreparingPhoto(false); }} /><FieldError message={form.errors.photo} /></div>
        <div className="form-grid"><label>{t("Emri i plotë")}<input required autoComplete="name" value={form.data.name} onChange={e => form.setData("name", e.target.value)} placeholder={t("p.sh. Ardit Krasniqi")} maxLength={120} /><FieldError message={form.errors.name} /></label><label>{t("Numri i telefonit")}<input required type="tel" autoComplete="tel" value={form.data.phone} onChange={e => form.setData("phone", e.target.value)} placeholder="+383 44 000 000" /><FieldError message={form.errors.phone} /></label><label>{t("Shteti")}<select required value={form.data.country_id} onChange={e => { const countryId = e.target.value; form.setData(data => ({ ...data, country_id: countryId, currency: countries.find(country => String(country.id) === countryId)?.defaultCurrency ?? "EUR", price: "", city_id: "", extra_city_ids: [] })); }}>{countries.map(country => <option key={country.id} value={country.id}>{t(country.name)}</option>)}</select><FieldError message={form.errors.country_id} /></label><label>{t("Qyteti")}<select required value={form.data.city_id} onChange={e => { const cityId = e.target.value; form.setData(data => ({ ...data, city_id: cityId, extra_city_ids: data.extra_city_ids.filter(id => id !== cityId) })); }}><option value="" disabled>{t("Zgjidh qytetin")}</option>{cities.filter(city => String(city.country_id) === form.data.country_id).map(city => <option key={city.id} value={city.id}>{city.name}</option>)}</select><FieldError message={form.errors.city_id} /></label><ExtraCityPicker cities={cities} countryId={form.data.country_id} primaryCityId={form.data.city_id} value={form.data.extra_city_ids} onChange={ids => form.setData("extra_city_ids", ids)} error={extraCityError} /></div>
        <label className="full-label">{t("Përshkrimi yt")}<textarea required maxLength={2000} value={form.data.bio} onChange={e => form.setData("bio", e.target.value)} placeholder={t("Tregoju ekipeve për përvojën dhe stilin tënd...")} /><FieldError message={form.errors.bio} /></label>
        <div className="form-grid"><label>{t("Çmimi për ndeshje")}<input required type="number" min="0" max="10000" step="0.01" value={form.data.price} onChange={e => form.setData("price", e.target.value)} /><FieldError message={form.errors.price} /></label><label>{t("Monedha")}<select value={form.data.currency} onChange={e => form.setData(data => ({ ...data, currency: e.target.value as PriceCurrency, price: "" }))}>{(countries.find(country => String(country.id) === form.data.country_id)?.currencies ?? ["EUR"]).map(currency => <option key={currency} value={currency}>{currency}</option>)}</select><small>{t("Kur ndryshon monedhën, vendos çmimin sërish. Pa konvertim automatik.")}</small><FieldError message={form.errors.currency} /></label><div className="format-check"><span>{t("Formatet e preferuara")}</span>{formats.map(code => <label key={code}><input type="checkbox" checked={form.data.formats.includes(code)} onChange={e => form.setData("formats", e.target.checked ? [...form.data.formats, code] : form.data.formats.filter(item => item !== code))} /> {code}</label>)}<FieldError message={form.errors.formats} /></div></div>
        <Button type="submit" className="register-submit">{t("Vazhdo")} <Icon name="arrow" /></Button>
      </> : <>
        <div className="form-stack"><label>{t("Email")}<input required type="email" autoComplete="email" value={form.data.email} onChange={e => form.setData("email", e.target.value)} maxLength={254} /><FieldError message={form.errors.email} /></label><label>{t("Fjalëkalimi")}<input required type="password" autoComplete="new-password" minLength={12} value={form.data.password} onChange={e => form.setData("password", e.target.value)} /><small>{t("Të paktën 12 karaktere, shkronja të mëdha, të vogla dhe numra.")}</small><FieldError message={form.errors.password} /></label><label>{t("Konfirmo fjalëkalimin")}<input required type="password" autoComplete="new-password" value={form.data.password_confirmation} onChange={e => form.setData("password_confirmation", e.target.value)} /></label></div>
        <label className="terms acceptance-check"><input type="checkbox" required checked={form.data.accept_terms} onChange={event => form.setData("accept_terms", event.target.checked)} aria-invalid={Boolean(form.errors.accept_terms)} /> <span>{t("Konfirmoj se jam të paktën 18 vjeç dhe pranoj")} <a href="/terms" target="_blank" rel="noreferrer">{t("Kushtet e përdorimit")}</a> {t("dhe")} <a href="/privacy" target="_blank" rel="noreferrer">{t("Politikën e privatësisë")}</a>.</span></label><FieldError message={form.errors.accept_terms} />
        {requestError && <p className="register-errors" role="alert">{t(requestError)}</p>}
        <Button type="submit" className="register-submit" disabled={form.processing}>{form.processing ? t("Duke krijuar...") : t("Krijo profilin falas")} <Icon name="arrow" /></Button><Button variant="ghost" onClick={() => setStep(1)}>{t("Kthehu")}</Button>
      </>}
    </form>
    <button className="text-link" onClick={() => router.visit("/login")}>{t("Ke llogari? Kyçu")}</button>
  </KeeperAuthShell>;
}
