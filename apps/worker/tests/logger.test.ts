import { describe, expect, it } from 'vitest';
import { createLogger, errorFields } from '../src/logger.js';

function capture() {
  const lines: Array<Record<string, unknown>> = [];
  const logger = createLogger({ level: 'debug', destination: { write: (chunk: string) => lines.push(JSON.parse(chunk) as Record<string, unknown>) } });
  return { logger, lines };
}

describe('structured logging', () => {
  it('emits JSON with service, level label and ISO time', () => {
    const { logger, lines } = capture();
    logger.info({ jobId: 'x' }, 'hello');
    expect(lines[0]).toMatchObject({ level: 'info', service: 'worker', jobId: 'x', msg: 'hello' });
    expect(String(lines[0]?.time)).toMatch(/^\d{4}-\d{2}-\d{2}T/);
  });

  it('never writes secrets', () => {
    const { logger, lines } = capture();
    logger.info(
      {
        req: { headers: { authorization: 'Basic abc', 'x-signature': 't=1,v1=aa', 'stripe-signature': 'v1=bb' } },
        payload: { password: 'p', token: 't', secret: 's', api_key: 'k' },
      },
      'request',
    );
    const raw = JSON.stringify(lines[0]);
    for (const secret of ['Basic abc', 't=1,v1=aa', 'v1=bb', '"p"', '"t"', '"s"', '"k"']) expect(raw).not.toContain(secret);
    expect(raw).toContain('[redacted]');
  });

  it('serializes errors with message, code and cause', () => {
    const { logger, lines } = capture();
    const error = Object.assign(new Error('boom', { cause: new Error('root') }), { code: 'ECONNREFUSED' });
    logger.error(errorFields(error), 'failed');
    expect(lines[0]?.err).toMatchObject({ type: 'Error', message: expect.stringContaining('boom') as unknown, code: 'ECONNREFUSED' });
    expect(JSON.stringify(lines[0]?.err)).toContain('root');
    expect(errorFields('plain').err.message).toBe('plain');
  });
});
