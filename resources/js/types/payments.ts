import { getLocale } from "../i18n";
export type BankDetails = { enabled: boolean; beneficiary: string; bankName: string; account: string; bic: string };
export type ManualPayment = {
  id: number; reference: string; amount: number; currency: string; status: "draft" | "pending" | "approved" | "rejected";
  bank: BankDetails; paidOn: string | null; hasReceipt: boolean; receiptUrl: string | null;
  createdAt: string; earliestPaidOn: string; submittedAt: string | null; reviewedAt: string | null;
  rejectionReason: string | null; periodStartsAt: string | null; periodEndsAt: string | null;
};
export type AccessStatus = { isEligible: boolean; subscriptionEndsAt: string | null; subscriptionEndsAtLabel: string | null; subscriptionStatus: "inactive" | "active" | "scheduled" | "expired" };
export const paymentStatus = { draft: "Në përgatitje", pending: "Në pritje të verifikimit", approved: "E miratuar", rejected: "E refuzuar" };
export const paymentDate = (date: string | null) => {
  if (!date) return "—";
  const parts = new Intl.DateTimeFormat("en-GB", { timeZone: "Europe/Belgrade", day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit", hourCycle: "h23" }).formatToParts(new Date(date));
  const part = (type: Intl.DateTimeFormatPartTypes) => parts.find(value => value.type === type)?.value ?? "";
  const separator = getLocale() === "sq" ? "." : "/";
  return `${part("day")}${separator}${part("month")}${separator}${part("year")} ${part("hour")}:${part("minute")}`;
};
