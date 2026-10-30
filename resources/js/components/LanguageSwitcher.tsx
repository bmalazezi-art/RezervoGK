import { useRef, useState } from 'react';
import { getLocale, setLocale, t, useLocale, type Locale } from '../i18n';

export default function LanguageSwitcher({ className = '' }: { className?: string }) {
    const locale = useLocale();
    const [busy, setBusy] = useState(false);
    const [failed, setFailed] = useState(false);
    const pending = useRef(false);
    async function change(next: Locale) {
        if (next === getLocale() || pending.current) return;
        pending.current = true;
        setBusy(true);
        setFailed(false);
        try {
            const xsrf = document.cookie
                .split('; ')
                .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
                ?.slice('XSRF-TOKEN='.length);
            const response = await fetch('/locale', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent(xsrf ?? ''),
                },
                body: JSON.stringify({ locale: next }),
            });
            if (!response.ok || response.redirected || (await response.json()).locale !== next)
                throw new Error('Locale preference was not saved.');
            // No navigation, remount or Inertia prop replacement: drafts and files stay intact.
            setLocale(next);
        } catch {
            setFailed(true);
        } finally {
            pending.current = false;
            setBusy(false);
        }
    }
    return (
        <div className={`language-control ${className}`}>
            <div
                className="lang-switch"
                role="group"
                aria-label={t('Gjuha e ndërfaqes')}
                aria-busy={busy}
            >
                {(['sq', 'en'] as const).map((item) => (
                    <button
                        key={item}
                        type="button"
                        lang={item}
                        aria-label={item === 'sq' ? 'Shqip' : 'English'}
                        aria-pressed={locale === item}
                        disabled={busy}
                        onClick={() => void change(item)}
                    >
                        {item.toUpperCase()}
                    </button>
                ))}
            </div>
            {failed && (
                <span className="language-error" role="alert">
                    {t('Gjuha nuk u ndryshua. Provo përsëri.')}
                </span>
            )}
        </div>
    );
}
