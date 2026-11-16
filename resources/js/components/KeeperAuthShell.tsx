import { t, ui, useLocale } from "../i18n";
import type { ReactNode } from "react";
import { usePage } from "@inertiajs/react";
import { Icon } from "./marketplace";

export function FieldError({ message }: { message?: string }) {
  useLocale();
  return message ? <small role="alert">{ui(message)}</small> : null;
}

export function AuthStatus() {
  useLocale();
  const { flash } = usePage<{ flash: { status: string | null } }>().props;
  return flash.status ? <p role="status">{ui(flash.status)}</p> : null;
}

export default function KeeperAuthShell({ children }: { children: ReactNode }) {
  useLocale();
  return <main className="page-main register-page"><section className="register-intro"><div className="eyebrow light"><span /> {t("BËHU PJESË E EKIPIT")}</div><h1>{t("Porta është e jotja.")}</h1><p>{t("Ndërto profilin tënd, cakto çmimin dhe luaj më shumë ndeshje pranë teje.")}</p><ul><li><Icon name="check" /> {t("Shërbim plotësisht falas")}</li><li><Icon name="check" /> {t("Ti mban 100% të pagesës")}</li><li><Icon name="check" /> {t("Pa abonim ose tarifë platforme.")}</li></ul><div className="register-quote">{t("Ti cakton çmimin dhe datat. Organizatori të kontakton direkt për të konfirmuar ndeshjen.")}<span>{t("Organizatori dhe portieri merren vesh drejtpërdrejt për mënyrën e pagesës dhe shumën. Gjej portier nuk mbledh, proceson apo transferon pagesat për ndeshje.")}</span></div></section>
    <section className="register-form">{children}</section>
  </main>;
}
