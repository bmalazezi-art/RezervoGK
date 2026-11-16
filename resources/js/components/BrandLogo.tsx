import { t, useLocale } from "../i18n";

export function Logo({ onClick, tone = "dark" }: { onClick: () => void; tone?: "dark" | "light" }) {
  useLocale();

  return (
    <button className={`logo${tone === "light" ? " logo-light" : ""}`} onClick={onClick} aria-label={t("Kthehu në ballinë")}>
      <img
        className="brand-lockup"
        src={tone === "light" ? "/brand/gjej-portier-logo-on-light.svg" : "/brand/gjej-portier-logo-on-dark.svg"}
        alt=""
        aria-hidden="true"
        width="272"
        height="72"
      />
    </button>
  );
}
