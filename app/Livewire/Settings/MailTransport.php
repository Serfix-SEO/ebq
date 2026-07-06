<?php

namespace App\Livewire\Settings;

use App\Models\GoogleAccount;
use App\Models\MailTransport as MailTransportModel;
use App\Models\MicrosoftAccount;
use App\Models\Website;
use App\Services\Mail\DynamicMailerFactory;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Symfony\Component\Mime\Email as SymfonyEmail;

/**
 * Settings → Reports → Mail transport panel. Lets the user pick how
 * branded report emails are sent: EBQ default mailer, their connected
 * Gmail account, their connected Outlook account, or a custom SMTP
 * server. Mirrors the per-user/per-website scope toggle on the
 * ReportBranding panel.
 *
 * Plan gate: when `report_whitelabel` is off the picker is hidden and
 * an upgrade banner is shown — saved rows are preserved.
 */
class MailTransport extends Component
{
    public ?string $websiteId = null;

    public string $scope = 'user';
    public string $provider = ''; // '' = no transport, fall back to EBQ default
    public string $display_name = '';
    public string $from_address = '';

    // SMTP-only fields.
    public string $smtp_host = '';
    public ?int $smtp_port = 587;
    public string $smtp_username = '';
    public string $smtp_password = '';
    public string $smtp_encryption = 'tls';

    // OAuth-only field — selected account id (FK).
    public ?int $oauth_account_id = null;

    public bool $saved = false;
    public ?string $testResult = null;

    public function mount(): void
    {
        $this->websiteId = session('current_website_id');
        $this->load();
    }

    #[On('website-changed')]
    public function switchWebsite(string $websiteId): void
    {
        $this->websiteId = $websiteId;
        $this->saved = false;
        $this->testResult = null;
        $this->load();
    }

    public function updatedScope(): void
    {
        $this->load();
    }

    public function save(): void
    {
        if (! $this->planAllowsWhitelabel()) {
            return;
        }
        $this->validate([
            // `present`, not `required` — an empty provider is the valid
            // "EBQ default / remove transport" selection, which `required`
            // would silently reject (empty string fails `required`), making
            // Save appear to do nothing. gmail/outlook stay accepted so any
            // pre-existing saved rows keep validating even while those
            // options are hidden from the picker.
            'provider'        => ['present', 'in:,gmail,outlook,smtp'],
            'from_address'    => ['required_unless:provider,', 'email', 'max:191'],
            'display_name'    => ['nullable', 'string', 'max:120'],
            'smtp_host'       => ['required_if:provider,smtp', 'nullable', 'string', 'max:191'],
            'smtp_port'       => ['required_if:provider,smtp', 'nullable', 'integer', 'min:1', 'max:65535'],
            'smtp_username'   => ['nullable', 'string', 'max:191'],
            'smtp_password'   => ['nullable', 'string', 'max:191'],
            'smtp_encryption' => ['required', 'in:tls,ssl,none'],
            'oauth_account_id' => ['nullable', 'string'],
        ]);

        if ($this->provider === '') {
            // Empty provider = remove the saved transport; reports fall
            // back to EBQ default.
            $row = $this->findRow();
            if ($row) {
                $row->delete();
            }
            $this->saved = true;
            return;
        }

        $row = $this->findOrNewRow();
        $row->fill([
            'provider'         => $this->provider,
            'display_name'     => $this->display_name ?: null,
            'from_address'     => $this->from_address,
            'oauth_account_id' => in_array($this->provider, ['gmail', 'outlook']) ? $this->oauth_account_id : null,
            'smtp_host'        => $this->provider === 'smtp' ? $this->smtp_host : null,
            'smtp_port'        => $this->provider === 'smtp' ? $this->smtp_port : null,
            'smtp_username'    => $this->provider === 'smtp' ? ($this->smtp_username ?: null) : null,
            'smtp_password'    => ($this->provider === 'smtp' && $this->smtp_password !== '')
                ? $this->smtp_password
                : ($row->exists ? $row->smtp_password : null), // preserve existing on re-save
            'smtp_encryption'  => $this->smtp_encryption,
        ]);
        $row->save();
        $this->saved = true;
    }

