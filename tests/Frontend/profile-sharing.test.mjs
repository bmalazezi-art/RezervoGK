import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import vm from 'node:vm';
import ts from 'typescript';
import { test } from 'node:test';

const root = resolve(import.meta.dirname, '../..');
const require = createRequire(resolve(root, 'package.json'));
const path = resolve(root, 'resources/js/lib/profileSharing.ts');
const module = { exports: {} };
const output = ts.transpileModule(readFileSync(path, 'utf8'), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2020 },
}).outputText;
vm.runInNewContext(output, { module, exports: module.exports, require, encodeURIComponent });
const { profileShareMessage, profileShareTargets, copyTextToClipboard } = module.exports;

test('WhatsApp and Viber share the canonical profile URL with encoded localized text', () => {
  const canonical = 'https://portieri.example/keepers/42';
  const message = profileShareMessage('Ardit Krasniqi | Gjej portier', 'Portier në Prishtinë — profil publik.', canonical);
  const targets = profileShareTargets('Ardit Krasniqi | Gjej portier', 'Portier në Prishtinë — profil publik.', canonical);

  assert.equal(targets.whatsapp, `https://wa.me/?text=${encodeURIComponent(message)}`);
  assert.equal(targets.viber, `viber://forward?text=${encodeURIComponent(message)}`);
  assert.equal(decodeURIComponent(new URL(targets.whatsapp).searchParams.get('text')), message);
  assert.equal(message.split(canonical).length - 1, 1);
  assert.ok(message.includes('Prishtinë'));
});

test('Viber-safe message truncation preserves the canonical URL and Unicode characters', () => {
  const canonical = 'https://portieri.example/keepers/42';
  const message = profileShareMessage('Profile title', '🧤'.repeat(300), canonical);

  assert.ok(Array.from(message).length <= 200);
  assert.ok(message.endsWith(canonical));
  assert.doesNotMatch(message, /[\uD800-\uDBFF]$/);
});

test('clipboard API rejection falls back to selecting and copying the link', async () => {
  let execCommandCalled = false;
  let removed = false;
  let restoredFocus = false;
  const field = {
    value: '', style: {}, setAttribute() {}, focus() {}, select() {}, setSelectionRange() {},
    remove() { removed = true; },
  };
  const doc = {
    body: { appendChild(node) { assert.equal(node, field); } },
    activeElement: { focus() { restoredFocus = true; } },
    createElement(name) { assert.equal(name, 'textarea'); return field; },
    execCommand(command) { assert.equal(command, 'copy'); execCommandCalled = true; return true; },
  };
  const clipboard = { async writeText() { throw new Error('Permission denied'); } };

  assert.equal(await copyTextToClipboard('https://portieri.example/keepers/42', clipboard, doc), true);
  assert.equal(field.value, 'https://portieri.example/keepers/42');
  assert.equal(execCommandCalled, true);
  assert.equal(removed, true);
  assert.equal(restoredFocus, true);
});

test('share controls use dedicated WhatsApp, Viber, Messenger and Instagram brand SVGs', () => {
  const page = readFileSync(resolve(root, 'resources/js/pages/Profile.tsx'), 'utf8');
  for (const brand of ['whatsapp', 'viber', 'messenger', 'instagram']) {
    const svg = readFileSync(resolve(root, `public/icons/share-${brand}.svg`), 'utf8');
    assert.match(svg, /<svg[^>]*viewBox="0 0 24 24"/);
    assert.match(svg, /<path d="/);
    assert.match(page, new RegExp(`/icons/share-${brand}\\.svg`));
  }
});

test('Messenger and Instagram open the native share sheet where supported and keep desktop fallbacks', () => {
  const page = readFileSync(resolve(root, 'resources/js/pages/Profile.tsx'), 'utf8');

  assert.match(page, /navigator\.share\(\{ title: shareTitle, text:/);
  assert.match(page, /t\("Më shumë opsione"\)/);
  assert.match(page, /const openMessengerFallback = \(\) =>/);
  assert.match(page, /const openInstagram = \(\) =>/);
  assert.equal((page.match(/if \(supportsNativeShare\(\)\) \{ void shareProfile\(\); return; \}/g) ?? []).length, 2);
  assert.match(page, /void copyLink\(t\("Linku u kopjua\. Ngjite në një bisedë në Messenger\."\)/);
  assert.match(page, /void copyLink\(t\("Linku u kopjua\. Instagram u hap; ngjite në bio ose mesazh\."\)/);
  assert.match(page, /href=\{shareTargets\.viber\}/);
  assert.match(page, /href=\{shareTargets\.whatsapp\}/);
});
