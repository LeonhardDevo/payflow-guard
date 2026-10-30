import { existsSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { parseArgs } from 'node:util';
import { runDoctor } from './lib/doctor.ts';
import { buildJobs, filterJobs, inspectWorkspace, type Job, type TaskName } from './lib/jobs.ts';
import { log } from './lib/log.ts';
import { isPortOpen, isWindows, killTree, listenersOn, listProcesses, matchDevProcesses, processName, run } from './lib/proc.ts';
import { loadProject, type Project } from './lib/project.ts';

const USAGE = 'usage: node scripts/tasks.ts <setup|dev|test|lint|build|stop|doctor> [options]';

async function runJobs(task: TaskName, jobs: Job[], project: Project): Promise<number> {
  if (jobs.length === 0) {
    log.warn(`nothing to run for "${task}"`);
    return 0;
  }
  const { default: concurrently } = await import('concurrently');
  const parallel = task !== 'test';
  const { result } = concurrently(
    jobs.map((job) => ({ name: job.name, command: job.command, cwd: job.cwd, env: job.env, prefixColor: job.color })),
    {
      prefix: '[{name}]',
      padPrefix: true,
      maxProcesses: parallel ? jobs.length : 1,
      killOthersOn: task === 'dev' ? ['failure'] : [],
      successCondition: 'all',
      handleInput: task === 'dev',
      timings: task !== 'dev',
      cwd: project.root,
    },
  );
  try {
    await result;
    return 0;
  } catch {
    return 1;
  }
}

function composeArgs(project: Project): string[] {
  return ['compose', '--env-file', join(project.root, 'scripts', 'project.env')];
}

async function ensureInfra(project: Project): Promise<void> {
  const ports = [project.ports.postgres, project.ports.redis];
  const open = await Promise.all(ports.map((port) => isPortOpen(port)));
  if (open.every(Boolean)) return;
  log.step('Starting infrastructure (docker compose up -d --wait)');
  const code = await run('docker', [...composeArgs(project), 'up', '-d', '--wait', '--wait-timeout', '180'], { cwd: project.root, shell: false });
  if (code !== 0) throw new Error('docker compose up failed — is Docker Desktop running? (`npm run doctor`)');
}

function assertPortsFree(project: Project, jobs: Job[]): void {
  const portOf: Partial<Record<Job['name'], number>> = {
    web: project.ports.web,
    'web-internal': project.ports.webInternal,
    vite: project.ports.vite,
    worker: project.ports.worker,
  };
  const busy = jobs.flatMap((job) => {
    const port = portOf[job.name];
    if (port === undefined) return [];
    const pids = listenersOn(port);
    return pids.length ? [`${job.name} :${port} is used by ${pids.map((pid) => `${processName(pid)} (${pid})`).join(', ')}`] : [];
  });
  if (busy.length) {
    for (const line of busy) log.error(line);
    throw new Error('ports are busy — a previous `npm run dev` may still be running; stop it with `npm run stop`');
  }
}

async function stop(project: Project, keepInfra: boolean): Promise<number> {
  log.step('Stopping dev servers');
  const targets: Array<[string, number]> = [
    ['web', project.ports.web],
    ...(project.ports.webInternal ? [['web-internal', project.ports.webInternal] as [string, number]] : []),
    ['vite', project.ports.vite],
    ['worker', project.ports.worker],
  ];
  for (const [name, port] of targets) {
    const pids = listenersOn(port);
    if (pids.length === 0) {
      log.info(`${name} :${port} not running`);
      continue;
    }
    for (const pid of pids) {
      const label = processName(pid);
      killTree(pid);
      log.ok(`${name} :${port} stopped ${label} (${pid})`);
    }
  }
  for (const orphan of matchDevProcesses(listProcesses(), project.root)) {
    killTree(orphan.pid);
    log.ok(`stopped leftover ${orphan.command.slice(0, 90)}${orphan.command.length > 90 ? '…' : ''} (${orphan.pid})`);
  }
  const hot = join(project.appDir('web'), 'public', 'hot');
  if (existsSync(hot) && listenersOn(project.ports.vite).length === 0) {
    rmSync(hot, { force: true });
    log.ok('removed stale apps/web/public/hot (pages use the built assets again)');
  }
  if (keepInfra) return 0;
  log.step('Stopping infrastructure (docker compose stop)');
  return run('docker', [...composeArgs(project), 'stop'], { cwd: project.root, shell: false });
}

function setup(project: Project, args: string[]): Promise<number> {
  const scripts = join(project.root, 'scripts');
  if (isWindows) {
    return run('powershell.exe', ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', join(scripts, 'setup.ps1'), ...args], {
      cwd: project.root,
      shell: false,
    });
  }
  return run('bash', [join(scripts, 'setup.sh'), ...args], { cwd: project.root, shell: false });
}

async function main(): Promise<number> {
  const [task, ...rest] = process.argv.slice(2);
  if (!task) {
    console.error(USAGE);
    return 2;
  }
  const project = loadProject();

  if (task === 'setup') return setup(project, rest.filter((arg) => arg !== '--'));
  if (task === 'doctor') return (await runDoctor(project)) ? 0 : 1;

  const { values } = parseArgs({
    args: rest,
    options: {
      only: { type: 'string' },
      fix: { type: 'boolean', default: false },
      scheduler: { type: 'boolean', default: false },
      'keep-infra': { type: 'boolean', default: false },
    },
  });

  if (task === 'stop') return stop(project, values['keep-infra'] ?? false);

  if (!['dev', 'test', 'lint', 'build'].includes(task)) {
    console.error(USAGE);
    return 2;
  }
  const name = task as TaskName;
  const workspace = inspectWorkspace(project);
  if (!workspace.installed.root) {
    log.error('root dependencies missing — run `npm install` (or `npm run setup`) first');
    return 1;
  }
  const { jobs: all, skipped } = buildJobs(name, workspace, { fix: values.fix, scheduler: values.scheduler });
  const jobs = filterJobs(all, values.only);
  for (const item of skipped) {
    if (!values.only || values.only.split(',').includes(item.name)) log.warn(`${item.name}: skipped — ${item.reason}`);
  }

  if (name === 'dev' || name === 'test') await ensureInfra(project);
  if (name === 'dev') {
    assertPortsFree(project, jobs);
    log.step(`${project.name} dev servers`);
    log.info(`web     http://localhost:${project.ports.web}`);
    log.info(`worker  http://localhost:${project.ports.worker}/healthz  (Bull Board: /admin/queues)`);
    log.info(`mailpit http://localhost:${project.ports.mailUi}`);
  }
  return runJobs(name, jobs, project);
}

main().then(
  (code) => {
    process.exitCode = code;
  },
  (error: unknown) => {
    log.error(error instanceof Error ? error.message : String(error));
    process.exitCode = 1;
  },
);
