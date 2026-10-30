import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import vm from 'node:vm';
import ts from 'typescript';
import { test } from 'node:test';

const root = resolve(import.meta.dirname, '../..');
const nativeRequire = createRequire(resolve(root, 'package.json'));
const modules = new Map();
function load(file) {
  const path = resolve(root, file);
  if (modules.has(path)) return modules.get(path).exports;
  const module = { exports: {} }; modules.set(path, module);
  const require = name => name.startsWith('.') ? load(resolve(dirname(path), `${name}.ts`)) : nativeRequire(name);
  const output = ts.transpileModule(readFileSync(path, 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2020 } }).outputText;
  vm.runInNewContext(output, { module, exports: module.exports, require, Date });
  return module.exports;
}

const { calendarDates, countAvailableDates, endsAfterMidnight, resolveAvailability } = load('resources/js/lib/availability.ts');
const schedule = [
  { weekday: 1, start: '18:00', end: '23:00' },
  { weekday: 2, start: '20:00', end: '23:00' },
  { weekday: 4, start: '18:00', end: '23:00' },
  { weekday: 5, start: '18:00', end: '23:00' },
  { weekday: 6, start: '18:00', end: '23:00' },
  { weekday: 7, start: '18:00', end: '23:00' },
];

test('calendar resolves usual hours, day-off, extra date and ignores stale exceptions', () => {
  const extra = ['2026-11-04', '2026-10-31'];
  const off = ['2026-10-31', '2026-10-28'];
  assert.equal(JSON.stringify(resolveAvailability('2026-10-27', schedule, extra, off)), JSON.stringify({ kind: 'usual', available: true, start: '20:00', end: '23:00' }));
  assert.equal(JSON.stringify(resolveAvailability('2026-10-31', schedule, extra, off)), JSON.stringify({ kind: 'day_off', available: false, start: null, end: null }));
  assert.equal(JSON.stringify(resolveAvailability('2026-11-04', schedule, extra, off)), JSON.stringify({ kind: 'extra', available: true, start: '18:00', end: '23:00' }));
  assert.equal(resolveAvailability('2026-10-28', schedule, extra, off).kind, 'off');
});

test('30-day count includes exceptions and excludes stale dates', () => {
  assert.equal(countAvailableDates('2026-10-27', schedule, ['2026-11-04', '2026-10-31'], ['2026-10-31', '2026-10-28']), 25);
});

test('calendar starts today with Monday-first leading blanks', () => {
  const { dates, leadingBlanks } = calendarDates('2026-10-27');
  assert.equal(leadingBlanks, 1);
  assert.equal(dates.length, 30);
  assert.equal(dates[0], '2026-10-27');
  assert.equal(dates.at(-1), '2026-11-25');
});

test('overnight hint excludes midnight and marks earlier or equal end times', () => {
  assert.equal(endsAfterMidnight('20:00', '02:00'), true);
  assert.equal(endsAfterMidnight('18:00', '00:00'), false);
  assert.equal(endsAfterMidnight('18:00', '18:00'), true);
});
