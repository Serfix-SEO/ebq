<?php

namespace App\Services\Content;

use App\Models\ContentRewriteCreditEvent as Event;
use App\Models\ContentRewriteRequest;
use App\Models\ContentTopic;
use App\Models\User;
use App\Support\ContentAutopilotConfig;
use App\Services\Content\Exceptions\InsufficientRewriteCreditsException;
use Illuminate\Support\Facades\DB;

/**
 * Rewrite-credit accounting. DB-count-is-the-meter (KeywordTrackerQuota
 * pattern): balances are ledger sums, never cached counters.
 *
 * Two pools: the FREE monthly allowance (paid subscribers only, computed per
 * calendar month, no rollover) and the PURCHASED pool (never expires; anyone
 * with content access can buy). Spends go free-first; refunds mirror the
 * source of the spend they reverse, so a refund never inflates the wrong
 * pool. Accepted user-favorable edge: a free spend refunded after month
 * rollover credits the NEW month's allowance computation.
 */
class RewriteCredits
{
    public function __construct(private ContentEntitlements $entitlements) {}

    public function monthlyFreeAllowance(User $user): int
    {
        return $this->entitlements->hasContentSubscription($user)
            ? ContentAutopilotConfig::rewriteMonthlyFree()
            : 0;
    }

    public function freeUsedThisMonth(User $user): int
    {
        $month = now()->startOfMonth();

        $spent = (int) Event::query()
            ->where('user_id', $user->id)
            ->where('kind', Event::KIND_SPEND)
            ->where('source', Event::SOURCE_FREE)
            ->where('created_at', '>=', $month)
            ->sum('delta');
        $refunded = (int) Event::query()
            ->where('user_id', $user->id)
            ->where('kind', Event::KIND_REFUND)
            ->where('source', Event::SOURCE_FREE)
            ->where('created_at', '>=', $month)
            ->sum('delta');

        return max(0, -$spent - $refunded);
    }

    public function purchasedBalance(User $user): int
    {
        return max(0, (int) Event::query()
            ->where('user_id', $user->id)
            ->where('source', '!=', Event::SOURCE_FREE)
            ->sum('delta'));
    }

    /**
     * In-flight rewrites (queued/running) — each holds ONE credit in reserve.
     * Credits are only SPENT when a rewrite finalizes (owner rule 2026-08-23:
     * never on optimization/internal passes, never on failures); the
     * reservation keeps a 1-credit user from starting five rewrites at once.
     */
    public function reservedCount(User $user): int
    {
        return ContentRewriteRequest::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [ContentRewriteRequest::STATUS_QUEUED, ContentRewriteRequest::STATUS_RUNNING])
            ->count();
    }

    /** @return array{free_remaining:int, purchased:int, total:int, reserved:int, available:int} */
    public function summary(User $user): array
    {
        $free = max(0, $this->monthlyFreeAllowance($user) - $this->freeUsedThisMonth($user));
        $purchased = $this->purchasedBalance($user);
        $reserved = $this->reservedCount($user);

        return [
            'free_remaining' => $free,
            'purchased' => $purchased,
            'total' => $free + $purchased,
            'reserved' => $reserved,
            'available' => max(0, $free + $purchased - $reserved),
        ];
    }

    /**
     * Reservation gate at dispatch: true when the user can start one more
     * rewrite. Serialized on the user row so two tabs can't both pass with a
     * single credit; the request row must be created in the SAME transaction.
     */
    public function canStartRewrite(User $user): bool
    {
        User::query()->whereKey($user->id)->lockForUpdate()->first();

        return $this->summary($user)['available'] >= 1;
    }

    /**
     * Charge the credit for a FINALIZED rewrite (called from the job's
     * success path only). Never blocks a delivered rewrite: an edge-case
     * race that emptied the balance mid-run just logs and forgives.
     */
    public function spendForRequest(ContentRewriteRequest $request): void
    {
        $user = User::query()->find($request->user_id);
        $topic = \App\Models\ContentTopic::query()->find($request->topic_id);
        if ($user === null || $topic === null) {
            return;
        }

        try {
            $event = $this->spend($user, $topic, $request->id);
            $request->update(['credit_event_id' => $event->id]);
        } catch (InsufficientRewriteCreditsException) {
            \Illuminate\Support\Facades\Log::warning('rewrite finalized with empty balance — credit forgiven', [
                'request_id' => $request->id, 'user_id' => $user->id,
            ]);
        }
    }

    /**
     * Consume one credit, free pool first. The user-row lock is the per-user
     * mutex — two tabs can't spend the same last credit.
     *
     * @throws InsufficientRewriteCreditsException
     */
    public function spend(User $user, ContentTopic $topic, string $requestId): Event
    {
        return DB::transaction(function () use ($user, $topic, $requestId): Event {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $summary = $this->summary($user);
            if ($summary['total'] < 1) {
                throw new InsufficientRewriteCreditsException('no rewrite credits left');
            }

            return Event::query()->create([
                'user_id' => $user->id,
                'delta' => -1,
                'kind' => Event::KIND_SPEND,
                'source' => $summary['free_remaining'] > 0 ? Event::SOURCE_FREE : Event::SOURCE_PURCHASED,
                'topic_id' => $topic->id,
                'rewrite_request_id' => $requestId,
                'created_at' => now(),
            ]);
        });
    }

    /** Idempotent: one refund per rewrite request, mirroring the spend's source. */
    public function refund(ContentRewriteRequest $request): void
    {
        $spend = Event::query()
            ->where('rewrite_request_id', $request->id)
            ->where('kind', Event::KIND_SPEND)
            ->first();
        if ($spend === null) {
            return;
        }
        $already = Event::query()
            ->where('rewrite_request_id', $request->id)
            ->where('kind', Event::KIND_REFUND)
            ->exists();
        if ($already) {
            return;
        }

        Event::query()->create([
            'user_id' => $spend->user_id,
            'delta' => 1,
            'kind' => Event::KIND_REFUND,
            'source' => $spend->source,
            'topic_id' => $spend->topic_id,
            'rewrite_request_id' => $request->id,
            'created_at' => now(),
        ]);
    }

    /**
     * Fulfill a purchased pack. Returns false when this Stripe session was
     * already fulfilled (unique index) — makes the success-return page and
     * the webhook mutually idempotent.
     */
    public function grantForPurchase(User $user, string $sessionId, int $credits): bool
    {
        if ($credits < 1) {
            return false;
        }

        try {
            Event::query()->create([
                'user_id' => $user->id,
                'delta' => $credits,
                'kind' => Event::KIND_PURCHASE,
                'source' => Event::SOURCE_PURCHASED,
                'stripe_session_id' => $sessionId,
                'created_at' => now(),
            ]);
        } catch (\Illuminate\Database\QueryException) {
            return false; // already fulfilled
        }

        return true;
    }

    /** Support/tinker: comp credits onto the purchased pool. */
    public function grantAdmin(User $user, int $credits): void
    {
        Event::query()->create([
            'user_id' => $user->id,
            'delta' => max(1, $credits),
            'kind' => Event::KIND_ADMIN_GRANT,
            'source' => Event::SOURCE_PURCHASED,
            'created_at' => now(),
        ]);
    }
}
