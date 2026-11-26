import { getLocale, localeTag } from "../i18n";
export type PriceCurrency = "EUR" | "ALL" | "MKD";
export const priceCurrencies: PriceCurrency[] = ["EUR", "ALL", "MKD"];
export const defaultPriceLimit = (currency: PriceCurrency) => currency === "EUR" ? 15 : 10000;
// Used only when the browser lacks Intl data for the language.
const manualFormats = { sq: { group: "\u00a0", minGroup: 5 }, mk: { group: ".", minGroup: 4 } };
export function formatPrice(amount: string, currency: PriceCurrency): string {
  const [whole, fraction = "00"] = amount.split(".");
  const formatter = new Intl.NumberFormat(localeTag(), {
    style: "currency", currency, currencyDisplay: currency === "EUR" ? "symbol" : "code",
    minimumFractionDigits: Number(fraction) === 0 ? 0 : 2, maximumFractionDigits: 2,
  });
  const locale = getLocale();
  const fallback = manualFormats[locale as keyof typeof manualFormats];
  if (fallback && !formatter.resolvedOptions().locale.startsWith(locale)) {
    // CLDR sq/mk: comma decimal, currency suffix; sq groups with NBSP from 5 digits, mk with dots from 4.
    // Format the canonical decimal string; never convert amounts between currencies.
    const integer = whole.replace(/^0+(?=\d)/, "");
    const grouped = integer.length >= fallback.minGroup ? integer.replace(/\B(?=(\d{3})+(?!\d))/g, fallback.group) : integer;
    return `${grouped}${Number(fraction) === 0 ? "" : `,${fraction.padEnd(2, "0").slice(0, 2)}`}\u00a0${currency === "EUR" ? "€" : currency}`;
  }
  return formatter.format(Number(amount));
}
export const formatKeeperPrice = (keeper: { priceAmount: string; currency: PriceCurrency }) => formatPrice(keeper.priceAmount, keeper.currency);
