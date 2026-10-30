import { getLocale } from "../i18n";

export function dateFromToday(days = 0): string {
  const date = new Date();
  date.setDate(date.getDate() + days);
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}
// Explicit CLDR/Carbon labels also work in browsers without Albanian Intl data.
export function displayDate(date: string): string {
  return date.split("-").reverse().join(getLocale() === "sq" ? "." : "/");
}
export function weekdayShort(date: string): string {
  const labels = getLocale() === "sq" ? ["die", "hën", "mar", "mër", "enj", "pre", "sht"] : ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
  return labels[new Date(`${date}T12:00:00`).getDay()].toUpperCase();
}
