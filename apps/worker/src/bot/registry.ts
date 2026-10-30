import type { Translator } from '../i18n/index.js';
import type { ActionHandler, BotCommand, BotModule, BotRegistry, NotificationTemplate } from './types.js';

export function createRegistry(modules: readonly BotModule[], translator: Translator): BotRegistry {
  const commands: BotCommand[] = [];
  const actions = new Map<string, ActionHandler>();
  const notifications = new Map<string, NotificationTemplate>();
  const names = new Set<string>();

  for (const module of modules) {
    if (names.has(module.name)) throw new Error(`Duplicate bot module "${module.name}"`);
    names.add(module.name);
    if (module.messages) translator.extend(module.messages);
    for (const command of module.commands ?? []) {
      if (commands.some((existing) => existing.command === command.command)) {
        throw new Error(`Bot command "/${command.command}" registered twice (module "${module.name}")`);
      }
      commands.push(command);
    }
    for (const [type, handler] of Object.entries(module.actions ?? {})) {
      if (actions.has(type)) throw new Error(`Bot action "${type}" registered twice (module "${module.name}")`);
      actions.set(type, handler);
    }
    for (const [name, template] of Object.entries(module.notifications ?? {})) {
      if (notifications.has(name)) throw new Error(`Notification template "${name}" registered twice (module "${module.name}")`);
      notifications.set(name, template);
    }
  }

  return {
    modules,
    commands,
    action: (type) => actions.get(type),
    notification: (name) => notifications.get(name),
  };
}
