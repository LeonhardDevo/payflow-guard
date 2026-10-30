import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { buildJobs, filterJobs, type Workspace } from '../lib/jobs.ts';
import { parseProject } from '../lib/project.ts';

const project = parseProject(
  [
    'PRODUCT_NAME=Demo',
    'PRODUCT_SLUG=demo',
    'COMPOSE_PROJECT=demo',
    'DB_NAME=demo',
    'DB_USER=app',
    'DB_PASSWORD=app',
    'WEB_PORT=8200',
    'VITE_PORT=5174',
    'WORKER_PORT=8201',
    'PG_PORT=5442',
    'REDIS_PORT=6392',
    'MAIL_SMTP_PORT=1032',
    'MAIL_UI_PORT=8231',
  ].join('\n'),
  '/repo',
);

function workspace(overrides: Partial<Workspace> = {}): Workspace {
  return {
    project,
    present: { web: true, worker: true },
    installed: { web: true, worker: true, e2e: true, root: true },
    npmScripts: {
      web: new Set(['dev', 'build', 'check', 'check:fix', 'types:check', 'test']),
      worker: new Set(['dev', 'build', 'test', 'lint', 'lint:fix', 'typecheck']),
      e2e: new Set(['test', 'typecheck']),
    },
    files: { phpstanConfig: true, phpstanBin: true, pintBin: true },
    ...overrides,
  };
}

const byName = (jobs: ReturnType<typeof buildJobs>['jobs']) => Object.fromEntries(jobs.map((job) => [job.name, job]));

describe('buildJobs', () => {
  it('dev uses the ports from scripts/project.env', () => {
    const jobs = byName(buildJobs('dev', workspace()).jobs);
    assert.equal(jobs.web?.command, 'php artisan serve --host=127.0.0.1 --port=8200');
    assert.equal(jobs.vite?.command, 'npm run dev -- --host 127.0.0.1 --port 5174 --strictPort');
    assert.equal(jobs.worker?.command, 'npm run dev');
    assert.equal(jobs.scheduler, undefined);
    assert.ok(byName(buildJobs('dev', workspace(), { scheduler: true }).jobs).scheduler);
    assert.equal(jobs['web-internal'], undefined);
    assert.equal(jobs.worker?.env, undefined);
  });

  it('dev serves internal calls on a second web server so web and worker never wait on each other', () => {
    const internal = { ...workspace(), project: { ...project, ports: { ...project.ports, webInternal: 8202 } } };
    const jobs = byName(buildJobs('dev', internal).jobs);
    assert.equal(jobs['web-internal']?.command, 'php artisan serve --host=127.0.0.1 --port=8202');
    assert.deepEqual(jobs.worker?.env, { WEB_URL: 'http://127.0.0.1:8202' });
  });

  it('skips apps that are missing or not installed, with a reason', () => {
    const notInstalled = buildJobs('test', workspace({ installed: { web: true, worker: false, e2e: false, root: true } }));
    assert.ok(notInstalled.skipped.find((s) => s.name === 'worker')?.reason.includes('npm run setup'));
    const { jobs, skipped } = buildJobs(
      'test',
      workspace({ present: { web: true, worker: false }, installed: { web: true, worker: false, e2e: false, root: true } }),
    );
    assert.deepEqual(jobs.map((job) => job.name).sort(), ['scripts', 'web', 'web-ui']);
    assert.ok(skipped.find((s) => s.name === 'worker')?.reason.includes('not present'));
  });

  it('test runs pest, vitest (web + worker) and the script tests', () => {
    const jobs = byName(buildJobs('test', workspace()).jobs);
    assert.equal(jobs.web?.command, 'php artisan test');
    assert.equal(jobs['web-ui']?.command, 'npm test');
    assert.equal(jobs['web-ui']?.cwd, project.appDir('web'));
    assert.equal(jobs.worker?.command, 'npm test');
    assert.ok(jobs.scripts?.command.includes('node --test'));
  });

  it('lint chains every available checker and switches to fixers with --fix', () => {
    const lint = byName(buildJobs('lint', workspace()).jobs);
    assert.equal(lint.web?.command, 'npm run check && npm run types:check && php vendor/bin/pint --parallel --test && php vendor/bin/phpstan analyse --no-progress --memory-limit=1G');
    assert.equal(lint.worker?.command, 'npm run lint && npm run typecheck');

    const fix = byName(buildJobs('lint', workspace(), { fix: true }).jobs);
    assert.equal(fix.web?.command, 'npm run check:fix && php vendor/bin/pint --parallel');
  });

  it('drops jobs whose command chain is empty', () => {
    const ws = workspace({ npmScripts: { web: new Set(), worker: new Set(), e2e: new Set() } });
    const { jobs, skipped } = buildJobs('lint', { ...ws, files: { ...ws.files, pintBin: false, phpstanBin: false } });
    assert.equal(jobs.find((job) => job.name === 'web'), undefined);
    assert.ok(skipped.some((s) => s.name === 'web' && s.reason.includes('nothing to run')));
  });
});

describe('filterJobs', () => {
  it('keeps only the requested jobs and rejects unknown names', () => {
    const { jobs } = buildJobs('dev', workspace());
    assert.deepEqual(filterJobs(jobs, 'web,vite').map((job) => job.name), ['web', 'vite']);
    assert.throws(() => filterJobs(jobs, 'wbe'), /Unknown job "wbe"/);
    assert.equal(filterJobs(jobs, undefined).length, jobs.length);
  });
});
