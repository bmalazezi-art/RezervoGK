import { t, useLocale } from "../i18n";
import { useForm } from "@inertiajs/react";
import { useState, type FormEvent } from "react";
import { FieldError } from "./KeeperAuthShell";
import { Button, Icon } from "./marketplace";

export type WeeklyHour = { weekday: number; start: string; end: string; endsNextDay: boolean };
const weekdays = ["E hënë", "E martë", "E mërkurë", "E enjte", "E premte", "E shtunë", "E diel"];
const guidance = "Ky orar përsërit disponueshmërinë për 30 ditët e ardhshme. Konfirmoni orën e ndeshjes direkt me portierin.";

export function WeeklySchedule({ hours }: { hours: WeeklyHour[] }) {
  useLocale();
  return <section className="panel" aria-label={t("Disponueshmëria javore")}><div className="panel-head"><div><Icon name="clock" /><h2>{t("Disponueshmëria javore")}</h2><span>{t("Përsëritet")}</span></div></div>
    <p>{t(guidance)}</p>
    {hours.length === 0 ? <p>{t("Portieri nuk ka publikuar ende orar javor.")}</p> : <dl>{weekdays.map((label, index) => {
      const day = hours.find(hour => hour.weekday === index + 1);
      return <div className="flex justify-between items-start gap-3 py-3 border-b border-[var(--line)] text-sm" key={label}><dt className="font-semibold">{t(label)}</dt><dd className="m-0 text-right">{day ? <><span className="whitespace-nowrap tabular-nums">{day.start} – {day.end}</span>{day.endsNextDay && <small className="block">{t("Përfundon ditën tjetër")}</small>}</> : t("Pa orar të publikuar")}</dd></div>;
    })}</dl>}
  </section>;
}

type DayInput = { weekday: number; enabled: boolean; start: string; end: string };
export function WeeklyHoursEditor({ keeperId, hours }: { keeperId: number; hours: WeeklyHour[] }) {
  useLocale();
  const firstSaved = hours[0];
  const initialStart = firstSaved?.start ?? "18:00";
  const initialEnd = firstSaved?.end ?? "00:00";
  const [activeWeekday, setActiveWeekday] = useState(firstSaved?.weekday ?? 1);
  const form = useForm<{ days: DayInput[] }>({ days: weekdays.map((_, index) => {
    const day = hours.find(hour => hour.weekday === index + 1);
    return { weekday: index + 1, enabled: !!day, start: day?.start ?? initialStart, end: day?.end ?? initialEnd };
  }) });
  const [saved, setSaved] = useState(false);
  const selected = form.data.days.filter(day => day.enabled);
  const editingDay = selected.find(day => day.weekday === activeWeekday) ?? selected[0];
  const errors = form.errors as Record<string, string | undefined>;
  const change = (weekday: number, values: Partial<DayInput>) => { setSaved(false); form.clearErrors(); form.setData("days", form.data.days.map(day => day.weekday === weekday ? { ...day, ...values } : day)); };
  const setDayEnabled = (weekday: number, enabled: boolean) => {
    change(weekday, { enabled });
    if (enabled) setActiveWeekday(weekday);
    else if (activeWeekday === weekday) setActiveWeekday(selected.find(day => day.weekday !== weekday)?.weekday ?? 1);
  };
  const submit = (event: FormEvent) => {
    event.preventDefault(); setSaved(false);
    form.transform(data => ({ days: data.days.filter(day => day.enabled).map(({ weekday, start, end }) => ({ weekday, start, end })) }));
    form.put(`/keepers/${keeperId}/weekly-hours`, { preserveScroll: true, onSuccess: () => setSaved(true) });
  };
  return <form className="panel weekly-editor" onSubmit={submit} aria-label={t("Ndrysho disponueshmërinë javore")} aria-busy={form.processing}>
    <div className="panel-head"><div><Icon name="clock" /><h2>{t("Disponueshmëria javore")}</h2></div>{selected.length > 0 && <span>{selected.length} {t("ditë të zgjedhura")}</span>}</div>
    <p className="editor-intro">{t("Zgjidh ditët kur je zakonisht i lirë, pastaj cakto orarin për secilën ditë. Kjo përsëritet automatikisht për 30 ditët e ardhshme.")}</p>
    <div className="weekday-picker" role="group" aria-label={t("Zgjidh ditët e lira")}>
      {form.data.days.map((day, index) => <label key={day.weekday} className={day.enabled ? "selected" : ""}><input type="checkbox" checked={day.enabled} disabled={form.processing} onChange={event => setDayEnabled(day.weekday, event.target.checked)} /><span>{t(weekdays[index])}</span></label>)}
    </div>
    <FieldError message={errors.days} />
    {selected.length > 0 ? <>
      <div className="weekly-day-hours">
        <label>{t("Cakto orarin për")}
          <select value={editingDay?.weekday ?? ""} onChange={event => setActiveWeekday(Number(event.target.value))}>
            {selected.map(day => <option key={day.weekday} value={day.weekday}>{t(weekdays[day.weekday - 1])}</option>)}
          </select>
        </label>
        {editingDay && <div className="weekly-day-time-fields"><label>{t("Nga")}<input type="time" step="60" required disabled={form.processing} value={editingDay.start} onChange={event => change(editingDay.weekday, { start: event.target.value })} /><FieldError message={errors[`days.${selected.findIndex(day => day.weekday === editingDay.weekday)}.start`]} /></label><label>{t("Deri")}<input type="time" step="60" required disabled={form.processing} value={editingDay.end} onChange={event => change(editingDay.weekday, { end: event.target.value })} /><FieldError message={errors[`days.${selected.findIndex(day => day.weekday === editingDay.weekday)}.end`]} /></label></div>}
      </div>
      {editingDay && editingDay.end < editingDay.start && <small className="weekly-overnight"><Icon name="clock" size={13} /> {t("Përfundon ditën tjetër")}</small>}
    </> : <p className="editor-empty-state">{t("Nuk ke zgjedhur ende ditë. Zgjidh të paktën një ditë që ekipet të të gjejnë.")}</p>}
    <p className="editor-hint">{t("Për mesnatë përdor 00:00. Një orë përfundimi më e hershme vazhdon ditën tjetër.")}</p>
    <div className="weekly-editor-footer"><p className="editor-hint">{t("Mund ta ndryshosh orarin kur të duash.")}</p><Button type="submit" disabled={form.processing}>{form.processing ? t("Duke ruajtur...") : t("Ruaj disponueshmërinë")}</Button></div>
    {saved && <p className="editor-feedback editor-success" role="status">{t("Orari javor u ruajt. Kërkimi përsërit disponueshmërinë për 30 ditët e ardhshme.")}</p>}
    <FieldError message={errors.days} />
    {Object.keys(errors).length > 0 && <p className="editor-feedback editor-error" role="alert">{t("Kontrollo ditët dhe orët e shënuara, pastaj provo përsëri.")}</p>}
  </form>;
}
