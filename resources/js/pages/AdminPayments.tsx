import { t, useLocale } from "../i18n";
import { router, useForm, usePage } from "@inertiajs/react";
import type { FormEvent } from "react";
import { Button } from "../components/marketplace";
import { AuthStatus, FieldError } from "../components/KeeperAuthShell";
import { paymentDate, paymentStatus, type ManualPayment } from "../types/payments";
import { displayDate } from "../lib/dates";

const auditEvents: Record<string, string> = { issued: "Referenca u krijua", submitted: "Njoftimi u dërgua", approved: "Pagesa u miratua", rejected: "Pagesa u refuzua", receipt_downloaded: "Dëshmia u shkarkua" };
const auditFields: Record<string, string> = { from: "Statusi i mëparshëm", to: "Statusi i ri", amount_cents: "Shuma (EUR)", currency: "Monedha", has_receipt: "Ka dëshmi", subscription_id: "Numri i periudhës", starts_at: "Fillimi", ends_at: "Përfundimi", verified_amount_cents: "Shuma e verifikuar (EUR)", bank_verified: "U verifikua në bankë", bank_transaction_reference: "Transaksioni bankar", locale: "Gjuha e njoftimit", received_on: "Data e pranimit" };
function auditValue(key: string, value: unknown): string {
  if (typeof value === "boolean") return t(value ? "Po" : "Jo");
  if (key === "amount_cents" || key === "verified_amount_cents") return (Number(value) / 100).toFixed(2);
  if (typeof value === "string") {
    if (key === "locale") return value === "en" ? "English" : "Shqip";
    if (key === "starts_at" || key === "ends_at") return paymentDate(value);
    if (key === "received_on") return displayDate(value);
    if (key === "from" || key === "to") return t(paymentStatus[value as ManualPayment["status"]] ?? "Status tjetër");
    return value;
  }
  return value == null ? "—" : String(value);
}

type AdminPayment = ManualPayment & { owner: { id: number; name: string; email: string }; senderNote: string | null; reviewNote: string | null; bankTransactionReference: string | null;
  audits: { id: number; event: string; actorId: number; at: string; metadata: Record<string, unknown> }[] };
