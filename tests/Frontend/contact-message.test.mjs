import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import vm from 'node:vm';
import { test } from 'node:test';
import ts from 'typescript';

const root = resolve(import.meta.dirname, '../..');
const nativeRequire = createRequire(resolve(root, 'package.json'));
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
  vm.runInNewContext(output, { module, exports: module.exports, require, document: { documentElement: { lang: 'sq' } }, Intl, Date, console });
  return module.exports;
}
const { whatsappMessage } = load('resources/js/lib/contactMessage.ts');
const { setLocale } = load('resources/js/i18n.ts');

test('WhatsApp message follows the searched date', () => {
  setLocale('sq');
  assert.match(whatsappMessage('Ardit', null, '2026-11-16'), /A je i lirë për një ndeshje/);
  assert.match(whatsappMessage('Ardit', '2026-11-16', '2026-11-16'), /i lirë sot/);
  assert.match(whatsappMessage('Ardit', '2026-11-17', '2026-11-16'), /i lirë nesër/);
  assert.match(whatsappMessage('Ardit', '2026-11-30', '2026-11-16'), /i lirë më 30\.11\.2026/);
  assert.match(whatsappMessage('Ardit', '2026-12-01', '2026-11-30'), /i lirë nesër/);
  setLocale('en');
  assert.match(whatsappMessage('Ardit', '2026-11-30', '2026-11-16'), /free on 30\/11\/2026/);
  setLocale('sq');
});
