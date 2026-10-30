import { describe, expect, it } from 'vitest';
import {
    isPlanKey,
    maxYearlySavings,
    monthlyEquivalent,
    planAction,
    subscriptionTone,
    usageState,
    yearlySavings,
} from '@/lib/billing';
import type { Plan, Subscription } from '@/types';

const plan = (key: Plan['key'], monthly: number, yearly: number): Plan => ({
    key,
    name: key,
    description: '',
    price_monthly: monthly,
    price_yearly: yearly,
    currency: 'USD',
    features: [],
    limits: {},
});

const free = plan('free', 0, 0);
const starter = plan('starter', 19, 190);
const pro = plan('pro', 49, 470);
const business = plan('business', 99, 950);

const subscription = (overrides: Partial<Subscription> = {}): Subscription => ({
    plan: 'pro',
    status: 'active',
    interval: 'month',
    current_period_end: null,
    cancel_at_period_end: false,
    ...overrides,
});

describe('usageState', () => {
    it('reports unlimited usage', () => {
        expect(usageState({ used: 42, limit: null })).toEqual({
            ratio: null,
            level: 'unlimited',
            remaining: null,
        });
    });

    it('classifies ok / warning / critical levels', () => {
        expect(usageState({ used: 3, limit: 10 }).level).toBe('ok');
        expect(usageState({ used: 8, limit: 10 }).level).toBe('warning');
        expect(usageState({ used: 10, limit: 10 }).level).toBe('critical');
        expect(usageState({ used: 12, limit: 10 })).toMatchObject({
            ratio: 1,
            level: 'critical',
            remaining: 0,
        });
        expect(usageState({ used: 0, limit: 0 }).level).toBe('critical');
    });
});

describe('prices', () => {
    it('computes the monthly equivalent for yearly billing', () => {
        expect(monthlyEquivalent(pro, 'month')).toBe(49);
        expect(monthlyEquivalent(pro, 'year')).toBeCloseTo(39.17, 2);
    });

    it('computes yearly savings', () => {
        expect(yearlySavings(free)).toBe(0);
        expect(yearlySavings(starter)).toBeCloseTo(1 - 190 / 228, 5);
        expect(maxYearlySavings([free, starter, pro, business])).toBeCloseTo(
            1 - 470 / 588,
            5,
        );
    });
});

describe('planAction', () => {
    it('treats a missing subscription as the free plan', () => {
        expect(planAction(free, null, 'month')).toBe('current');
        expect(planAction(pro, null, 'month')).toBe('upgrade');
    });

    it('detects upgrades, downgrades and interval switches', () => {
        const active = subscription();

        expect(planAction(pro, active, 'month')).toBe('current');
        expect(planAction(pro, active, 'year')).toBe('switch-interval');
        expect(planAction(business, active, 'month')).toBe('upgrade');
        expect(planAction(starter, active, 'month')).toBe('downgrade');
    });

    it('ignores canceled subscriptions and honours the effective plan', () => {
        expect(
            planAction(pro, subscription({ status: 'canceled' }), 'month'),
        ).toBe('upgrade');
        expect(planAction(starter, null, 'month', 'starter')).toBe('current');
        expect(planAction(pro, null, 'month', 'unknown')).toBe('upgrade');
    });
});

describe('helpers', () => {
    it('validates plan keys', () => {
        expect(isPlanKey('pro')).toBe(true);
        expect(isPlanKey('enterprise')).toBe(false);
    });

    it('maps subscription status to a badge tone', () => {
        expect(subscriptionTone('active')).toBe('success');
        expect(subscriptionTone('past_due')).toBe('warning');
        expect(subscriptionTone('canceled')).toBe('neutral');
    });
});
