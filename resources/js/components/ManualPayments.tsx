import { t, useLocale } from "../i18n";
import { router, useForm } from "@inertiajs/react";
import { useRef, type FormEvent } from "react";
import { Button } from "./marketplace";
import { AuthStatus, FieldError } from "./KeeperAuthShell";
import { paymentDate, paymentStatus, type BankDetails, type ManualPayment } from "../types/payments";

function Submission({ payment, today }: { payment: ManualPayment; today: string }) {
  useLocale();
  const form = useForm<{ paid_on: string; sender_note: string; receipt: File | null }>({ paid_on: today, sender_note: "", receipt: null });
  const picker = useRef<HTMLInputElement>(null);
  const submit = (event: FormEvent) => {
    event.preventDefault();
    form.post(`/payments/${payment.id}/submit`, { forceFormData: true, preserveScroll: true,
      onSuccess: () => { form.reset(); if (picker.current) picker.current.value = ""; } });
  };
  return <form onSubmit={submit}>
    <p>{t("Pasi ta kryesh transferin në bankën tënde, dërgo njoftimin këtu. Dëshmia nuk zëvendëson verifikimin bankar.")}</p>
    <div className="form-grid"><label>{t("Data e transferit")}<input required type="date" value={form.data.paid_on} min={payment.earliestPaidOn} max={today} onChange={e => form.setData("paid_on", e.target.value)} /><FieldError message={form.errors.paid_on} /></label>
      <label>{t("Dëshmia private (opsionale)")}<input ref={picker} type="file" accept="application/pdf,image/jpeg,image/png" onChange={e => form.setData("receipt", e.target.files?.[0] ?? null)} /><small>{t("PDF, JPG ose PNG deri në 5MB; imazhet deri në 3000 × 3000px. Vetëm ti dhe administratori mund ta shkarkoni.")}</small><FieldError message={form.errors.receipt} /></label></div>
    <label className="full-label">{t("Shënim (opsional)")}<textarea maxLength={500} value={form.data.sender_note} onChange={e => form.setData("sender_note", e.target.value)} /><FieldError message={form.errors.sender_note} /></label>
    <Button type="submit" disabled={form.processing}>{t("Dërgo për verifikim")}</Button>
  </form>;
}
export default function ManualPayments({ payments, bankTransfer, today }: { payments: ManualPayment[]; bankTransfer: { configured: boolean; bank: BankDetails }; today: string }) {
  useLocale();
  const issue = useForm({});
  const open = payments.find(p => p.status === "draft" || p.status === "pending");
  const bank = open?.bank ?? bankTransfer.bank;
  return <div className="panel min-w-0"><div className="panel-head"><div><h2>{t("Pagesa bankare")}</h2><span>{t("€7 / 30 ditë")}</span></div></div><AuthStatus />
    <p>{t("Vetëm transfertë manuale. Nuk ka debitim automatik. Profili aktivizohet vetëm pas verifikimit të fondeve në bankë nga administratori.")}</p>
    {(bankTransfer.configured || open) ? <div className="form-grid"><div><strong>{t("Përfituesi")}</strong><p>{bank.beneficiary}</p><strong>{t("Banka")}</strong><p>{bank.bankName}</p></div><div className="min-w-0"><strong>{t("IBAN / Llogaria")}</strong><p className="break-all">{bank.account}</p><strong>BIC</strong><p>{bank.bic || "—"}</p></div></div> : <p role="status">{t("Pagesat bankare nuk janë hapur ende. Të dhënat bankare duhet të konfigurohen nga administratori.")}</p>}
    {open ? <><p><strong>{t("Referenca unike:")} <span className="break-all">{open.reference}</span></strong></p><p>{t("Shuma: €7 EUR. Shkruaje referencën saktë në përshkrimin e transferit.")}</p>
      <p role="status">{t(paymentStatus[open.status])}. {open.status === "pending" && t("Nëse prova ka përfunduar, profili mbetet i fshehur deri në miratim.")}</p>
      {open.status === "draft" && (bankTransfer.configured ? <Submission key={open.id} payment={open} today={today} /> : <p>{t("Njoftimet për pagesa janë ndalur derisa të konfigurohet banka.")}</p>)}</> : <Button disabled={!bankTransfer.configured || issue.processing} onClick={() => issue.post("/payments", { preserveScroll: true })}>{t("Krijo referencën e pagesës")}</Button>}
    {payments.length > 0 && <><h3>{t("Historiku i pagesave")}</h3>{payments.map(payment => <div key={payment.id} className="panel min-w-0">
      <strong className="break-all">{payment.reference}</strong><p>€{payment.amount} {payment.currency} · {t(paymentStatus[payment.status])}</p>
      {payment.rejectionReason && <p>{t("Arsyeja:")} {payment.rejectionReason}</p>}
      {payment.periodEndsAt && <p>{t("Periudha:")} {paymentDate(payment.periodStartsAt)} — {paymentDate(payment.periodEndsAt)}</p>}
      {payment.receiptUrl && <a href={payment.receiptUrl}>{t("Shkarko dëshminë private")}</a>}
    </div>)}</>}
  </div>;
}
