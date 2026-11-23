import { t } from "../i18n";
import { displayDate } from "./dates";
import type { Keeper } from "../types/goalkeeper";

export const keeperCityNames = (keeper: Keeper) => keeper.cities?.length ? keeper.cities.map(city => city.name) : (keeper.cityName ? [keeper.cityName] : []);
export const keeperLocation = (keeper: Keeper) => {
  const names = keeperCityNames(keeper);
  return names.length && keeper.countryName ? `${names.join(" · ")}, ${t(keeper.countryName)}` : keeper.city;
};
export function keeperAvailability(keeper: Keeper, requestedDate?: string): string {
  const label = keeperAvailabilityDate(keeper, requestedDate);
  const showCity = keeper.availableCityName && (requestedDate ? keeper.availableOnRequestedDate : keeper.availabilityKind !== "none");
  return showCity ? t(":availability në :city", { availability: label, city: keeper.availableCityName ?? "" }) : label;
}
function keeperAvailabilityDate(keeper: Keeper, requestedDate?: string): string {
  if (requestedDate) return keeper.availableOnRequestedDate
    ? t("I lirë më :date", { date: displayDate(requestedDate) })
    : t("Jo i lirë më :date", { date: displayDate(requestedDate) });
  if (keeper.availabilityKind === "none") return t("Pa data të publikuara");
  if (keeper.availabilityKind === "today") return t("I lirë sot");
  if (keeper.availabilityKind === "tomorrow") return t("I lirë nesër");
  if (keeper.availableOn) return t("I lirë më :date", { date: displayDate(keeper.availableOn) });
  return t(keeper.available);
}
