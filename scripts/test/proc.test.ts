import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:net';
import { commandLine, isPortOpen, matchDevProcesses, parseNetstatListeners, quoteArg } from '../lib/proc.ts';

const NETSTAT = `
Active Connections

  Proto  Local Address          Foreign Address        State           PID
  TCP    0.0.0.0:135            0.0.0.0:0              LISTENING       1100
  TCP    127.0.0.1:8100         0.0.0.0:0              LISTENING       4242
  TCP    127.0.0.1:8100         127.0.0.1:53000        ESTABLISHED     4242
  TCP    127.0.0.1:53000        127.0.0.1:8100         ESTABLISHED     7777
  TCP    0.0.0.0:18100          0.0.0.0:0              LISTENING       5555
  TCP    [::]:8100              [::]:0                 LISTENING       4243
  TCP    [::1]:5173             [::]:0                 LISTENING       9001
  TCP    0.0.0.0:8101           0.0.0.0:0              LISTENING       0
`;

describe('parseNetstatListeners', () => {
  it('returns listening PIDs for IPv4 and IPv6 sockets of the port only', () => {
    assert.deepEqual(parseNetstatListeners(NETSTAT, 8100).sort(), [4242, 4243]);
  });
  it('ignores remote-port matches, longer ports and PID 0', () => {
    assert.deepEqual(parseNetstatListeners(NETSTAT, 53000), []);
    assert.deepEqual(parseNetstatListeners(NETSTAT, 8101), []);
  });
  it('handles IPv6 loopback', () => {
    assert.deepEqual(parseNetstatListeners(NETSTAT, 5173), [9001]);
  });
});

describe('isPortOpen', () => {
  it('detects a listening socket and a closed port', async () => {
    const server = createServer();
    await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
    const address = server.address();
    assert.ok(address && typeof address === 'object');
    assert.equal(await isPortOpen(address.port), true);
    await new Promise<void>((resolve) => server.close(() => resolve()));
    assert.equal(await isPortOpen(address.port, '127.0.0.1', 300), false);
  });
});

describe('quoteArg / commandLine', () => {
  it('leaves plain arguments untouched', () => {
    for (const arg of ['--version', 'artisan', String.raw`C:\php\php.exe`, 'a=b', 'user@host', '50%']) {
      assert.equal(quoteArg(arg, true), arg);
      assert.equal(quoteArg(arg, false), arg);
    }
  });
  it('quotes spaces and quotes for cmd.exe', () => {
    assert.equal(quoteArg('echo PHP_VERSION;', true), '"echo PHP_VERSION;"');
    assert.equal(quoteArg('say "hi"', true), '"say ""hi"""');
  });
  it('quotes for POSIX sh', () => {
    assert.equal(quoteArg("it's here", false), String.raw`'it'\''s here'`);
  });
  it('joins a command line', () => {
    assert.equal(commandLine('docker', ['compose', 'version', '--short']), 'docker compose version --short');
  });
});

describe('matchDevProcesses', () => {
  const root = String.raw`C:\Users\dev\payflow-guard`;
  const processes = [
    { pid: 10, command: String.raw`"node" "C:\Users\dev\payflow-guard\apps\worker\node_modules\.bin\..\tsx\dist\cli.mjs" watch --clear-screen=false src/index.ts` },
    { pid: 11, command: String.raw`C:\Users\dev\payflow-guard\apps\ai\.venv\Scripts\uvicorn.exe app.main:app --port 8102 --reload` },
    { pid: 12, command: String.raw`node C:\Users\dev\payflow-guard\node_modules\concurrently\dist\bin\concurrently.js` },
    { pid: 13, command: String.raw`node C:\Users\dev\payflow-guard\apps\worker\node_modules\vitest\vitest.mjs run` },
    { pid: 14, command: String.raw`"node" "C:\Users\dev\opsbot\apps\worker\node_modules\tsx\dist\cli.mjs" watch src/index.ts` },
    { pid: 15, command: String.raw`node C:\Users\dev\payflow-guard\scripts\tasks.ts dev` },
    { pid: 16, command: String.raw`node C:\Users\dev\payflow-guard\scripts\tasks.ts stop` },
  ];

  it('matches watchers and launchers of this checkout only', () => {
    assert.deepEqual(
      matchDevProcesses(processes, root, 16).map((p) => p.pid),
      [10, 12, 15],
    );
  });

  it('never matches the calling process', () => {
    assert.ok(!matchDevProcesses(processes, root, 15).some((p) => p.pid === 15));
  });

  it('works with POSIX paths', () => {
    const posix = [{ pid: 20, command: '/usr/bin/node /home/dev/payflow-guard/apps/web/node_modules/.bin/vp dev --port 5173' }];
    assert.deepEqual(matchDevProcesses(posix, '/home/dev/payflow-guard/', 1).map((p) => p.pid), [20]);
  });
});
