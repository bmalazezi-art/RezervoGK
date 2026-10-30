import { t, useLocale } from "../i18n";

export function Logo({ onClick, tone = "dark" }: { onClick: () => void; tone?: "dark" | "light" }) {
  useLocale();

  return (
    <button className={`logo${tone === "light" ? " logo-light" : ""}`} onClick={onClick} aria-label={t("Kthehu në ballinë")}>
      <img
        className="brand-lockup"
        src={tone === "light" ? "/brand/gjej-portier-horizontal-light.svg" : "/brand/gjej-portier-horizontal-dark.svg"}
        alt=""
        aria-hidden="true"
        width="224"
        height="56"
      />
    </button>
  );
}
