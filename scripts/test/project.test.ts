import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { join } from 'node:path';
import { loadProject, parseProject, ProjectConfigError } from '../lib/project.ts';

const VALID = `
PRODUCT_NAME="Demo Product"
PRODUCT_SLUG=demo-product
COMPOSE_PROJECT=demo
DB_NAME=demo
DB_USER=app
DB_PASSWORD=app
WEB_PORT=8100
VITE_PORT=5173
WORKER_PORT=8101
PG_PORT=5441
REDIS_PORT=6391
MAIL_SMTP_PORT=1031
MAIL_UI_PORT=8131
`;

describe('parseProject', () => {
  it('parses a complete file', () => {
    const project = parseProject(VALID, '/repo');
    assert.equal(project.name, 'Demo Product');
    assert.equal(project.slug, 'demo-product');
    assert.equal(project.ports.web, 8100);
    assert.equal(project.ports.postgres, 5441);
    assert.equal(project.ports.webInternal, undefined);
    assert.equal(parseProject(`${VALID}WEB_INTERNAL_PORT=8102
`, '/repo').ports.webInternal, 8102);
    assert.throws(() => parseProject(`${VALID}WEB_INTERNAL_PORT=8100
`, '/repo'), ProjectConfigError);
    assert.equal(project.appDir('worker'), join('/repo', 'apps', 'worker'));
  });

  it('reports every missing key', () => {
    assert.throws(() => parseProject('PRODUCT_NAME=x\n', '/repo'), (error: unknown) => {
      assert.ok(error instanceof ProjectConfigError);
      assert.match(error.message, /PRODUCT_SLUG/);
      assert.match(error.message, /MAIL_UI_PORT/);
      return true;
    });
  });

  it('rejects invalid ports, duplicate ports and bad identifiers', () => {
    assert.throws(() => parseProject(VALID.replace('WEB_PORT=8100', 'WEB_PORT=http'), '/repo'), /WEB_PORT must be a TCP port/);
    assert.throws(() => parseProject(VALID.replace('VITE_PORT=5173', 'VITE_PORT=8100'), '/repo'), /both 8100/);
    assert.throws(() => parseProject(VALID.replace('PRODUCT_SLUG=demo-product', 'PRODUCT_SLUG=Demo Product'), '/repo'), /PRODUCT_SLUG/);
    assert.throws(() => parseProject(VALID.replace('DB_NAME=demo', 'DB_NAME=demo-db'), '/repo'), /DB_NAME/);
  });

  it('loads this repository configuration', () => {
    const project = loadProject();
    assert.ok(project.slug.length > 0);
    assert.ok(project.ports.web > 0);
  });
});
