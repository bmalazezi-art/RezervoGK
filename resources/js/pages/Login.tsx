import { t, useLocale } from "../i18n";
import { type FormEvent } from "react";
import { router, useForm } from "@inertiajs/react";
import { Button } from "../components/marketplace";
import KeeperAuthShell, { AuthStatus, FieldError } from "../components/KeeperAuthShell";

export default function Login({ admin = false }: { admin?: boolean }) {
  useLocale();
  const form = useForm({ email: "", password: "", remember: false });
  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    form.post("/login", { replace: true, onFinish: () => form.reset("password") });
  };
  return <KeeperAuthShell><span>{admin ? t("ADMINISTRIMI") : t("PËR PORTIERËT")}</span><h2>{admin ? t("Kyçu si administrator") : t("Mirë se u ktheve")}</h2><p>{admin ? t("Vetëm për administratorët e Gjej portier.") : t("Kyçu për të menaxhuar profilin tënd.")}</p><AuthStatus />
    <form onSubmit={submit}><div className="form-stack"><label>{t("Email")}<input required type="email" autoComplete="username" value={form.data.email} onChange={e => form.setData("email", e.target.value)} /><FieldError message={form.errors.email} /></label><label>{t("Fjalëkalimi")}<input required type="password" autoComplete="current-password" value={form.data.password} onChange={e => form.setData("password", e.target.value)} /><FieldError message={form.errors.password} /></label></div><label><input type="checkbox" checked={form.data.remember} onChange={e => form.setData("remember", e.target.checked)} /> {t("Më mbaj mend")}</label><Button type="submit" className="register-submit" disabled={form.processing}>{form.processing ? t("Duke u kyçur...") : t("Kyçu")}</Button></form>
    <button className="text-link" onClick={() => router.visit("/forgot-password")}>{t("Ke harruar fjalëkalimin?")}</button>{!admin && <button className="text-link" onClick={() => router.visit("/register")}>{t("Krijo një profil portieri")}</button>}
  </KeeperAuthShell>;
}
