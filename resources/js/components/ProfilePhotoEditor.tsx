import { t, ui, useLocale } from "../i18n";
import { router, useForm } from "@inertiajs/react";
import { useEffect, useRef, useState, type FormEvent } from "react";
import { Button, Icon } from "./marketplace";
import { FieldError } from "./KeeperAuthShell";

type Props = { keeperId: number; name: string; photo: string };

export default function ProfilePhotoEditor({ keeperId, name, photo }: Props) {
  useLocale();
  const form = useForm<{ photo: File | null; remove_photo: boolean }>({ photo: null, remove_photo: false });
  const input = useRef<HTMLInputElement>(null);
  const saving = useRef(false);
  const [preview, setPreview] = useState<string | null>(null);
  const [status, setStatus] = useState("");
  const [requestError, setRequestError] = useState("");
  const [imageError, setImageError] = useState(false);
  useEffect(() => {
    if (!form.data.photo) { setPreview(null); return; }
    const url = URL.createObjectURL(form.data.photo);
    setPreview(url);
    return () => URL.revokeObjectURL(url);
  }, [form.data.photo]);
  const currentPhoto = form.data.remove_photo ? "/keeper-placeholder.svg" : preview ?? photo;
  useEffect(() => setImageError(false), [currentPhoto]);
  useEffect(() => {
    const stopException = router.on("exception", event => {
      if (!saving.current) return;
      event.preventDefault();
      form.cancel();
      setRequestError("Lidhja u ndërpre. Fotografia mund të mos jetë ruajtur; rifresko për ta kontrolluar dhe provo përsëri.");
    });
    const stopInvalid = router.on("invalid", event => {
      if (!saving.current) return;
      event.preventDefault();
      form.cancel();
      setRequestError(event.detail.response.status === 419
        ? "Sesioni ka skaduar. Rifresko faqen dhe provo përsëri."
        : "Ruajtja nuk u konfirmua. Rifresko për ta kontrolluar fotografinë dhe provo përsëri.");
    });
    return () => { stopException(); stopInvalid(); };
  }, []);
  const choose = (file: File | null) => {
    form.clearErrors(); setStatus(""); setRequestError("");
    form.setData({ photo: null, remove_photo: false });
    if (!file) return;
    if (!["image/jpeg", "image/png"].includes(file.type) || file.size > 5 * 1024 * 1024) {
      form.setError("photo", "Zgjidh JPG ose PNG deri në 5MB.");
      if (input.current) input.current.value = "";
      return;
    }
    form.setData({ photo: file, remove_photo: false });
  };
  const submit = (event: FormEvent) => {
    event.preventDefault(); setStatus(""); setRequestError("");
    form.post(`/keepers/${keeperId}/photo`, {
      forceFormData: true, preserveScroll: true,
      onStart: () => { saving.current = true; },
      onSuccess: () => {
        setStatus(form.data.remove_photo ? "Fotografia u hoq." : "Fotografia u ruajt.");
        form.reset(); if (input.current) input.current.value = "";
      },
      onCancel: () => setRequestError("Ruajtja u ndërpre. Provo përsëri."),
      onFinish: () => { saving.current = false; },
    });
  };
  return <form className="panel photo-editor" aria-label={t("Fotografia e profilit")} aria-busy={form.processing} onSubmit={submit}>
    <div className="panel-head"><div><Icon name="upload" /><h2>{t("Fotografia e profilit")}</h2></div></div>
    <div className="photo-editor-body">
      <div className="photo-editor-preview"><img src={imageError ? "/keeper-placeholder.svg" : currentPhoto} alt={t("Fotografia e :name", { name })} onError={() => setImageError(true)} />{preview && <span>{t("Parapamje · ende e paruajtur")}</span>}</div>
      <div className="photo-editor-controls"><p>{t("Fotografia shfaqet në profilin publik dhe kartat e kërkimit kur profili është aktiv.")}</p>
        <p className="editor-hint">{t("JPG ose PNG, deri në 5MB dhe 3000 × 3000px.")}</p>
        <input ref={input} id={`photo-${keeperId}`} hidden type="file" accept="image/jpeg,image/png" aria-label={t("Zgjidh fotografinë")} disabled={form.processing} onChange={event => choose(event.target.files?.[0] ?? null)} />
        <div className="photo-editor-actions"><Button variant="outline" disabled={form.processing} onClick={() => input.current?.click()}><Icon name="upload" size={17} /> {t("Zgjidh fotografinë")}</Button>
          <Button type="submit" disabled={form.processing || (!form.data.photo && !form.data.remove_photo)}>{form.processing ? t("Duke ruajtur...") : form.data.remove_photo ? t("Ruaj heqjen") : t("Ruaj fotografinë")}</Button></div>
        {form.data.photo && <p className="photo-filename">{form.data.photo.name}</p>}
        <label className="photo-remove"><input type="checkbox" disabled={form.processing} checked={form.data.remove_photo} onChange={event => { form.clearErrors(); setStatus(""); setRequestError(""); form.setData({ photo: null, remove_photo: event.target.checked }); if (input.current) input.current.value = ""; }} /> {t("Hiq fotografinë aktuale")}</label>
        {form.processing && <p className="editor-feedback" role="status">{t("Duke ruajtur fotografinë")}{form.progress?.percentage != null ? ` · ${form.progress.percentage}%` : "..."}</p>}
        {status && <p className="editor-feedback editor-success" role="status">{ui(status)}</p>}
        <FieldError message={form.errors.photo ?? form.errors.remove_photo} />
        {requestError && <p className="editor-feedback editor-error" role="alert">{ui(requestError)}</p>}
        {imageError && <p className="editor-feedback editor-error" role="alert">{t("Fotografia nuk mund të shfaqet. Zgjidh një fotografi të re ose kontrollo ruajtjen publike.")}</p>}
      </div>
    </div>
  </form>;
}
