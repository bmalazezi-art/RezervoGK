import { t } from "../i18n";
import { dateFromToday, displayDate } from "./dates";

/** WhatsApp opener that matches the date the organiser searched for, if any. */
export function whatsappMessage(goalkeeperName: string, requestedDate?: string | null, today: string = dateFromToday()): string {
  const values = { goalkeeper_name: goalkeeperName };
  if (!requestedDate) {
    return t("Tung :goalkeeper_name, të pashë te Gjej portier. A je i lirë për një ndeshje, për me mbrojt? 😄", values);
  }
  if (requestedDate === today) {
    return t("Tung :goalkeeper_name, të pashë te Gjej portier. Mos je i lirë sot për një ndeshje, për me mbrojt? 😄", values);
  }
  const tomorrow = new Date(`${today}T12:00:00`);
  tomorrow.setDate(tomorrow.getDate() + 1);
  const tomorrowKey = `${tomorrow.getFullYear()}-${String(tomorrow.getMonth() + 1).padStart(2, "0")}-${String(tomorrow.getDate()).padStart(2, "0")}`;
  if (requestedDate === tomorrowKey) {
    return t("Tung :goalkeeper_name, të pashë te Gjej portier. Mos je i lirë nesër për një ndeshje, për me mbrojt? 😄", values);
  }
  return t("Tung :goalkeeper_name, të pashë te Gjej portier. Mos je i lirë më :date për një ndeshje, për me mbrojt? 😄", { ...values, date: displayDate(requestedDate) });
}