    public function sendTest(): void
    {
        if (! $this->planAllowsWhitelabel()) {
            return;
        }
        $row = $this->findRow();
        if (! $row) {
            $this->testResult = 'No transport saved yet — save first, then try again.';
            return;
        }

        $user = Auth::user();
        $message = (new SymfonyEmail())
            ->from(new \Symfony\Component\Mime\Address($row->from_address, $row->display_name ?? ''))
            ->to(new \Symfony\Component\Mime\Address($user->email))
            ->subject('Test: Serfix report transport')
            ->html('<p>Sent from your configured Serfix report mail transport. If you can read this, the transport is working.</p>');

        try {
            if ($row->provider === MailTransportModel::PROVIDER_SMTP) {
                app(DynamicMailerFactory::class)
                    ->buildSymfonyTransport($row)
                    ->send($message);
            } elseif ($row->provider === MailTransportModel::PROVIDER_GMAIL) {
                app(\App\Services\Mail\GmailMailSender::class)->send($row, $message);
            } elseif ($row->provider === MailTransportModel::PROVIDER_OUTLOOK) {
                app(\App\Services\Mail\OutlookMailSender::class)->send($row, $message);
            }
            $row->forceFill(['last_verified_at' => now(), 'last_error' => null])->save();
            $this->testResult = "Test sent to {$user->email}.";
        } catch (\Throwable $e) {
            $row->forceFill(['last_error' => substr($e->getMessage(), 0, 1000)])->save();
            $this->testResult = 'Test failed: ' . $e->getMessage();
        }
    }

    public function render()
    {
        $userId = Auth::id();
        $row = $this->findRow();
        return view('livewire.settings.mail-transport', [
            'allowed' => $this->planAllowsWhitelabel(),
            'currentWebsite' => $this->getWebsite(),
            'savedRow' => $row,
            'googleAccounts' => GoogleAccount::where('user_id', $userId)->get(),
            'microsoftAccounts' => MicrosoftAccount::where('user_id', $userId)->get(),
        ]);
    }

    private function load(): void
    {
        $row = $this->findRow();
        if ($row) {
            $this->provider = (string) $row->provider;
            $this->display_name = (string) ($row->display_name ?? '');
            $this->from_address = (string) $row->from_address;
            $this->oauth_account_id = $row->oauth_account_id;
            $this->smtp_host = (string) ($row->smtp_host ?? '');
            $this->smtp_port = $row->smtp_port;
            $this->smtp_username = (string) ($row->smtp_username ?? '');
            // Never expose the existing password to the form — leaving
            // the field empty on re-save preserves the stored value.
            $this->smtp_password = '';
            $this->smtp_encryption = (string) ($row->smtp_encryption ?: 'tls');
        } else {
            $this->provider = '';
            $this->display_name = (string) (Auth::user()->name ?? '');
            $this->from_address = (string) (Auth::user()->email ?? '');
            $this->oauth_account_id = null;
            $this->smtp_host = '';
            $this->smtp_port = 587;
            $this->smtp_username = '';
            $this->smtp_password = '';
            $this->smtp_encryption = 'tls';
        }
    }

    private function findRow(): ?MailTransportModel
    {
        $userId = Auth::id();
        if ($this->scope === 'website' && ($this->websiteId !== null && $this->websiteId !== '')) {
            return MailTransportModel::query()
                ->where('user_id', $userId)
                ->where('website_id', $this->websiteId)
                ->first();
        }
        return MailTransportModel::query()
            ->where('user_id', $userId)
            ->whereNull('website_id')
            ->first();
    }

    private function findOrNewRow(): MailTransportModel
    {
        $row = $this->findRow();
        if ($row) {
            return $row;
        }
        $userId = Auth::id();
        return new MailTransportModel([
            'user_id' => $userId,
            'website_id' => $this->scope === 'website' && ($this->websiteId !== null && $this->websiteId !== '') ? $this->websiteId : null,
        ]);
    }

    private function getWebsite(): ?Website
    {
        if (($this->websiteId === null || $this->websiteId === '')) {
            return null;
        }
        return Auth::user()?->canViewWebsiteId($this->websiteId) ? Website::find($this->websiteId) : null;
    }

    private function planAllowsWhitelabel(): bool
    {
        $flags = Auth::user()?->effectivePlanFeatures() ?? [];
        return ($flags['report_whitelabel'] ?? false) === true;
    }
}
