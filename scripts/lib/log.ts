import { styleText } from 'node:util';

type Style = Parameters<typeof styleText>[0];

const color = (style: Style, text: string): string =>
  process.env.NO_COLOR || !process.stdout.isTTY ? text : styleText(style, text);

export const log = {
  step(title: string): void {
    console.log(`\n${color(['bold', 'cyan'], '==>')} ${color('bold', title)}`);
  },
  info(message: string): void {
    console.log(`${color('gray', '  ·')} ${message}`);
  },
  ok(message: string): void {
    console.log(`${color('green', '  +')} ${message}`);
  },
  warn(message: string): void {
    console.warn(`${color('yellow', '  !')} ${message}`);
  },
  error(message: string): void {
    console.error(`${color('red', '  x')} ${message}`);
  },
};
