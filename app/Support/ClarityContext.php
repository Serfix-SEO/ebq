<?php

namespace App\Support;

use App\Models\ContentIntegration;
use App\Models\ContentTopic;
use App\Models\User;
use App\Services\Content\ContentEntitlements;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Custom tags handed to Microsoft Clarity so sessions can be segmented by who
 * is driving them and what state their account is in.
 *
 * The headline one is separating STAFF traffic from real customer traffic.
 * Clarity has recorded admin sessions since the tag was installed (every admin
 * view renders `x-layouts.app`, which carries the snippet), mixed
 * indistinguishably into customer data — so every heatmap and recording set
 * silently included our own clicking around.
 *
 * IMPERSONATION is the case that matters most: the authenticated user is the
 * CLIENT, so `is_admin` is false and the session looks exactly like theirs.
 * Without a tag for it, admin QA is permanently indistinguishable from the
 * customer behaviour it is meant to diagnose.
 *
 * PERFORMANCE: this runs in the <head> of every layout on every request. The
 * cheap tags (session/plan/trial/locale) read columns already loaded on the
 * auth user; anything needing a COUNT is cached per user for 10 minutes —
 * a segment does not need to be real-time, and page renders must not pay for
 * it. Never throws: a broken analytics tag must not take a page down.
 *
 * NO PII. Clarity hashes the identify() id but NOT custom tag values, so
 * nothing here may carry an email, name or domain.
 */
final class ClarityContext
{
    public const TYPE_GUEST = 'guest';

    public const TYPE_CUSTOMER = 'customer';

    public const TYPE_ADMIN = 'admin';

    public const TYPE_IMPERSONATING = 'impersonating';

    private const CACHE_TTL = 600;

    /**
     * @return array<string, string>
     */
    public static function tags(): array
    {
        try {
            $type = self::sessionType();

            $tags = [
                'session_type' => $type,
                // The everyday filter: one click to exclude everything driven
                // by us. `session_type` keeps the detail for when it matters.
                'staff_session' => in_array($type, [self::TYPE_ADMIN, self::TYPE_IMPERSONATING], true) ? 'yes' : 'no',
                'locale' => app()->getLocale(),
                'product_mode' => config('features.seo_platform_ui') ? 'seo+content' : 'content',
            ];

            $user = Auth::user();
            if ($user === null) {
                return $tags;
            }

            $ent = app(ContentEntitlements::class);
            $tags['plan'] = self::plan($user, $ent);

            // Which day of the trial they are on — where a 5-day trial loses
            // people is invisible in an undifferentiated "trial" bucket.
            if ($ent->onContentTrial($user) && $user->content_trial_started_at !== null) {
                $tags['trial_day'] = (string) min(99, max(1, (int) $user->content_trial_started_at->startOfDay()->diffInDays(now()->startOfDay()) + 1));
            }

            if ($user->email_verified_at === null) {
                $tags['email_verified'] = 'no';
            }

            return $tags + self::cachedCountTags($user);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Stable id for `clarity("identify", …)` — links a person's SESSIONS
     * together in the dashboard.
     *
     * Clarity ends a session after ~30 minutes of inactivity while our own
     * lasts 24h (SESSION_LIFETIME=1440), and this product is full of long
     * waits — an article generation runs for minutes and people switch tabs.
     * One visit therefore lands as several sessions. Identify does not merge
     * them (deliberately: merging on our session boundary could produce
     * day-long recordings), it makes them findable as one person's journey.
     *
     * Signed in → the user ULID. Pseudonymous, and Clarity hashes it again on
     * their side. No friendlyName is passed: that field is NOT hashed, so a
     * name or email there would hand PII to Microsoft.
     *
     * Anonymous onboarding → a one-way HMAC of the resume token, so a guest's
     * wizard run still stitches together. NEVER the token itself: that is a
     * capability that resumes someone else's onboarding.
     */
    public static function identity(): ?string
    {
        try {
            $user = Auth::user();
            if ($user !== null) {
                return (string) $user->id;
            }

            $token = self::onboardingToken();

            return $token === null
                ? null
                : 'anon-'.substr(hash_hmac('sha256', $token, (string) config('app.key')), 0, 24);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function onboardingToken(): ?string
    {
        if (! app()->bound('session') || ! app('session')->isStarted()) {
            return null;
        }
        $token = (string) session('content_onboarding_token', '');

        return $token === '' ? null : $token;
    }

    public static function sessionType(): string
    {
        // An impersonated session is an ADMIN driving a client account — the
        // logged-in user is the client, so check the session flag first or
        // this returns 'customer' and hides exactly what we want to exclude.
        if (self::isImpersonating()) {
            return self::TYPE_IMPERSONATING;
        }

        $user = Auth::user();
        if ($user === null) {
            return self::TYPE_GUEST;
        }

        return $user->is_admin ? self::TYPE_ADMIN : self::TYPE_CUSTOMER;
    }

    /** paid / trial / comped / free — the same rules the admin dashboard counts by. */
    private static function plan(User $user, ContentEntitlements $ent): string
    {
        return match (true) {
            $ent->hasContentSubscription($user) => 'paid',
            $ent->compSites($user) > 0 => 'comped',
            $ent->onContentTrial($user) => 'trial',
            default => 'free',
        };
    }

    /**
     * Tags that need a COUNT, cached per user. Bucketed, not exact: Clarity
     * filters on values, and "articles = 37" is a useless segment where
     * "articles = 21+" is a cohort.
     *
     * @return array<string, string>
     */
    private static function cachedCountTags(User $user): array
    {
        return Cache::remember('clarity:tags:'.$user->id, self::CACHE_TTL, function () use ($user) {
            $websites = $user->websites()->count();
            $websiteIds = $user->websites()->pluck('id');

            $articles = $websiteIds->isEmpty() ? 0 : ContentTopic::query()
                ->whereIn('website_id', $websiteIds)
                ->where('status', ContentTopic::STATUS_PUBLISHED)
                ->count();

            $publishing = $websiteIds->isNotEmpty() && ContentIntegration::query()
                ->whereIn('website_id', $websiteIds)
                ->exists();

            return [
                'websites' => self::bucket($websites, [0, 1, 2, 5]),
                'articles_published' => self::bucket($articles, [0, 1, 5, 20]),
                // The known drop-off: a calendar that cannot publish anywhere.
                'publishing' => $publishing ? 'connected' : 'none',
            ];
        });
    }

    /**
     * Label $n by which band it falls in. The top edge becomes "N+"; a band
     * covering exactly one value prints that value alone (a lone website is
     * "1", not "1-1").
     *
     * @param  list<int>  $edges  ascending band starts
     */
    private static function bucket(int $n, array $edges): string
    {
        for ($i = count($edges) - 1; $i >= 0; $i--) {
            if ($n >= $edges[$i]) {
                if ($i === count($edges) - 1) {
                    return $edges[$i].'+';
                }
                $high = $edges[$i + 1] - 1;

                return $edges[$i] === $high ? (string) $edges[$i] : $edges[$i].'-'.$high;
            }
        }

        return (string) $edges[0];
    }

    private static function isImpersonating(): bool
    {
        // Guarded: the marketing layouts render for guests and in contexts
        // where the session store may not be resolvable.
        if (! app()->bound('session') || ! app('session')->isStarted()) {
            return false;
        }

        return session()->has('impersonator_id');
    }
}
