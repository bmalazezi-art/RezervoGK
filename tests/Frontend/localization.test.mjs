import assert from 'node:assert/strict';
import { readFileSync, readdirSync, existsSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import vm from 'node:vm';
import { test } from 'node:test';
import ts from 'typescript';

const root = resolve(import.meta.dirname, '../..');
const nativeRequire = createRequire(resolve(root, 'package.json'));
const document = { documentElement: { lang: 'sq' } };
// Execute the actual TS helpers and catalogs without adding a frontend test framework.
function createLoader(intl = Intl) {
const modules = new Map();
function load(file) {
  const path = resolve(root, file);
  if (modules.has(path)) return modules.get(path).exports;
  const module = { exports: {} }; modules.set(path, module);
  const require = (name) => {
    if (!name.startsWith('.')) return nativeRequire(name);
    let target = resolve(dirname(path), name);
    if (!existsSync(target)) target += '.ts';
    if (target.endsWith('.json')) return JSON.parse(readFileSync(target, 'utf8'));
    return load(target);
  };
  const output = ts.transpileModule(readFileSync(path, 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2020, esModuleInterop: true } }).outputText;
  vm.runInNewContext(output, { module, exports: module.exports, require, document, Intl: intl, Date, console });
  return module.exports;
}
return load;
}
const load = createLoader();
const { t, ui, setLocale, getLocale, localeTag } = load('resources/js/i18n.ts');
const { formatPrice } = load('resources/js/lib/pricing.ts');
const { displayDate, weekdayShort } = load('resources/js/lib/dates.ts');
const { paymentDate } = load('resources/js/types/payments.ts');
const { keeperAvailability, keeperLocation } = load('resources/js/lib/keeperLabels.ts');
const en = JSON.parse(readFileSync(resolve(root, 'lang/en.json')));
const sq = JSON.parse(readFileSync(resolve(root, 'lang/sq.json')));

