import { getLocale, localeTag } from "../i18n";
export type PriceCurrency = "EUR" | "ALL" | "MKD";
export const priceCurrencies: PriceCurrency[] = ["EUR", "ALL", "MKD"];
export const defaultPriceLimit = (currency: PriceCurrency) => currency === "EUR" ? 15 : 10000;
export function formatPrice(amount: string, currency: PriceCurrency): string {
  const [whole, fraction = "00"] = amount.split(".");
  const formatter = new Intl.NumberFormat(localeTag(), {
    style: "currency", currency, currencyDisplay: currency === "EUR" ? "symbol" : "code",
    minimumFractionDigits: Number(fraction) === 0 ? 0 : 2, maximumFractionDigits: 2,
  });
  if (getLocale() === "sq" && !formatter.resolvedOptions().locale.startsWith("sq")) {
    // CLDR sq: comma decimal, NBSP grouping (5+ integer digits), currency suffix.
    // Format the canonical decimal string; never convert amounts between currencies.
    const integer = whole.replace(/^0+(?=\d)/, "");
    const grouped = integer.length >= 5 ? integer.replace(/\B(?=(\d{3})+(?!\d))/g, "\u00a0") : integer;
    return `${grouped}${Number(fraction) === 0 ? "" : `,${fraction.padEnd(2, "0").slice(0, 2)}`}\u00a0${currency === "EUR" ? "€" : currency}`;
  }
  return formatter.format(Number(amount));
}
export const formatKeeperPrice = (keeper: { priceAmount: string; currency: PriceCurrency }) => formatPrice(keeper.priceAmount, keeper.currency);