function Review({ payment, today }: { payment: AdminPayment; today: string }) {
  useLocale();
  const form = useForm({ decision: "approved", bank_verified: false, received_amount: "7.00", bank_transaction_reference: "", received_on: today, review_note: "" });
  const submit = (event: FormEvent) => { event.preventDefault(); form.post(`/admin/payments/${payment.id}/review`, { preserveScroll: true }); };
  return <form onSubmit={submit}><div className="form-grid"><label>{t("Vendimi")}<select value={form.data.decision} onChange={e => form.setData("decision", e.target.value)}><option value="approved">{t("Mirato pas verifikimit bankar")}</option><option value="rejected">{t("Refuzo")}</option></select><FieldError message={form.errors.decision} /></label></div>
    {form.data.decision === "approved" && <><p>{t("Kontrollo llogarinë bankare jashtë platformës: përfituesin, referencën e pagesës, monedhën EUR dhe €7 të pranuara. Një imazh/PDF ose pretendim i portierit nuk provon pagesën.")}</p>
      <div className="form-grid"><label>{t("Identifikuesi unik i transaksionit bankar")}<input required value={form.data.bank_transaction_reference} maxLength={120} onChange={e => form.setData("bank_transaction_reference", e.target.value)} /><FieldError message={form.errors.bank_transaction_reference} /></label>
        <label>{t("Shuma e pranuar (EUR)")}<input required type="number" min="7" max="7" step="0.01" value={form.data.received_amount} onChange={e => form.setData("received_amount", e.target.value)} /><FieldError message={form.errors.received_amount} /></label>
        <label>{t("Data e pranimit në bankë")}<input required type="date" min={payment.earliestPaidOn} max={today} value={form.data.received_on} onChange={e => form.setData("received_on", e.target.value)} /><FieldError message={form.errors.received_on} /></label></div>
      <label><input required type="checkbox" checked={form.data.bank_verified} onChange={e => form.setData("bank_verified", e.target.checked)} /> {t("Verifikova në bankë marrjen e €7 EUR me referencën përkatëse.")}</label><FieldError message={form.errors.bank_verified} /></>}
    <label className="full-label">{form.data.decision === "rejected" ? t("Arsyeja e refuzimit (e dukshme për portierin)") : t("Shënim i brendshëm")}<textarea required={form.data.decision === "rejected"} maxLength={1000} value={form.data.review_note} onChange={e => form.setData("review_note", e.target.value)} /><FieldError message={form.errors.review_note} /></label>
    <Button type="submit" disabled={form.processing}>{form.data.decision === "approved" ? t("Mirato pagesën e verifikuar") : t("Refuzo pagesën")}</Button>
  </form>;
}
export default function AdminPayments({ payments, filter, today }: { payments: { data: AdminPayment[]; prev_page_url: string | null; next_page_url: string | null }; filter: ManualPayment["status"]; today: string }) {
  useLocale();
  const { auth } = usePage<{ auth: { user: { id: number } } }>().props;
  return <main className="page-main dashboard-page"><div className="section dash-top"><div><span>{t("ADMINISTRIMI")}</span><h1>{t("Verifikimi i pagesave")}</h1><p>{t("Miratimi bëhet vetëm pasi fondet të konfirmohen në bankë.")}</p></div></div>
    <section className="section"><AuthStatus /><div className="panel"><label>{t("Statusi")}<select aria-label={t("Statusi i pagesave")} value={filter} onChange={e => router.get("/admin/payments", { status: e.target.value })}>{Object.entries(paymentStatus).map(([value,label]) => <option key={value} value={value}>{t(label)}</option>)}</select></label></div>
      {payments.data.length === 0 && <div className="panel"><p>{t("Nuk ka pagesa me këtë status.")}</p></div>}
      {payments.data.map(payment => <article className="panel min-w-0" key={payment.id}><div className="panel-head"><div><h2 className="break-all">{payment.reference}</h2><span>{t(paymentStatus[payment.status])}</span></div></div>
        <p>{payment.owner.name} · {payment.owner.email} · €{payment.amount} {payment.currency}</p><p>{t("Data e deklaruar:")} {payment.paidOn ? displayDate(payment.paidOn) : "—"}{t(". Përfituesi:")} {payment.bank.beneficiary} · {payment.bank.bankName} · <span className="break-all">{payment.bank.account}</span></p>
        {payment.senderNote && <p>{t("Shënimi i portierit:")} {payment.senderNote}</p>}
        {payment.receiptUrl && <a href={payment.receiptUrl}>{t("Shkarko dëshminë private")}</a>}
        {payment.periodEndsAt && <p>{t("Periudha e miratuar:")} {paymentDate(payment.periodStartsAt)} — {paymentDate(payment.periodEndsAt)}</p>}
        {payment.bankTransactionReference && <p>{t("Transaksioni i verifikuar:")} {payment.bankTransactionReference}</p>}
        {payment.reviewNote && <p>{t("Shënimi i kontrollit:")} {payment.reviewNote}</p>}
        {payment.status === "pending" && (payment.owner.id === auth.user.id ? <p>{t("Nuk lejohet miratimi ose refuzimi i pagesës tënde.")}</p> : <Review payment={payment} today={today} />)}
        <details><summary>{t("Historia e auditimit")}</summary>{payment.audits.map(audit => <div key={audit.id}><p>{paymentDate(audit.at)} · {t(auditEvents[audit.event] ?? "Veprim i regjistruar")} {t("· Llogaria #")}{audit.actorId}</p><dl className="break-all">{Object.entries(audit.metadata).map(([key, value]) => <div key={key}><dt>{t(auditFields[key] ?? "Të dhëna shtesë")}</dt><dd>{auditValue(key, value)}</dd></div>)}</dl></div>)}</details>
      </article>)}
      <div className="chips">{payments.prev_page_url && <Button variant="outline" onClick={() => router.visit(payments.prev_page_url!)}>{t("Më parë")}</Button>}{payments.next_page_url && <Button variant="outline" onClick={() => router.visit(payments.next_page_url!)}>{t("Më pas")}</Button>}</div>
    </section>
  </main>;
}