test('SQ is default and locale updates document language', () => {
  assert.equal(getLocale(), 'sq'); assert.equal(t('Kyçu'), 'Kyçu');
  setLocale('en'); assert.equal(document.documentElement.lang, 'en'); assert.equal(t('Kyçu'), 'Log in');
  setLocale('sq'); assert.equal(localeTag(), 'sq-AL');
});
test('catalogs cover every source key and preserve placeholders', () => {
  for (const [key, english] of Object.entries(en)) {
    assert.equal(sq[key], key, `Missing SQ source: ${key}`);
    assert.ok(english.length, `Empty English: ${key}`);
    const placeholders = (v) => [...v.matchAll(/:[a-zA-Z][a-zA-Z0-9_]*/g)].map(m => m[0]).sort();
    assert.deepEqual(placeholders(key), placeholders(english), `Placeholder mismatch: ${key}`);
    if (/[ëçËÇ]/.test(key)) assert.notEqual(english, key, `Untranslated: ${key}`);
  }
});
test('all explicit UI calls and curated document/option text have translations', () => {
  for (const dir of ['pages', 'components', 'layouts', 'lib', 'types']) {
    for (const file of readdirSync(resolve(root, 'resources/js', dir)).filter(f => /\.tsx?$/.test(f))) {
      const text = readFileSync(resolve(root, 'resources/js', dir, file), 'utf8');
      const source = ts.createSourceFile(file, text, ts.ScriptTarget.Latest, true, file.endsWith('tsx') ? ts.ScriptKind.TSX : ts.ScriptKind.TS);
      function visit(node) {
        if (ts.isCallExpression(node) && node.expression.getText(source) === 't' && node.arguments[0] && ts.isStringLiteral(node.arguments[0])) {
          assert.ok(en[node.arguments[0].text], `${file}: ${node.arguments[0].text}`);
        }
        if (ts.isJsxText(node)) {
          const value = node.getFullText(source).trim();
          assert.ok(!/[ëçËÇ]/.test(value) || ['Prishtinë', 'Tiranë'].includes(value), `${file}: untranslated JSX ${value}`);
        }
        if (ts.isStringLiteral(node) && ['Information.tsx', 'WeeklyHours.tsx', 'AdminPayments.tsx', 'payments.ts'].includes(file) && /[ëçËÇ]/.test(node.text)) {
          assert.ok(en[node.text], `${file}: missing curated text ${node.text}`);
        }
        ts.forEachChild(node, visit);
      }
      visit(source);
    }
  }
});
test('validation and status text switches in place, including interpolated field names', () => {
  setLocale('en');
  assert.equal(ui('Fusha çmimi është e detyrueshme.'), 'The price field is required.');
  assert.equal(ui('Fusha fjalëkalimi duhet të ketë të paktën 12 karaktere.'), 'The password field must contain at least 12 characters.');
  assert.equal(ui('Fotografia u ruajt.'), 'Photo saved.');
  assert.equal(ui('The price field is required.'), 'The price field is required.');
  setLocale('sq');
  assert.equal(ui('The price field is required.'), 'Fusha çmimi është e detyrueshme.');
  assert.equal(ui('Photo saved.'), 'Fotografia u ruajt.');
});
test('price and dates use the selected locale without conversion or mutating canonical amounts', () => {
  for (const language of ['sq', 'en']) {
    setLocale(language);
    const tag = localeTag();
    for (const currency of ['EUR', 'ALL', 'MKD']) {
      for (const amount of ['0.00', '7.00', '1200.75', '10000.00']) {
        const expected = new Intl.NumberFormat(tag, { style: 'currency', currency, currencyDisplay: currency === 'EUR' ? 'symbol' : 'code', minimumFractionDigits: amount.endsWith('.00') ? 0 : 2, maximumFractionDigits: 2 }).format(Number(amount));
        assert.equal(formatPrice(amount, currency), expected);
      }
    }
    assert.equal(displayDate('2026-10-23'), new Intl.DateTimeFormat(tag, { day: '2-digit', month: '2-digit', year: 'numeric' }).format(new Date('2026-10-23T12:00:00')));
    assert.equal(weekdayShort('2026-10-26'), new Intl.DateTimeFormat(tag, { weekday: 'short' }).format(new Date('2026-10-26T12:00:00')).toUpperCase());
    assert.equal(paymentDate('2026-10-24T23:30:00+00:00'), language === 'sq' ? '25.10.2026 01:30' : '25/10/2026 01:30');
  }
});
test('profile names/content are never translated while application labels are localized', () => {
  const keeper = { name: 'Kyçu', city: 'Tiranë, Shqipëri', cityName: 'Tiranë', countryName: 'Shqipëri', availableOn: '2026-10-23', availabilityKind: 'today', bio: 'Mirë se u ktheve', languages: ['Shqip'], priceAmount: '1200.75', currency: 'ALL' };
  const before = JSON.stringify(keeper);
  setLocale('en');
  assert.equal(keeperLocation(keeper), 'Tiranë, Albania');
  assert.equal(keeperAvailability(keeper), 'Available today');
  assert.equal(t('Fotografia e :name', { name: keeper.name }), 'Photo of Kyçu');
  assert.equal(JSON.stringify(keeper), before);
  setLocale('sq'); assert.equal(keeperAvailability(keeper), 'I lirë sot');
});

test('Albanian formatting remains correct when the browser has no SQ Intl data', () => {
  const unsupportedSq = { NumberFormat: function (locale, options) { return new Intl.NumberFormat(locale.startsWith('sq') ? 'en-US' : locale, options); }, DateTimeFormat: function (locale, options) { return new Intl.DateTimeFormat(locale.startsWith('sq') ? 'en-US' : locale, options); } };
  const fallbackLoad = createLoader(unsupportedSq);
  const i18n = fallbackLoad('resources/js/i18n.ts'); i18n.setLocale('sq');
  const pricing = fallbackLoad('resources/js/lib/pricing.ts');
  const dates = fallbackLoad('resources/js/lib/dates.ts');
  const payments = fallbackLoad('resources/js/types/payments.ts');
  assert.equal(pricing.formatPrice('7.00', 'EUR'), '7\u00a0€');
  assert.equal(pricing.formatPrice('1200.75', 'ALL'), '1200,75\u00a0ALL');
  assert.equal(pricing.formatPrice('10000.00', 'MKD'), '10\u00a0000\u00a0MKD');
  assert.equal(dates.displayDate('2026-10-23'), '23.10.2026');
  assert.equal(dates.weekdayShort('2026-10-23'), 'PRE');
  assert.equal(payments.paymentDate('2026-10-24T23:30:00+00:00'), '25.10.2026 01:30');
});
