import { t, useLocale } from "../i18n";
import { type FormEvent } from "react";
import { router, useForm } from "@inertiajs/react";
import { Button } from "../components/marketplace";
import KeeperAuthShell, { AuthStatus, FieldError } from "../components/KeeperAuthShell";

export default function AdminVerify({ email, minutes }: { email: string; minutes: number }) {
  useLocale();
  const form = useForm({ code: "" });
  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    form.post("/admin/verify", { replace: true, onError: () => form.reset("code") });
  };
  return <KeeperAuthShell><span>{t("ADMINISTRIMI")}</span><h2>{t("Shkruaj kodin nga email-i")}</h2><p>{t("Dërguam një kod 6-shifror te :email. Vlen :minutes minuta.", { email, minutes })}</p><AuthStatus />
    <form onSubmit={submit}><div className="form-stack"><label>{t("Kodi i verifikimit")}<input className="admin-code-input" required autoFocus inputMode="numeric" autoComplete="one-time-code" pattern="[0-9 ]{6,7}" maxLength={7} placeholder="123456" value={form.data.code} onChange={e => form.setData("code", e.target.value.replace(/[^\d ]/g, ""))} /><FieldError message={form.errors.code} /></label></div><Button type="submit" className="register-submit" disabled={form.processing || form.data.code.replace(/\D/g, "").length !== 6}>{form.processing ? t("Duke kontrolluar...") : t("Vazhdo")}</Button></form>
    <button className="text-link" type="button" onClick={() => router.post("/admin/verify/resend", {}, { preserveScroll: true })}>{t("Nuk erdhi? Dërgo një kod të ri")}</button><button className="text-link" type="button" onClick={() => router.visit("/admin/login")}>{t("Kthehu te kyçja")}</button>
  </KeeperAuthShell>;
}
