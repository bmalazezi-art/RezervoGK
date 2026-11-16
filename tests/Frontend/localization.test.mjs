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
test('FAQ and Contact public copy is complete, translated and free of draft notes', () => {
  const information = readFileSync(resolve(root, 'resources/js/pages/Information.tsx'), 'utf8');
  const faq = information.slice(information.indexOf('  faq: {'), information.indexOf('  contact: {'));
  const contact = information.slice(information.indexOf('  contact: {'), information.indexOf('  privacy: {'));
  const visibleCopy = `${faq}\n${contact}`;
  for (const internal of ['draft: true', 'review:', 'DRAFT FOR REVIEW', 'PROJEKT PËR RISHIKIM', 'owner review', 'postal address']) {
    assert.ok(!visibleCopy.toLowerCase().includes(internal.toLowerCase()), `Public FAQ/Contact contains internal note: ${internal}`);
  }
  setLocale('en');
  assert.equal(t('Pyetje të shpeshta'), 'Frequently Asked Questions');
  assert.equal(t('A ka moshë minimale për t\'u regjistruar?'), 'Is there a minimum age to register?');
  assert.match(t('Për të krijuar llogari portieri, duhet të konfirmosh se je të paktën 18 vjeç duke shënuar kutinë e pranimit gjatë regjistrimit. Platforma nuk kërkon datëlindjen dhe nuk e verifikon moshën në mënyrë të pavarur.'), /confirm that you are at least 18/);
  assert.match(t('Në fund të një profili publik të portierit, mund të dërgosh raport anonim duke zgjedhur arsyen dhe, nëse dëshiron, duke shtuar detaje. Raporti hyn në radhën e shqyrtimit të administratorit. Dërgimi i raportit nuk e pezullon automatikisht profilin.'), /anonymous report.*review queue.*does not automatically suspend/i);
  assert.match(t('Për pyetje rreth platformës ose privatësisë, kontakto me email. Për një ndeshje, kontakto portierin drejtpërdrejt nga profili i tij publik.'), /contact us by email.*contact the goalkeeper directly/i);
  setLocale('sq');
});
test('all public information page copy and metadata have English translations', () => {
  const information = readFileSync(resolve(root, 'resources/js/pages/Information.tsx'), 'utf8');
  const source = ts.createSourceFile('Information.tsx', information, ts.ScriptTarget.Latest, true, ts.ScriptKind.TSX);
  const documents = source.statements.find(node => ts.isVariableStatement(node)
    && node.declarationList.declarations.some(declaration => declaration.name.getText(source) === 'documents'));
  assert.ok(documents, 'Information document catalog exists');
  function visit(node) {
    if (ts.isStringLiteral(node) && node.text !== 'bmalazezi@gmail.com') {
      assert.ok(en[node.text], `Information page copy is missing an EN translation: ${node.text}`);
      assert.ok(!/rezervo\s*gk|rezervogk/i.test(node.text), `Old brand appears in public copy: ${node.text}`);
    }
    ts.forEachChild(node, visit);
  }
  visit(documents);
  assert.ok(en['Gjej portier, kontakto drejtpërdrejt me WhatsApp ose telefon për ndeshjen tënde.'], 'Shared page description has an EN translation');
  assert.ok(information.includes('head-key="description"'), 'Information metadata follows translated document copy');
});
test('match-payment and tax wording switch to the requested SQ and EN copy', () => {
  const paymentSq = 'Organizatori dhe portieri merren vesh drejtpërdrejt për mënyrën e pagesës dhe shumën. Gjej portier nuk mbledh, proceson apo transferon pagesat për ndeshje.';
  const paymentEn = 'The organiser and goalkeeper agree directly on the payment method and amount. Gjej portier does not collect, process or transfer match payments.';
  const taxSq = 'Secili portier është përgjegjës për respektimin e detyrimeve tatimore, të regjistrimit dhe detyrimeve të tjera ligjore që zbatohen për aktivitetin dhe të ardhurat e tij. Gjej portier nuk llogarit, mbledh apo paguan tatime në emër të përdoruesve.';
  const taxEn = 'Each goalkeeper is responsible for complying with applicable tax, registration and other legal obligations arising from their own activities and income. Gjej portier does not calculate, collect or pay taxes on behalf of users.';
  setLocale('en');
  assert.equal(t(paymentSq), paymentEn);
  assert.equal(t(taxSq), taxEn);
  setLocale('sq');
  assert.equal(t(paymentSq), paymentSq);
  assert.equal(t(taxSq), taxSq);
  for (const file of ['resources/js/pages/Information.tsx', 'resources/js/pages/Home.tsx', 'resources/js/pages/Profile.tsx', 'resources/js/components/KeeperAuthShell.tsx', 'resources/js/pages/Pricing.tsx']) {
    const text = readFileSync(resolve(root, file), 'utf8');
    assert.doesNotMatch(text, /paguaj në (?:dorë|fushë)|pagesa(?:t)?[^\"\n]{0,100}në dorë|pay cash|cash payment|pay at the pitch/i, `${file} contains mandatory-cash match wording`);
  }
  setLocale('sq');
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
