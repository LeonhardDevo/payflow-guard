import { createHash } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { execFileSync } from 'node:child_process';
import { collectStates } from '../env-sync.ts';
import { planEnvSync } from './env-plan.ts';
import { log } from './log.ts';
import { commandVersion, isPortOpen, isWindows, listenersOn, processName } from './proc.ts';
import { appPresent, type Project } from './project.ts';

interface Check {
  label: string;
  ok: boolean;
  detail: string;
  fix?: string;
  severity?: 'error' | 'warn';
}

const REQUIRED_PHP_EXTENSIONS = ['pdo_pgsql', 'pgsql', 'mbstring', 'openssl', 'curl', 'zip', 'gd', 'fileinfo'];
const RECOMMENDED_PHP_EXTENSIONS = ['redis', 'intl'];

function toolChecks(): Check[] {
  const checks: Check[] = [];
  const tool = (label: string, command: string, args: string[], fix: string, test?: (v: string) => boolean) => {
    const version = commandVersion(command, args);
    const ok = version !== null && (test ? test(version) : true);
    checks.push({ label, ok, detail: version ?? 'not found', fix });
  };

  tool('node ≥ 24', 'node', ['--version'], 'install Node 24 LTS', (v) => Number(/v(\d+)/.exec(v)?.[1]) >= 24);
  tool('npm', 'npm', ['--version'], 'ships with Node');
  tool('php ≥ 8.4', 'php', ['--version'], 'install PHP 8.4', (v) => /^PHP 8\.(?:[4-9]|\d{2})\./.test(v));
  tool('composer', 'composer', ['--version', '--no-ansi'], 'install Composer 2');
  tool('docker', 'docker', ['version', '--format', '{{.Server.Version}}'], 'start Docker Desktop');
  tool('docker compose', 'docker', ['compose', 'version', '--short'], 'update Docker Desktop');
  tool('tesseract', 'tesseract', ['--version'], 'install Tesseract 5 with rus+eng language data');

  try {
    const modules = execFileSync('php', ['-m'], { encoding: 'utf8', windowsHide: true }).toLowerCase().split(/\r?\n/);
    const missing = REQUIRED_PHP_EXTENSIONS.filter((ext) => !modules.includes(ext));
    checks.push({
      label: 'php extensions',
      ok: missing.length === 0,
      detail: missing.length ? `missing: ${missing.join(', ')}` : REQUIRED_PHP_EXTENSIONS.join(', '),
      fix: 'enable them in php.ini',
    });
    const optional = RECOMMENDED_PHP_EXTENSIONS.filter((ext) => !modules.includes(ext));
    if (optional.length) {
      checks.push({
        label: 'php extensions (recommended)',
        ok: false,
        severity: 'warn',
        detail: `missing: ${optional.join(', ')} (the production image has them)`,
        fix: 'optional locally; Intl formatting falls back to the client',
      });
    }
  } catch {}

  try {
    const langs = execFileSync('tesseract', ['--list-langs'], { encoding: 'utf8', windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'] });
    const missing = ['eng', 'rus'].filter((lang) => !new RegExp(`^${lang}$`, 'm').test(langs.replace(/\r/g, '')));
    checks.push({
      label: 'tesseract languages',
      ok: missing.length === 0,
      severity: 'warn',
      detail: missing.length ? `missing: ${missing.join(', ')}` : 'eng, rus',
      fix: 'put rus.traineddata (+ copies of eng/osd) in a user-writable folder and set TESSDATA_PREFIX (README → Troubleshooting)',
    });
  } catch {
    return checks;
  }
  return checks;
}

async function infraChecks(project: Project): Promise<Check[]> {
  const checks: Check[] = [];
  const infra: Array<[string, number]> = [
    ['postgres (pgvector)', project.ports.postgres],
    ['redis 7', project.ports.redis],
    ['mailpit smtp', project.ports.mailSmtp],
    ['mailpit ui', project.ports.mailUi],
  ];
  for (const [label, port] of infra) {
    const open = await isPortOpen(port);
    checks.push({ label: `${label} :${port}`, ok: open, detail: open ? 'reachable' : 'not reachable', fix: 'npm run infra:up' });
  }
  const services: Array<[string, number]> = [
    ['web', project.ports.web],
    ...(project.ports.webInternal ? [['web-internal', project.ports.webInternal] as [string, number]] : []),
    ['vite', project.ports.vite],
    ['worker', project.ports.worker],
  ];
  for (const [label, port] of services) {
    const pids = listenersOn(port);
    checks.push({
      label: `${label} :${port}`,
      ok: true,
      severity: 'warn',
      detail: pids.length ? `in use by ${pids.map((pid) => `${processName(pid)} (${pid})`).join(', ')}` : 'free',
    });
  }
  return checks;
}

function envChecks(project: Project): Check[] {
  const states = collectStates(project);
  const plan = planEnvSync(project, states);
  const pending = plan.create.length > 0 || Object.keys(plan.updates).length > 0;
  const checks: Check[] = [
    {
      label: 'env files',
      ok: !pending,
      detail: pending ? plan.notes.join('; ') : 'present, secrets generated and shared',
      fix: 'npm run env:sync',
    },
  ];
  for (const warning of plan.warnings) checks.push({ label: 'env', ok: false, detail: warning, fix: 'edit the app .env' });

  if (appPresent(project, 'web')) {
    const hot = join(project.appDir('web'), 'public', 'hot');
    if (existsSync(hot) && listenersOn(project.ports.vite).length === 0) {
      checks.push({
        label: 'vite hot file',
        ok: false,
        severity: 'warn',
        detail: 'apps/web/public/hot exists but no Vite dev server is running (pages load assets from a dead server)',
        fix: 'npm run stop (removes it) or delete apps/web/public/hot',
      });
    }
  }

  if (isWindows && appPresent(project, 'web')) {
    const binding = join(project.appDir('web'), 'node_modules', '@voidzero-dev', 'vite-plus-win32-x64-msvc');
    const hasModules = existsSync(join(project.appDir('web'), 'node_modules'));
    if (hasModules) {
      checks.push({
        label: 'vite-plus Windows binding',
        ok: existsSync(binding),
        detail: existsSync(binding) ? 'installed' : 'missing (vp build/dev will fail)',
        fix: 'cd apps/web && npm install --no-save @voidzero-dev/vite-plus-win32-x64-msvc',
      });
    }
  }
  return checks;
}

export function platformDocCheck(root: string): Check | null {
  const local = join(root, 'PLATFORM.md');
  const canonical = join(dirname(root), 'PLATFORM.md');
  if (!existsSync(local) || !existsSync(canonical)) return null;
  const hash = (path: string): string => createHash('sha256').update(readFileSync(path)).digest('hex');
  const same = hash(local) === hash(canonical);
  return {
    label: 'PLATFORM.md in sync',
    ok: same,
    severity: 'warn',
    detail: same ? 'matches ../PLATFORM.md' : 'differs from the canonical ../PLATFORM.md',
    fix: 'edit ../PLATFORM.md and copy it into every product folder',
  };
}

export async function runDoctor(project: Project): Promise<boolean> {
  const platform = platformDocCheck(project.root);
  const sections: Array<[string, Check[]]> = [
    ['Toolchain', toolChecks()],
    ['Infrastructure & ports', await infraChecks(project)],
    ['Configuration', [...envChecks(project), ...(platform ? [platform] : [])]],
  ];
  let failed = 0;
  for (const [title, checks] of sections) {
    log.step(title);
    for (const check of checks) {
      const line = `${check.label.padEnd(30)} ${check.detail}`;
      if (check.ok) log.ok(line);
      else if (check.severity === 'warn') log.warn(`${line}${check.fix ? `  → ${check.fix}` : ''}`);
      else {
        failed++;
        log.error(`${line}${check.fix ? `  → ${check.fix}` : ''}`);
      }
    }
  }
  console.log('');
  if (failed) log.error(`${failed} problem(s) found`);
  else log.ok('all checks passed');
  return failed === 0;
}
