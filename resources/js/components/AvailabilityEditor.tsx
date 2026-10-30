import { useForm } from "@inertiajs/react";
import { useState, type FormEvent } from "react";
import { t, useLocale } from "../i18n";
import { calendarDates, countAvailableDates, displayAvailabilityTime, endsAfterMidnight, resolveAvailability, type WeeklyScheduleDay } from "../lib/availability";

type DayInput = WeeklyScheduleDay & { enabled: boolean };
type AvailabilityForm = { days: DayInput[]; offDates: string[]; extraDates: string[] };
type Props = { keeperId: number; hours: WeeklyScheduleDay[]; extraDates: string[]; offDates: string[]; today: string };
const dayNames = ["E hënë", "E martë", "E mërkurë", "E enjte", "E premte", "E shtunë", "E diel"];
const sundayFirstDays = ["E diel", ...dayNames.slice(0, 6)];
const monthNames = ["janar", "shkurt", "mars", "prill", "maj", "qershor", "korrik", "gusht", "shtator", "tetor", "nëntor", "dhjetor"];
const shortDays = ["Hën", "Mar", "Mër", "Enj", "Pre", "Sht", "Die"];
const hourOptions = Array.from({ length: 48 }, (_, index) => `${String(Math.floor(index / 2)).padStart(2, "0")}:${index % 2 ? "30" : "00"}`);

function dateObject(iso: string) { return new Date(`${iso}T12:00:00Z`); }
function dateLabel(iso: string) {
  const date = dateObject(iso);
  return `${t(sundayFirstDays[date.getUTCDay()])} ${date.getUTCDate()} ${t(monthNames[date.getUTCMonth()])}`;
}
function fullDateLabel(iso: string) {
  const date = dateObject(iso);
  return `${t(sundayFirstDays[date.getUTCDay()])}, ${date.getUTCDate()} ${t(monthNames[date.getUTCMonth()])} ${date.getUTCFullYear()}`;
}
function monthLabel(iso: string) { return t(monthNames[dateObject(iso).getUTCMonth()]).slice(0, 3); }

function normalizedExceptions(days: DayInput[], extraDates: string[], offDates: string[]) {
  const schedule = days.filter(day => day.enabled);
  return {
    offDates: offDates.filter(date => resolveAvailability(date, schedule, [], [date]).kind === "day_off"),
    extraDates: extraDates.filter(date => resolveAvailability(date, schedule, [date], []).kind === "extra"),
  };
}

