import { t, useLocale } from "../i18n";
import { type FormEvent } from "react";
import { router, useForm } from "@inertiajs/react";
import { Button } from "../components/marketplace";
import KeeperAuthShell, { AuthStatus, FieldError } from "../components/KeeperAuthShell";

export default function ForgotPassword() {
  useLocale();
  const form = useForm({ email: "" });
  const submit = (event: FormEvent<HTMLFormElement>) => { event.preventDefault(); form.post("/forgot-password"); };
  return <KeeperAuthShell><span>{t("LLOGARIA JOTE")}</span><h2>{t("Ke harruar fjalëkalimin?")}</h2><p>{t("Shkruaj email-in për të marrë lidhjen e rivendosjes.")}</p><AuthStatus />
    <form onSubmit={submit}><div className="form-stack"><label>{t("Email")}<input required type="email" autoComplete="email" value={form.data.email} onChange={e => form.setData("email", e.target.value)} /><FieldError message={form.errors.email} /></label></div><Button type="submit" className="register-submit" disabled={form.processing}>{t("Dërgo lidhjen")}</Button></form><button className="text-link" onClick={() => router.visit("/login")}>{t("Kthehu te kyçja")}</button>
  </KeeperAuthShell>;
}
