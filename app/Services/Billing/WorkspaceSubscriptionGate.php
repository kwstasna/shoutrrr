<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Platform;
use App\Models\Workspace;
use App\Services\Usage\UsageMeter;
use App\Support\UsageOperation;
use App\Support\UsagePricing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

class WorkspaceSubscriptionGate
{
    public function __construct(
        private readonly UsageMeter $usageMeter,
        private readonly UsagePricing $pricing,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('subscriptions.enabled');
    }

    public function canPublish(Workspace $workspace): bool
    {
        return $this->canUseWorkspace($workspace);
    }

    public function canUseWorkspace(Workspace $workspace): bool
    {
        if (! $this->isEnabled()) {
            return true;
        }

        return $workspace->is_initial || $workspace->subscribed('default');
    }

    public function canPublishX(Workspace $workspace): bool
    {
        if (! $this->isEnabled() || $workspace->is_initial) {
            return true;
        }

        return $this->canPublish($workspace)
            && $this->remainingXPosts($workspace) > 0
            && $this->remainingXBudgetMicrousd($workspace) >= $this->xPostCostMicrousd();
    }

    /**
     * Monthly X publish quota, or null when unlimited (a non-positive per-post
     * cost means X publishing is not billed).
     */
    public function monthlyXPostLimit(): ?int
    {
        $budgetCents = (int) config('subscriptions.monthly_x_budget_cents');
        $postCostCents = (float) config('subscriptions.x_post_cost_cents');

        if ($postCostCents <= 0.0) {
            return null;
        }

        return (int) floor($budgetCents / $postCostCents);
    }

    public function remainingXPosts(Workspace $workspace): int
    {
        if (! $this->isEnabled() || $workspace->is_initial) {
            return PHP_INT_MAX;
        }

        $limit = $this->monthlyXPostLimit();

        if ($limit === null) {
            return PHP_INT_MAX;
        }

        return max(0, $limit - $this->currentXPostUsage($workspace));
    }

    public function monthlyXBudgetMicrousd(): int
    {
        return (int) config('subscriptions.monthly_x_budget_cents') * 10_000;
    }

    public function remainingXBudgetMicrousd(Workspace $workspace): int
    {
        if (! $this->isEnabled() || $workspace->is_initial) {
            return PHP_INT_MAX;
        }

        return max(0, $this->monthlyXBudgetMicrousd() - $this->currentXCostMicrousd($workspace));
    }

    public function currentXCostMicrousd(Workspace $workspace): int
    {
        $periodStart = $this->billingPeriodStart($workspace);

        if ($periodStart !== null) {
            return $this->usageMeter->costSinceMicrousd($workspace->id, $periodStart, Platform::X);
        }

        return $this->usageMeter->currentPeriodCostMicrousd($workspace->id, Platform::X);
    }

    /**
     * X publishes in the current billing period. For a subscribed workspace the
     * period is anchored to the subscription date (matching when Stripe renews),
     * so it counts successful publish events since the last cycle start. Without
     * a subscription it falls back to the calendar-month metering counters.
     */
    public function currentXPostUsage(Workspace $workspace): int
    {
        $periodStart = $this->billingPeriodStart($workspace);

        if ($periodStart !== null) {
            return $this->usageMeter->countSince($workspace->id, $periodStart, Platform::X, UsageOperation::POST);
        }

        return $this->usageMeter->currentPeriodCount($workspace->id, Platform::X, UsageOperation::POST);
    }

    private function xPostCostMicrousd(): int
    {
        return $this->pricing->costWeightMicrousd(Platform::X->value, UsageOperation::POST, 1);
    }

    /**
     * Start of the workspace's current billing cycle: the subscription creation
     * date advanced by whole months (no overflow, mirroring Stripe's anchor
     * behavior on short months). Null when the workspace has no subscription.
     */
    private function billingPeriodStart(Workspace $workspace): ?CarbonImmutable
    {
        $subscription = $workspace->subscription('default');

        if ($subscription === null || $subscription->created_at === null) {
            return null;
        }

        $anchor = CarbonImmutable::instance($subscription->created_at);
        $now = CarbonImmutable::instance(Date::now());

        $elapsedMonths = (int) $anchor->diffInMonths($now);
        $periodStart = $anchor->addMonthsNoOverflow($elapsedMonths);

        if ($periodStart->greaterThan($now)) {
            $periodStart = $anchor->addMonthsNoOverflow($elapsedMonths - 1);
        }

        return $periodStart;
    }
}
