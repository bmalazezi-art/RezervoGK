import { t } from "../i18n";
import { displayDate } from "./dates";
import type { Keeper } from "../types/goalkeeper";

export const keeperLocation = (keeper: Keeper) => keeper.cityName && keeper.countryName ? `${keeper.cityName}, ${t(keeper.countryName)}` : keeper.city;
export function keeperAvailability(keeper: Keeper, requestedDate?: string): string {
  if (requestedDate) return keeper.availableOnRequestedDate
    ? t("I lirë më :date", { date: displayDate(requestedDate) })
    : t("Jo i lirë më :date", { date: displayDate(requestedDate) });
  if (keeper.availabilityKind === "none") return t("Pa data të publikuara");
  if (keeper.availabilityKind === "today") return t("I lirë sot");
  if (keeper.availabilityKind === "tomorrow") return t("I lirë nesër");
  if (keeper.availableOn) return t("I lirë më :date", { date: displayDate(keeper.availableOn) });
  return t(keeper.available);
}
