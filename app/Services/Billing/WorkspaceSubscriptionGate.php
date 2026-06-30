<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Workspace;
use App\Models\XPostUsage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

class WorkspaceSubscriptionGate
{
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

        return $this->isFirstWorkspace($workspace) || $workspace->subscribed('default');
    }

    public function canPublishX(Workspace $workspace): bool
    {
        if (! $this->isEnabled() || $this->isFirstWorkspace($workspace)) {
            return true;
        }

        return $this->canPublish($workspace) && $this->remainingXPosts($workspace) > 0;
    }

    public function monthlyXPostLimit(): int
    {
        $budgetCents = (int) config('subscriptions.monthly_x_budget_cents');
        $postCostCents = (float) config('subscriptions.x_post_cost_cents');

        if ($postCostCents <= 0.0) {
            return 0;
        }

        return (int) floor($budgetCents / $postCostCents);
    }

    public function remainingXPosts(Workspace $workspace): int
    {
        if (! $this->isEnabled() || $this->isFirstWorkspace($workspace)) {
            return PHP_INT_MAX;
        }

        $usage = $this->usageForCurrentPeriod($workspace);

        return max(0, $this->monthlyXPostLimit() - $usage->used);
    }

    public function currentXPostUsage(Workspace $workspace): int
    {
        [$periodStart] = $this->currentMonthlyPeriod();

        return (int) XPostUsage::query()
            ->where('workspace_id', $workspace->id)
            ->whereDate('period_start', $periodStart->toDateString())
            ->value('used');
    }

    public function recordXPostRequest(Workspace $workspace, int $count = 1): XPostUsage
    {
        $usage = $this->usageForCurrentPeriod($workspace);
        $usage->increment('used', $count);

        return $usage->refresh();
    }

    private function usageForCurrentPeriod(Workspace $workspace): XPostUsage
    {
        [$periodStart, $periodEnd] = $this->currentMonthlyPeriod();

        $usage = XPostUsage::query()
            ->where('workspace_id', $workspace->id)
            ->whereDate('period_start', $periodStart->toDateString())
            ->first();

        if ($usage instanceof XPostUsage) {
            return $usage;
        }

        return XPostUsage::query()->create([
            'workspace_id' => $workspace->id,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'used' => 0,
        ]);
    }

    private function isFirstWorkspace(Workspace $workspace): bool
    {
        $firstWorkspaceId = Workspace::query()
            ->oldest('created_at')
            ->oldest('id')
            ->value('id');

        return $firstWorkspaceId === $workspace->id;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function currentMonthlyPeriod(): array
    {
        $now = CarbonImmutable::instance(Date::now());
        $periodStart = $now->startOfMonth();

        return [$periodStart, $periodStart->endOfMonth()];
    }
}
