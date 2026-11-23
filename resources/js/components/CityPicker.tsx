import { t, useLocale } from "../i18n";
import type { CityOption } from "../types/goalkeeper";
import { FieldError } from "./KeeperAuthShell";

/** Server limit: the primary city plus up to four more, all in one country. */
export const MAX_EXTRA_CITIES = 4;

/** Extra cities a goalkeeper also plays in, chosen from the primary city's country. */
export function ExtraCityPicker({ cities, countryId, primaryCityId, value, onChange, error }: {
  cities: CityOption[]; countryId: number | string | undefined; primaryCityId: string; value: string[];
  onChange: (value: string[]) => void; error?: string;
}) {
  useLocale();
  const options = cities.filter(city => String(city.country_id) === String(countryId) && String(city.id) !== primaryCityId);
  const full = value.length >= MAX_EXTRA_CITIES;
  return <fieldset className="extra-city-picker">
    <legend>{t("Luan edhe në qytete të tjera?")}</legend>
    <small>{t("Opsionale. Zgjidh deri në :max qytete në të njëjtin shtet. Te disponueshmëria cakton cilat ditë je në cilin qytet.", { max: MAX_EXTRA_CITIES })}</small>
    <div className="extra-city-options">{options.map(city => {
      const checked = value.includes(String(city.id));
      return <label key={city.id} className={checked ? "selected" : ""}><input type="checkbox" checked={checked} disabled={!checked && full} onChange={event => onChange(event.target.checked ? [...value, String(city.id)] : value.filter(id => id !== String(city.id)))} /> {city.name}</label>;
    })}</div>
    <FieldError message={error} />
  </fieldset>;
}
