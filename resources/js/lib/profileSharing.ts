export function profileShareMessage(title: string, description: string, canonicalUrl: string): string {
  const url = canonicalUrl.trim();
  const text = [title, description].filter(Boolean).join("\n").trim();
  const maxTextLength = Math.max(0, 199 - url.length);
  const prefix = Array.from(text).slice(0, maxTextLength).join("").trimEnd();

  return prefix ? `${prefix}\n${url}` : url;
}

export function profileShareTargets(title: string, description: string, canonicalUrl: string) {
  const text = profileShareMessage(title, description, canonicalUrl);
  const encodedText = encodeURIComponent(text);

  return {
    whatsapp: `https://wa.me/?text=${encodedText}`,
    viber: `viber://forward?text=${encodedText}`,
  };
}

export async function copyTextToClipboard(text: string, clipboard?: Pick<Clipboard, "writeText">, doc?: Document): Promise<boolean> {
  const clipboardApi = clipboard ?? (typeof navigator !== "undefined" ? navigator.clipboard : undefined);
  const browserDocument = doc ?? (typeof document !== "undefined" ? document : undefined);

  if (clipboardApi?.writeText) {
    try {
      await clipboardApi.writeText(text);
      return true;
    } catch {
      // Some browsers expose Clipboard API but reject writes; try the legacy path below.
    }
  }

  if (!browserDocument?.body) return false;

  const field = browserDocument.createElement("textarea");
  const previousFocus = browserDocument.activeElement as HTMLElement | null;
  field.value = text;
  field.setAttribute("readonly", "");
  field.style.position = "fixed";
  field.style.left = "-9999px";
  field.style.opacity = "0";
  browserDocument.body.appendChild(field);

  try {
    field.focus();
    field.select();
    field.setSelectionRange(0, field.value.length);
    return browserDocument.execCommand("copy");
  } catch {
    return false;
  } finally {
    field.remove();
    previousFocus?.focus?.();
  }
}
