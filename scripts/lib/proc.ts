import { execFileSync, execSync, spawn, type SpawnOptions } from 'node:child_process';
import { connect } from 'node:net';

export const isWindows = process.platform === 'win32';

export interface RunOptions {
  cwd?: string;
  env?: NodeJS.ProcessEnv;
  shell?: boolean;
}

export function quoteArg(arg: string, windows: boolean = isWindows): string {
  if (/^[\w@%+=:,./\\-]+$/.test(arg)) return arg;
  return windows ? `"${arg.replace(/"/g, '""')}"` : `'${arg.replace(/'/g, `'\\''`)}'`;
}

export function commandLine(command: string, args: readonly string[]): string {
  return [command, ...args.map((arg) => quoteArg(arg))].join(' ');
}

export function run(command: string, args: readonly string[], options: RunOptions = {}): Promise<number> {
  return new Promise((resolve, reject) => {
    const shell = options.shell ?? isWindows;
    const spawnOptions: SpawnOptions = {
      cwd: options.cwd,
      env: options.env ?? process.env,
      stdio: 'inherit',
      shell,
      windowsHide: true,
    };
    const child = shell ? spawn(commandLine(command, args), spawnOptions) : spawn(command, [...args], spawnOptions);
    child.once('error', reject);
    child.once('exit', (code, signal) => resolve(code ?? (signal ? 1 : 0)));
  });
}

export function isPortOpen(port: number, host = '127.0.0.1', timeoutMs = 750): Promise<boolean> {
  return new Promise((resolve) => {
    const socket = connect({ port, host });
    const done = (open: boolean) => {
      socket.removeAllListeners();
      socket.destroy();
      resolve(open);
    };
    socket.setTimeout(timeoutMs, () => done(false));
    socket.once('connect', () => done(true));
    socket.once('error', () => done(false));
  });
}

export function parseNetstatListeners(output: string, port: number): number[] {
  const pids = new Set<number>();
  for (const line of output.split(/\r?\n/)) {
    const cols = line.trim().split(/\s+/);
    if (cols.length < 5 || !/^TCP/i.test(cols[0]!)) continue;
    const local = cols[1]!;
    const state = cols[3]!;
    const pid = Number(cols[4]);
    if (!/^LISTEN/i.test(state)) continue;
    if (!local.endsWith(`:${port}`)) continue;
    if (Number.isInteger(pid) && pid > 0) pids.add(pid);
  }
  return [...pids];
}

export function listenersOn(port: number): number[] {
  try {
    if (isWindows) {
      const output = execFileSync('netstat', ['-ano', '-p', 'tcp'], { encoding: 'utf8', windowsHide: true });
      const v6 = execFileSync('netstat', ['-ano', '-p', 'tcpv6'], { encoding: 'utf8', windowsHide: true });
      return [...new Set([...parseNetstatListeners(output, port), ...parseNetstatListeners(v6, port)])];
    }
    const output = execFileSync('lsof', ['-nP', `-iTCP:${port}`, '-sTCP:LISTEN', '-t'], { encoding: 'utf8' });
    return output
      .split(/\s+/)
      .map(Number)
      .filter((pid) => Number.isInteger(pid) && pid > 0);
  } catch {
    return [];
  }
}

export function processName(pid: number): string {
  try {
    if (isWindows) {
      const out = execFileSync('tasklist', ['/FI', `PID eq ${pid}`, '/FO', 'CSV', '/NH'], { encoding: 'utf8', windowsHide: true });
      return /^"([^"]+)"/.exec(out.trim())?.[1] ?? `pid ${pid}`;
    }
    return execFileSync('ps', ['-p', String(pid), '-o', 'comm='], { encoding: 'utf8' }).trim() || `pid ${pid}`;
  } catch {
    return `pid ${pid}`;
  }
}

export interface ProcessInfo {
  pid: number;
  command: string;
}

const DEV_LAUNCHERS = [
  /\btsx\b.*\bwatch\b/i,
  /\bvp(?:\.cmd|\.js)?["']?\s+dev\b/i,
  /\bvite\b.*\bdev\b/i,
  /\bartisan["']?\s+(?:serve|schedule:work|queue:work)\b/i,
  /\bconcurrently\b/i,
  /scripts[\\/]tasks\.ts["']?\s+dev\b/i,
];

export function matchDevProcesses(processes: readonly ProcessInfo[], root: string, selfPid: number = process.pid): ProcessInfo[] {
  const normalize = (value: string) => value.replace(/\\/g, '/').toLowerCase();
  const needle = `${normalize(root).replace(/\/+$/, '')}/`;
  return processes.filter(
    (info) =>
      info.pid !== selfPid &&
      normalize(info.command).includes(needle) &&
      DEV_LAUNCHERS.some((pattern) => pattern.test(info.command)),
  );
}

export function listProcesses(): ProcessInfo[] {
  try {
    if (isWindows) {
      const json = execFileSync(
        'powershell.exe',
        ['-NoProfile', '-Command', 'Get-CimInstance Win32_Process | Select-Object ProcessId, CommandLine | ConvertTo-Json -Compress'],
        { encoding: 'utf8', windowsHide: true, maxBuffer: 64 * 1024 * 1024 },
      );
      const rows = JSON.parse(json) as Array<{ ProcessId: number; CommandLine: string | null }>;
      return rows.filter((row) => row.CommandLine).map((row) => ({ pid: row.ProcessId, command: row.CommandLine! }));
    }
    return execFileSync('ps', ['-eo', 'pid=,args='], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 })
      .split('\n')
      .map((line) => /^\s*(\d+)\s+(.*)$/.exec(line))
      .filter((match): match is RegExpExecArray => match !== null)
      .map((match) => ({ pid: Number(match[1]), command: match[2]! }));
  } catch {
    return [];
  }
}

export function killTree(pid: number): void {
  try {
    if (isWindows) {
      execFileSync('taskkill', ['/PID', String(pid), '/T', '/F'], { stdio: 'ignore', windowsHide: true });
      return;
    }
    try {
      process.kill(-pid, 'SIGTERM');
    } catch {
      process.kill(pid, 'SIGTERM');
    }
  } catch {
    return;
  }
}

export function commandExists(command: string): boolean {
  try {
    execFileSync(isWindows ? 'where' : 'which', [command], { stdio: 'ignore', windowsHide: true });
    return true;
  } catch {
    return false;
  }
}

export function commandVersion(command: string, args: readonly string[] = ['--version']): string | null {
  try {
    const options = { encoding: 'utf8' as const, stdio: ['ignore', 'pipe', 'pipe'] as ['ignore', 'pipe', 'pipe'], windowsHide: true };
    const out = isWindows ? execSync(commandLine(command, args), options) : execFileSync(command, [...args], options);
    // eslint-disable-next-line no-control-regex
    return out.replace(/\u001b\[[0-9;]*m/g, '').split(/\r?\n/).find((line) => line.trim() !== '')?.trim() ?? null;
  } catch {
    return null;
  }
}
