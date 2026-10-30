import { t, useLocale } from "../i18n";
import { type FormEvent } from "react";
import { useForm } from "@inertiajs/react";
import { Button } from "../components/marketplace";
import KeeperAuthShell, { FieldError } from "../components/KeeperAuthShell";

export default function ResetPassword({ email, token }: { email: string; token: string }) {
  useLocale();
  const form = useForm({ email, token, password: "", password_confirmation: "" });
  const submit = (event: FormEvent<HTMLFormElement>) => { event.preventDefault(); form.post("/reset-password", { onFinish: () => form.reset("password", "password_confirmation") }); };
  return <KeeperAuthShell><span>{t("LLOGARIA JOTE")}</span><h2>{t("Vendos fjalëkalimin e ri")}</h2><p>{t("Zgjidh të paktën 12 karaktere me shkronja të mëdha, të vogla dhe numra.")}</p>
    <form onSubmit={submit}><div className="form-stack"><label>{t("Email")}<input required type="email" autoComplete="email" value={form.data.email} onChange={e => form.setData("email", e.target.value)} /><FieldError message={form.errors.email} /></label><label>{t("Fjalëkalimi i ri")}<input required type="password" autoComplete="new-password" minLength={12} value={form.data.password} onChange={e => form.setData("password", e.target.value)} /><FieldError message={form.errors.password} /></label><label>{t("Konfirmo fjalëkalimin")}<input required type="password" autoComplete="new-password" value={form.data.password_confirmation} onChange={e => form.setData("password_confirmation", e.target.value)} /></label></div><FieldError message={form.errors.token} /><Button type="submit" className="register-submit" disabled={form.processing}>{t("Ruaj fjalëkalimin")}</Button></form>
  </KeeperAuthShell>;
}
