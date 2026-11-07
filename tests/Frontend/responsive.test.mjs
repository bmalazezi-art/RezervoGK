import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { test } from 'node:test';

const root = resolve(import.meta.dirname, '../..');

test('mobile controls and form fields keep usable touch target sizes', () => {
  const css = readFileSync(resolve(root, 'resources/css/app.css'), 'utf8');
  const mobileBlock = [...css.matchAll(/@media \(max-width: 640px\) \{([\s\S]*?)\n\}/g)]
    .map((match) => match[1])
    .find((block) => block.includes('.admin-table-wrap table')) ?? '';

  assert.match(mobileBlock, /button, \[role="button"\], \.btn \{ min-width: 24px; min-height: 44px; \}/);
  assert.match(mobileBlock, /\.menu-btn \{ width: 44px; height: 44px;/);
  assert.match(mobileBlock, /\.lang-switch button \{ min-width: 24px; min-height: 44px; \}/);
  assert.match(mobileBlock, /select, textarea \{ min-height: 44px; \}/);
});
