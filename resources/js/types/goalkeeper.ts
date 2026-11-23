import type { PriceCurrency } from "../lib/pricing";
import type { WeeklyHour } from "../components/WeeklyHours";

export interface Keeper {
  id: number;
  slug?: string;
  url?: string;
  verifiedByAdmin?: boolean;
  name: string;
  city: string;
  cityName?: string;
  countryName?: string;
  /** Primary city first, then the other cities the goalkeeper plays in. */
  cities?: KeeperCity[];
  /** City of the shown availability date, only for goalkeepers in more than one city. */
  availableCityName?: string | null;
  availableOn?: string | null;
  availabilityKind?: "today" | "tomorrow" | "date" | "none";
  price: number;
  priceAmount: string;
  priceMinor: number;
  currency: PriceCurrency;
  formats: string[];
  photo: string;
  available: string;
  phone: string;
  whatsapp?: string | null;
  featured: boolean;
  availabilityDates?: string[];
  requestedDate?: string | null;
  availableOnRequestedDate?: boolean | null;
}

export interface PublicKeeper extends Keeper {
  bio: string;
  languages: string[];
  experienceYears: number;
  weeklyHours: WeeklyHour[];
  availabilityDates: string[];
}

export interface KeeperCity { id: number; name: string }
export interface CityOption { id: number; name: string; country_id: number; currencies: PriceCurrency[]; defaultCurrency: PriceCurrency }
export interface CountryOption { id: number; name: string; currencies: PriceCurrency[]; defaultCurrency: PriceCurrency }
export interface BrowseFilters {
  city_id: string;
  country_id: string;
  max_price: number;
  currency: PriceCurrency;
  format: string;
  date: string;
}

export interface CalendarDay { iso: string; day: string; date: number; available: boolean; cityId?: number | null; cityName?: string | null }
