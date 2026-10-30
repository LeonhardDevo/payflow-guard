import type {
    BillingInterval,
    Plan,
    PlanKey,
    Subscription,
    SubscriptionStatus,
    UsageEntry,
} from '@/types/models';

export type UsageLevel = 'ok' | 'warning' | 'critical' | 'unlimited';

export type UsageState = {
    ratio: number | null;
    level: UsageLevel;
    remaining: number | null;
};

export const PLAN_ORDER: readonly PlanKey[] = [
    'free',
    'starter',
    'pro',
    'business',
];

export function usageState(
    entry: UsageEntry,
    warnAt = 0.8,
    criticalAt = 1,
): UsageState {
    if (entry.limit === null || entry.limit === undefined) {
        return { ratio: null, level: 'unlimited', remaining: null };
    }

    if (entry.limit <= 0) {
        return { ratio: 1, level: 'critical', remaining: 0 };
    }

    const raw = entry.used / entry.limit;
    const ratio = Math.min(1, Math.max(0, raw));
    const level: UsageLevel =
        raw >= criticalAt ? 'critical' : raw >= warnAt ? 'warning' : 'ok';

    return { ratio, level, remaining: Math.max(0, entry.limit - entry.used) };
}

export function monthlyEquivalent(
    plan: Plan,
    interval: BillingInterval,
): number {
    return interval === 'year' ? plan.price_yearly / 12 : plan.price_monthly;
}

export function yearlySavings(plan: Plan): number {
    const fullYear = plan.price_monthly * 12;

    if (fullYear <= 0 || plan.price_yearly <= 0) {
        return 0;
    }

    return Math.max(0, (fullYear - plan.price_yearly) / fullYear);
}

export function maxYearlySavings(plans: Plan[]): number {
    return plans.reduce((max, plan) => Math.max(max, yearlySavings(plan)), 0);
}

export type PlanAction =
    | 'current'
    | 'upgrade'
    | 'downgrade'
    | 'switch-interval';

export function planAction(
    plan: Plan,
    subscription: Subscription | null,
    interval: BillingInterval,
    effectivePlan: string | null = null,
): PlanAction {
    const fromSubscription: PlanKey =
        subscription && isActiveStatus(subscription.status)
            ? subscription.plan
            : 'free';
    const currentKey: PlanKey = isPlanKey(effectivePlan)
        ? effectivePlan
        : fromSubscription;
    const currentInterval: BillingInterval = subscription?.interval ?? 'month';

    if (plan.key === currentKey) {
        return plan.key !== 'free' && currentInterval !== interval
            ? 'switch-interval'
            : 'current';
    }

    return PLAN_ORDER.indexOf(plan.key) > PLAN_ORDER.indexOf(currentKey)
        ? 'upgrade'
        : 'downgrade';
}

export function isPlanKey(value: unknown): value is PlanKey {
    return (
        typeof value === 'string' &&
        (PLAN_ORDER as readonly string[]).includes(value)
    );
}

export function isActiveStatus(status: SubscriptionStatus): boolean {
    return (
        status === 'active' || status === 'trialing' || status === 'past_due'
    );
}

export function subscriptionTone(
    status: SubscriptionStatus,
): 'success' | 'warning' | 'destructive' | 'info' | 'neutral' {
    switch (status) {
        case 'active':
            return 'success';
        case 'trialing':
            return 'info';
        case 'past_due':
            return 'warning';
        case 'incomplete':
            return 'warning';
        case 'canceled':
            return 'neutral';
    }
}
