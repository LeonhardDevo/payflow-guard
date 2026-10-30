import { existsSync } from 'node:fs';
import { join } from 'node:path';
import { appPresent, npmScripts, type AppName, type Project } from './project.ts';

export type TaskName = 'dev' | 'test' | 'lint' | 'build';
export type JobName = 'web' | 'web-internal' | 'web-ui' | 'vite' | 'scheduler' | 'worker' | 'e2e' | 'scripts';

export const JOB_NAMES: readonly JobName[] = ['web', 'web-internal', 'web-ui', 'vite', 'scheduler', 'worker', 'e2e', 'scripts'];

export interface Job {
  name: JobName;
  command: string;
  cwd: string;
  env?: Record<string, string>;
  color: string;
}

export interface Skipped {
  name: JobName;
  reason: string;
}

export interface JobOptions {
  fix?: boolean;
  scheduler?: boolean;
}

export interface Workspace {
  project: Project;
  present: Record<AppName, boolean>;
  installed: Record<AppName | 'e2e' | 'root', boolean>;
  npmScripts: Record<'web' | 'worker' | 'e2e', Set<string>>;
  files: {
    phpstanConfig: boolean;
    phpstanBin: boolean;
    pintBin: boolean;
  };
}

export function inspectWorkspace(project: Project): Workspace {
  const web = project.appDir('web');
  const worker = project.appDir('worker');
  const e2e = join(project.root, 'e2e');
  return {
    project,
    present: { web: appPresent(project, 'web'), worker: appPresent(project, 'worker') },
    installed: {
      web: existsSync(join(web, 'vendor', 'autoload.php')) && existsSync(join(web, 'node_modules')),
      worker: existsSync(join(worker, 'node_modules')),
      e2e: existsSync(join(e2e, 'node_modules', '@playwright', 'test')),
      root: existsSync(join(project.root, 'node_modules', 'concurrently')),
    },
    npmScripts: { web: npmScripts(web), worker: npmScripts(worker), e2e: npmScripts(e2e) },
    files: {
      phpstanConfig: existsSync(join(web, 'phpstan.neon')) || existsSync(join(web, 'phpstan.neon.dist')),
      phpstanBin: existsSync(join(web, 'vendor', 'bin', 'phpstan')),
      pintBin: existsSync(join(web, 'vendor', 'bin', 'pint')),
    },
  };
}

const COLORS: Record<JobName, string> = {
  web: 'green',
  'web-internal': 'green',
  'web-ui': 'greenBright',
  vite: 'magenta',
  scheduler: 'gray',
  worker: 'cyan',
  e2e: 'blue',
  scripts: 'white',
};

const chain = (...steps: Array<string | false | undefined>): string => steps.filter(Boolean).join(' && ');

export function buildJobs(task: TaskName, ws: Workspace, options: JobOptions = {}): { jobs: Job[]; skipped: Skipped[] } {
  const { project } = ws;
  const jobs: Job[] = [];
  const skipped: Skipped[] = [];
  const add = (name: JobName, cwd: string, command: string, env?: Record<string, string>) =>
    jobs.push({ name, cwd, command, color: COLORS[name], ...(env ? { env } : {}) });
  const skip = (name: JobName, reason: string) => skipped.push({ name, reason });

  const web = project.appDir('web');
  const worker = project.appDir('worker');
  const e2e = join(project.root, 'e2e');
  const webScripts = ws.npmScripts.web;
  const workerScripts = ws.npmScripts.worker;

  const appReady = (app: AppName, name: JobName = app): boolean => {
    if (!ws.present[app]) {
      skip(name, `apps/${app} is not present yet`);
      return false;
    }
    if (!ws.installed[app]) {
      skip(name, `apps/${app} dependencies are not installed — run \`npm run setup\``);
      return false;
    }
    return true;
  };

  switch (task) {
    case 'dev': {
      if (appReady('web')) {
        add('web', web, `php artisan serve --host=127.0.0.1 --port=${project.ports.web}`);
        if (project.ports.webInternal) add('web-internal', web, `php artisan serve --host=127.0.0.1 --port=${project.ports.webInternal}`);
        if (webScripts.has('dev')) add('vite', web, `npm run dev -- --host 127.0.0.1 --port ${project.ports.vite} --strictPort`);
        else skip('vite', 'apps/web has no "dev" npm script');
        if (options.scheduler) add('scheduler', web, 'php artisan schedule:work');
      }
      if (appReady('worker')) {
        const internal = ws.present.web && ws.installed.web && project.ports.webInternal ? { WEB_URL: `http://127.0.0.1:${project.ports.webInternal}` } : undefined;
        if (workerScripts.has('dev')) add('worker', worker, 'npm run dev', internal);
        else skip('worker', 'apps/worker has no "dev" npm script');
      }
      break;
    }
    case 'test': {
      if (appReady('web')) {
        add('web', web, 'php artisan test');
        if (webScripts.has('test')) add('web-ui', web, 'npm test');
        else skip('web-ui', 'apps/web has no "test" npm script');
      }
      if (appReady('worker')) {
        if (workerScripts.has('test')) add('worker', worker, 'npm test');
        else skip('worker', 'apps/worker has no "test" npm script');
      }
      add('scripts', project.root, 'node --test "scripts/**/*.test.ts"');
      break;
    }
    case 'lint': {
      if (appReady('web')) {
        add(
          'web',
          web,
          options.fix
            ? chain(webScripts.has('check:fix') && 'npm run check:fix', ws.files.pintBin && 'php vendor/bin/pint --parallel')
            : chain(
                webScripts.has('check') && 'npm run check',
                webScripts.has('types:check') && 'npm run types:check',
                ws.files.pintBin && 'php vendor/bin/pint --parallel --test',
                ws.files.phpstanConfig && ws.files.phpstanBin && 'php vendor/bin/phpstan analyse --no-progress --memory-limit=1G',
              ),
        );
      }
      if (appReady('worker')) {
        add(
          'worker',
          worker,
          options.fix
            ? chain(workerScripts.has('lint:fix') && 'npm run lint:fix')
            : chain(workerScripts.has('lint') && 'npm run lint', workerScripts.has('typecheck') && 'npm run typecheck'),
        );
      }
      if (ws.installed.e2e) add('e2e', e2e, 'npm run typecheck');
      else skip('e2e', 'e2e dependencies are not installed — run `npm run e2e:install`');
      add('scripts', project.root, chain('node node_modules/typescript/bin/tsc -p scripts/tsconfig.json', 'node scripts/comments.ts'));
      break;
    }
    case 'build': {
      if (appReady('web')) add('web', web, 'npm run build');
      if (appReady('worker')) {
        if (workerScripts.has('build')) add('worker', worker, 'npm run build');
        else skip('worker', 'apps/worker has no "build" npm script');
      }
      break;
    }
  }

  const runnable = jobs.filter((job) => {
    if (job.command.trim() !== '') return true;
    skip(job.name, `nothing to run for "${task}"`);
    return false;
  });
  return { jobs: runnable, skipped };
}

export function filterJobs(jobs: Job[], only: string | undefined): Job[] {
  if (!only) return jobs;
  const wanted = new Set(only.split(',').map((name) => name.trim()).filter(Boolean));
  const known = new Set<JobName>(JOB_NAMES);
  for (const name of wanted) {
    if (!known.has(name as JobName)) throw new Error(`Unknown job "${name}" (known: ${[...known].join(', ')})`);
  }
  return jobs.filter((job) => wanted.has(job.name));
}
