export type AvailabilityKind = "usual" | "day_off" | "extra" | "off";
export type WeeklyScheduleDay = { weekday: number; start: string; end: string };
export type ResolvedAvailability = { kind: AvailabilityKind; available: boolean; start: string | null; end: string | null };

const weekdayFor = (iso: string) => new Date(`${iso}T12:00:00Z`).getUTCDay() || 7;
const dateAtOffset = (iso: string, offset: number) => {
  const date = new Date(`${iso}T12:00:00Z`);
  date.setUTCDate(date.getUTCDate() + offset);
  return date.toISOString().slice(0, 10);
};

export function resolveAvailability(iso: string, schedule: WeeklyScheduleDay[], extraDates: string[], offDates: string[]): ResolvedAvailability {
  const usual = schedule.find(day => day.weekday === weekdayFor(iso));
  if (usual) {
    const dayOff = offDates.includes(iso);
    return { kind: dayOff ? "day_off" : "usual", available: !dayOff, start: dayOff ? null : usual.start, end: dayOff ? null : usual.end };
  }
  const extra = extraDates.includes(iso);
  return { kind: extra ? "extra" : "off", available: extra, start: extra ? "18:00" : null, end: extra ? "23:00" : null };
}

export function countAvailableDates(today: string, schedule: WeeklyScheduleDay[], extraDates: string[], offDates: string[]): number {
  return Array.from({ length: 30 }, (_, offset) => dateAtOffset(today, offset))
    .filter(date => resolveAvailability(date, schedule, extraDates, offDates).available).length;
}

export function calendarDates(today: string): { dates: string[]; leadingBlanks: number } {
  return { dates: Array.from({ length: 30 }, (_, offset) => dateAtOffset(today, offset)), leadingBlanks: weekdayFor(today) - 1 };
}

export function endsAfterMidnight(start: string, end: string): boolean {
  return end !== "00:00" && end <= start;
}

export function displayAvailabilityTime(time: string): string {
  return time.endsWith(":00") ? time.slice(0, -3) : time;
}
