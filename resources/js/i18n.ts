import { useSyncExternalStore } from 'react';
import englishMessages from '../../lang/en.json';
const english: Record<string, string> = englishMessages;

export type Locale = 'sq' | 'en';
export type MessageKey = string;
type Values = Record<string, string | number | null | undefined>;
let current: Locale = 'sq';
const listeners = new Set<() => void>();
export const getLocale = () => current;
export const localeTag = () => (current === 'en' ? 'en-GB' : 'sq-AL');

export function setLocale(locale: Locale) {
    current = locale;
    if (typeof document !== 'undefined') document.documentElement.lang = locale;
    listeners.forEach((listener) => listener());
}

export function useLocale() {
    return useSyncExternalStore(
        (listener) => {
            listeners.add(listener);
            return () => listeners.delete(listener);
        },
        getLocale,
        () => 'sq' as Locale,
    );
}

function interpolate(message: string, values: Values) {
    return message.replace(/:([a-zA-Z][a-zA-Z0-9_]*)/g, (match, key: string) =>
        Object.prototype.hasOwnProperty.call(values, key) ? String(values[key] ?? '') : match,
    );
}

/** Source-language keys make a missing translation readable, never a machine key. */
export function t(key: MessageKey, values: Values = {}) {
    return interpolate(
        current === 'en' ? english[key] || key : key,
        values,
    );
}

const reverse = new Map(Object.entries(english).map(([sq, en]) => [en, sq as MessageKey]));
const escape = (value: string) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const templates = Object.entries(english).flatMap(([sq, en]) =>
    [sq, en]
        .filter((value) => /:[a-zA-Z]/.test(value))
        .map((source) => {
            const names: string[] = [];
            const parts = source.split(/(:[a-zA-Z][a-zA-Z0-9_]*)/g);
            const pattern = parts
                .map((part) => {
                    if (/^:[a-zA-Z]/.test(part)) {
                        names.push(part.slice(1));
                        return ['min', 'max', 'size', 'minutes', 'count'].includes(part.slice(1)) ? '([0-9]+(?:[.,][0-9]+)?)' : '(.+?)';
                    }
                    return escape(part);
                })
                .join('');
            return { key: sq as MessageKey, names, specificity: source.replace(/:[a-zA-Z][a-zA-Z0-9_]*/g, '').length, pattern: new RegExp(`^${pattern}$`, 's') };
        }),
).sort((a, b) => b.specificity - a.specificity);

/** Only for application-owned labels/errors from props or static option tables.
 * Never apply to seller titles/descriptions, messages or other user content.
 * Handles history/poll responses produced before a language change, without reloads.
 */
export function ui(value: string | null | undefined): string {
    if (!value) return '';
    if (Object.prototype.hasOwnProperty.call(english, value)) return t(value as MessageKey);
    const key = reverse.get(value);
    if (key) return t(key);
    for (const template of templates) {
        const match = value.match(template.pattern);
        if (match)
            return t(
                template.key,
                Object.fromEntries(
                    template.names.map((name, i) => [
                        name,
                        ['attribute', 'other'].includes(name) ? ui(match[i + 1]) : match[i + 1],
                    ]),
                ),
            );
    }
    return value;
}