export default function AvailabilityEditor({ keeperId, hours, extraDates, offDates, today }: Props) {
  useLocale();
  const initialDays = dayNames.map((_, index) => {
    const saved = hours.find(day => day.weekday === index + 1);
    return { weekday: index + 1, enabled: !!saved, start: saved?.start ?? "18:00", end: saved?.end ?? "23:00" };
  });
  const initialExceptions = normalizedExceptions(initialDays, extraDates, offDates);
  const form = useForm<AvailabilityForm>({ days: initialDays, ...initialExceptions });
  const [selectedDate, setSelectedDate] = useState(today);
  const [saved, setSaved] = useState(false);
  const [saveError, setSaveError] = useState(false);
  const { dates, leadingBlanks } = calendarDates(today);
  const schedule = form.data.days.filter(day => day.enabled);
  const hasInvalidHours = schedule.some(day => day.start === day.end);
  const count = countAvailableDates(today, schedule, form.data.extraDates, form.data.offDates);
  const selected = resolveAvailability(selectedDate, schedule, form.data.extraDates, form.data.offDates);
  const changed = (data: AvailabilityForm) => {
    setSaved(false);
    setSaveError(false);
    form.clearErrors();
    form.setData(data);
  };
  const changeDay = (weekday: number, patch: Partial<DayInput>) => {
    const days = form.data.days.map(day => day.weekday === weekday ? { ...day, ...patch } : day);
    changed({ ...form.data, days, ...normalizedExceptions(days, form.data.extraDates, form.data.offDates) });
  };
  const setPreset = (preset: "all" | "weekdays" | "weekends") => {
    const days = form.data.days.map(day => {
      const enabled = preset === "all" || (preset === "weekdays" ? day.weekday <= 5 : day.weekday >= 6);
      return { ...day, enabled, start: preset === "weekends" ? "12:00" : "18:00", end: "23:00" };
    });
    changed({ ...form.data, days, ...normalizedExceptions(days, form.data.extraDates, form.data.offDates) });
  };
  const updateSelectedDate = () => {
    if (selected.kind === "usual") changed({ ...form.data, offDates: [...new Set([...form.data.offDates, selectedDate])] });
    else if (selected.kind === "day_off") changed({ ...form.data, offDates: form.data.offDates.filter(date => date !== selectedDate) });
    else if (selected.kind === "off") changed({ ...form.data, extraDates: [...new Set([...form.data.extraDates, selectedDate])] });
    else changed({ ...form.data, extraDates: form.data.extraDates.filter(date => date !== selectedDate) });
  };
  const submit = (event: FormEvent) => {
    event.preventDefault();
    setSaved(false);
    setSaveError(false);
    let succeeded = false;
    form.transform(data => ({
      days: data.days.filter(day => day.enabled).map(({ weekday, start, end }) => ({ weekday, start, end })),
      offDates: data.offDates,
      extraDates: data.extraDates,
    }));
    form.put(`/keepers/${keeperId}/weekly-hours`, {
      preserveScroll: true,
      onSuccess: () => { succeeded = true; form.setDefaults(); setSaved(true); },
      onError: () => setSaveError(true),
      onFinish: () => { if (!succeeded) setSaveError(true); },
    });
  };
  const exceptionSummary = form.data.offDates.length || form.data.extraDates.length
    ? t(":offCount ditë pushimi dhe :extraCount data shtesë në 30 ditët e ardhshme.", { offCount: form.data.offDates.length, extraCount: form.data.extraDates.length })
    : t("Nuk ka ende përjashtime. Java jote e zakonshme vlen për çdo datë.");
  const selectedAction = selected.kind === "usual" ? "Shëno si ditë pushimi"
    : selected.kind === "day_off" ? "Hiq ditën e pushimit"
      : selected.kind === "extra" ? "Hiq datën shtesë" : "Shto si datë shtesë";
  const saveBar = <section className={`availability-savebar${form.isDirty ? " is-dirty" : ""}`} aria-live="polite">
    <div><strong>{saved ? t("Të gjitha ndryshimet u ruajtën") : form.isDirty ? t("Ke ndryshime të paruajtura") : t("Të gjitha ndryshimet u ruajtën")}</strong><span>{t("Mund ta ndryshosh orarin kur të duash.")}</span>{saveError && <span className="availability-save-error" role="alert">{t("Disponueshmëria nuk u ruajt. Kontrollo lidhjen dhe provo sërish.")}</span>}</div>
    <button type="submit" disabled={form.processing || !form.isDirty || hasInvalidHours}>{form.processing ? t("Duke ruajtur...") : t("Ruaj disponueshmërinë")}</button>
  </section>;

  return <form className="availability-editor" onSubmit={submit} aria-busy={form.processing}>
    <header className="availability-heading">
      <div><h1>{t("Disponueshmëria jote")}</h1><p>{t("Cakto javën tënde të zakonshme një herë dhe ne e përsërisim për 30 ditët e ardhshme. Pastaj shëno datat që ndryshojnë.")}</p></div>
      <span className="availability-count">{t("I/e disponueshëm në :count nga 30 ditët e ardhshme", { count })}</span>
    </header>
    {saveBar}

    <div className="availability-editor-grid">
      <section className="panel availability-card" aria-labelledby="usual-week-heading">
        <div className="availability-card-heading"><div><span className="availability-step">01</span><div><h2 id="usual-week-heading">{t("Java jote e zakonshme")}</h2><p>{t("Zgjidh ditët dhe vendos orarin për secilën.")}</p></div></div></div>
        <div className="availability-presets" aria-label={t("Nisje e shpejtë")}>
          <button type="button" onClick={() => setPreset("all")}>{t("Mbrëmje, çdo ditë")}</button>
          <button type="button" onClick={() => setPreset("weekdays")}>{t("Vetëm ditët e javës")}</button>
          <button type="button" onClick={() => setPreset("weekends")}>{t("Vetëm fundjavat")}</button>
        </div>
        <div className="availability-day-rows">
          {form.data.days.map((day, index) => <div className={`availability-day-row${day.enabled ? " is-enabled" : ""}`} key={day.weekday}>
            <div className="availability-day-identity"><button type="button" role="switch" aria-checked={day.enabled} disabled={form.processing} aria-label={t(":day: :state, kaloje :action", { day: t(dayNames[index]), state: t(day.enabled ? "I/e disponueshëm" : "Jo i/e disponueshëm"), action: t(day.enabled ? "çaktivizo" : "aktivizo") })} onClick={() => changeDay(day.weekday, { enabled: !day.enabled })}><span /></button><strong>{t(dayNames[index])}</strong></div>
            {day.enabled ? <div className="availability-time-range"><label><span>{t("Nga")}</span><select value={day.start} disabled={form.processing} onChange={event => changeDay(day.weekday, { start: event.target.value })}>{!hourOptions.includes(day.start) && <option value={day.start}>{day.start}</option>}{hourOptions.map(time => <option value={time} key={time}>{time}</option>)}</select></label><span className="availability-to">{t("deri")}</span><label><span>{t("Deri")}</span><select value={day.end} disabled={form.processing} onChange={event => changeDay(day.weekday, { end: event.target.value })}>{!hourOptions.includes(day.end) && <option value={day.end}>{day.end}</option>}{hourOptions.map(time => <option value={time} key={time}>{time}</option>)}</select></label>{day.end === day.start && <small className="availability-inline-error">{t("Ora e përfundimit duhet të ndryshojë nga ora e fillimit.")}</small>}{endsAfterMidnight(day.start, day.end) && <small className="availability-overnight">{t("Përfundon pas mesnatës dhe vazhdon ditën tjetër")}</small>}</div> : <span className="availability-not-available">{t("Jo i/e disponueshëm")}</span>}
          </div>)}
        </div>
        <p className="availability-tip">{t("Këshillë: për të përfunduar pas mesnatës, zgjidh një orë përfundimi më të hershme se fillimi (p.sh. 20:00 deri 02:00).")}</p>
      </section>

      <section className="panel availability-card" aria-labelledby="different-dates-heading">
        <div className="availability-card-heading"><div><span className="availability-step">02</span><div><h2 id="different-dates-heading">{t("Datat që ndryshojnë")}</h2><p>{t("Përjashtimet vlejnë vetëm për 30 ditët e ardhshme.")}</p></div></div></div>
        <div className="availability-legend" aria-label={t("Shpjegimi i gjendjeve")}>
          <span><i className="legend-usual" />{t("I/e disponueshëm")}</span><span><i className="legend-off" />{t("Jo i/e disponueshëm")}</span><span><i className="legend-day-off" />{t("Ditë pushimi")}</span><span><i className="legend-extra" />{t("Datë shtesë")}</span>
        </div>
        <div className="availability-calendar" role="group" aria-label={t("30 ditët e ardhshme")}>
          {shortDays.map(day => <span className="availability-weekday-label" key={day}>{t(day)}</span>)}
          {Array.from({ length: leadingBlanks }, (_, index) => <span className="availability-calendar-blank" aria-hidden="true" key={`blank-${index}`} />)}
          {dates.map((date, index) => {
            const day = resolveAvailability(date, schedule, form.data.extraDates, form.data.offDates);
            const stateLabel = day.kind === "usual" ? t("I/e disponueshëm") : day.kind === "day_off" ? t("Ditë pushimi") : day.kind === "extra" ? t("Datë shtesë") : t("Jo i/e disponueshëm");
            const topLabel = index === 0 ? t("Sot") : date.slice(-2) === "01" ? monthLabel(date) : "";
            const availabilityText = day.kind === "usual" ? `${displayAvailabilityTime(day.start ?? "")}–${displayAvailabilityTime(day.end ?? "")}` : day.kind === "extra" ? t("Shtesë") : day.kind === "day_off" ? t("Pushim") : t("Mbyllur");
            return <button type="button" key={date} aria-pressed={selectedDate === date} aria-label={t(":date, shënuar si :status", { date: dateLabel(date), status: stateLabel })} className={`availability-date-cell status-${day.kind}${selectedDate === date ? " is-selected" : ""}`} onClick={() => setSelectedDate(date)}>
              <span className="availability-date-tag">{topLabel}{index === 0 && date.slice(-2) === "01" ? ` · ${monthLabel(date)}` : ""}</span><strong>{dateObject(date).getUTCDate()}</strong><span className="availability-date-status">{availabilityText}</span>
            </button>;
          })}
        </div>
        <div className="availability-date-detail" aria-live="polite">
          <div><span>{fullDateLabel(selectedDate)}</span><strong>{selected.kind === "usual" ? `${t("I/e disponueshëm")} · ${displayAvailabilityTime(selected.start ?? "")}–${displayAvailabilityTime(selected.end ?? "")}` : selected.kind === "day_off" ? t("Ditë pushimi") : selected.kind === "extra" ? t("Datë shtesë · 18:00–23:00") : t("Jo i/e disponueshëm")}</strong></div>
          <button type="button" disabled={form.processing} onClick={updateSelectedDate}>{t(selectedAction)}</button>
        </div>
        <p className="availability-exception-summary">{exceptionSummary}</p>
      </section>
    </div>

  </form>;
}
