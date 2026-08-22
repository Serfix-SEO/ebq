<?php

namespace App\Livewire\Referrals;

use App\Models\Referral;
use App\Services\ReferralProgram;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Client "Refer & earn" page: the shareable referral URL plus tracking of
 * every referred sign-up and whether it matured into a reward. Client-facing:
 * internal states (qualified / credit_failed) collapse into one neutral
 * "Pending" bucket — never leak pipeline vocabulary.
 */
class ReferralHub extends Component
{
    public string $code = '';

    public string $url = '';

    public function mount(): void
    {
        $this->code = app(ReferralProgram::class)->codeFor(Auth::user());
        $this->url = rtrim((string) config('app.public_url', config('app.url')), '/').'/?ref='.$this->code;
    }

    /** j***@gmail.com — enough for the referrer to recognize, never the full address. */
    public static function maskEmail(?string $email): string
    {
        $email = (string) $email;
        $at = strpos($email, '@');
        if ($at < 1) {
            return '***';
        }

        return mb_substr($email, 0, 1).'***'.substr($email, $at);
    }

    public function render()
    {
        $referrals = Referral::query()
            ->where('referrer_user_id', Auth::id())
            ->with('referred:id,email,created_at')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        $credited = $referrals->where('status', Referral::STATUS_CREDITED);

        return view('livewire.referrals.referral-hub', [
            'referrals' => $referrals,
            'stats' => [
                'signups' => $referrals->count(),
                'pending' => $referrals->count() - $credited->count(),
                'matured' => $credited->count(),
                'earned_usd' => $credited->sum('credit_cents') / 100,
            ],
        ]);
    }
}
