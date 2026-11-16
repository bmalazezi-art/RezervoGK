import { t, useLocale } from "../i18n";
import "../../css/admin-dashboard.css";

type Props = {
  checks: {
    backup: { ok: boolean; finishedAt: string | null; bytes: number | null; keptDays: number | null };
    mail: { ok: boolean; issue: string | null };
    scheduler: { ok: boolean; lastRunAt: string | null };
    disk: { ok: boolean; freeBytes: number | null; usedPercent: number | null };
    cleanup: { pending: number };
  };
  version: { commit: string | null; php: string; laravel: string; environment: string };
  checkedAt: string;
};

const when = (value: string | null) => value ? new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" }).format(new Date(value)) : "–";
const size = (bytes: number | null) => bytes === null ? "–" : bytes > 1e9 ? `${(bytes / 1e9).toFixed(1)} GB` : bytes > 1e6 ? `${(bytes / 1e6).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1e3))} KB`;

function Card({ title, ok, children }: { title: string; ok: boolean; children: React.ReactNode }) {
  return <article className="panel admin-health-card"><span className={`health-pill ${ok ? "ok" : "bad"}`}>{ok ? t("Në rregull") : t("Kërkon vëmendje")}</span><h2>{title}</h2>{children}</article>;
}

export default function AdminHealth({ checks, version, checkedAt }: Props) {
  useLocale();
  const mailIssues: Record<string, string> = { disabled: "Email-et transaksionale janë të çaktivizuara.", smtp_security: "Cilësimet e sigurisë së SMTP mungojnë.", sender: "Adresa e dërguesit nuk është e vlefshme.", canonical_url: "APP_URL nuk është https i vlefshëm." };
  return <main className="page-main dashboard-page admin-dashboard">
    <div className="dash-top section"><div><span>{t("ADMINISTRIMI")}</span><h1>{t("Gjendja e sistemit")}</h1><p>{t("Kontrolluar më :time.", { time: when(checkedAt) })}</p></div></div>
    <section className="section admin-health-grid">
      <Card title={t("Backup-i i natës")} ok={checks.backup.ok}><p>{checks.backup.finishedAt ? t("I fundit: :time · :size · :days ditë të ruajtura", { time: when(checks.backup.finishedAt), size: size(checks.backup.bytes), days: checks.backup.keptDays ?? 0 }) : t("Ende nuk ka informacion për backup-in. Ai shfaqet pas backup-it të radhës.")}</p></Card>
      <Card title={t("Email-et")} ok={checks.mail.ok}><p>{checks.mail.ok ? t("Rivendosja e fjalëkalimit dhe kodet e administratorit mund të dërgohen.") : t(mailIssues[checks.mail.issue ?? ""] ?? "Konfigurimi i email-it ka problem: :issue", { issue: checks.mail.issue ?? "" })}</p></Card>
      <Card title={t("Detyrat automatike")} ok={checks.scheduler.ok}><p>{checks.scheduler.lastRunAt ? t("Ekzekutimi i fundit: :time", { time: when(checks.scheduler.lastRunAt) }) : t("Ende nuk është regjistruar asnjë ekzekutim.")}</p></Card>
      <Card title={t("Hapësira në disk")} ok={checks.disk.ok}><p>{checks.disk.usedPercent === null ? "–" : t(":used% e përdorur · :free të lira", { used: checks.disk.usedPercent, free: size(checks.disk.freeBytes) })}</p></Card>
      <Card title={t("Pastrimi pas fshirjes së llogarive")} ok={checks.cleanup.pending === 0}><p>{checks.cleanup.pending === 0 ? t("Asnjë skedar në pritje.") : t(":count skedarë presin të fshihen; provohet automatikisht.", { count: checks.cleanup.pending })}</p></Card>
      <article className="panel admin-health-card"><h2>{t("Versioni live")}</h2><p>{t("Commit :commit · PHP :php · Laravel :laravel · :env", { commit: version.commit ?? "–", php: version.php, laravel: version.laravel, env: version.environment })}</p></article>
    </section>
  </main>;
}
