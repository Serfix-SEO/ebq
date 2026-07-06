<?php

namespace App\Livewire\Settings;

use App\Models\ReportBranding as ReportBrandingModel;
use App\Models\Website;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Settings → Reports → Branding panel. Edits a {@see ReportBrandingModel}
 * row owned either by the user (default for all their websites) or by
 * the currently-selected website (override).
 *
 * Plan gate: when `report_whitelabel` is off the form is hidden and an
 * upgrade banner is shown instead — the DB row is preserved.
 */
class ReportBranding extends Component
{
    use WithFileUploads;

    public ?string $websiteId = null;

    public string $scope = 'user'; // 'user' = default, 'website' = override
    public string $company_name = '';
    public string $accent_color = '#F26419';
    public ?string $footer_text = null;
    public ?string $contact_email = null;
    public ?string $contact_phone = null;
    public ?string $contact_address = null;
    public ?string $reply_to_email = null;
    public ?string $current_logo_url = null;

    // Livewire file upload — when present, replaces logo on save.
    public $logo;

    public bool $saved = false;

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
        $this->load();
    }

    public function updatedScope(): void
    {
        // Switching scope reloads the form from the matching row so
        // the user can see the saved values for the other scope.
        $this->load();
    }

    public function save(): void
    {
        if (! $this->planAllowsWhitelabel()) {
            return;
        }
        $this->validate([
            'company_name'    => ['required', 'string', 'max:120'],
            'accent_color'    => ['required', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'footer_text'     => ['nullable', 'string', 'max:2000'],
            'contact_email'   => ['nullable', 'email', 'max:191'],
            'contact_phone'   => ['nullable', 'string', 'max:64'],
            'contact_address' => ['nullable', 'string', 'max:2000'],
            'reply_to_email'  => ['nullable', 'email', 'max:191'],
            'logo'            => ['nullable', 'image', 'max:2048'], // 2MB
        ]);

        $row = $this->findOrNewRow();
        $row->fill([
            'company_name'    => $this->company_name,
            'accent_color'    => $this->accent_color,
            'footer_text'     => $this->footer_text,
            'contact_email'   => $this->contact_email,
            'contact_phone'   => $this->contact_phone,
            'contact_address' => $this->contact_address,
            'reply_to_email'  => $this->reply_to_email,
        ]);

        if ($this->logo) {
            // Persist on the `public` disk so the URL is permanent and
            // CDN-friendly. The path is what we store; the URL is
            // resolved at render time so storage roots can move.
            $path = $this->logo->store('report-branding/logos', 'public');
            $row->logo_path = $path;
        }

        $row->save();
        $this->logo = null;
        $this->current_logo_url = $row->logoUrl();
        $this->saved = true;
    }

    public function removeLogo(): void
    {
        $row = $this->findRow();
        if ($row && $row->logo_path) {
            Storage::disk('public')->delete($row->logo_path);
            $row->update(['logo_path' => null]);
            $this->current_logo_url = null;
            $this->saved = true;
        }
    }

    public function render()
    {
        return view('livewire.settings.report-branding', [
            'allowed' => $this->planAllowsWhitelabel(),
            'currentWebsite' => $this->getWebsite(),
        ]);
    }

    private function load(): void
    {
        $row = $this->findRow();
        if ($row) {
            $this->company_name = (string) $row->company_name;
            $this->accent_color = (string) ($row->accent_color ?: '#F26419');
            $this->footer_text = $row->footer_text;
            $this->contact_email = $row->contact_email;
            $this->contact_phone = $row->contact_phone;
            $this->contact_address = $row->contact_address;
            $this->reply_to_email = $row->reply_to_email;
            $this->current_logo_url = $row->logoUrl();
        } else {
            // First-time render — pre-fill the company name with the
            // user's name so the form isn't empty. Operator can overwrite.
            $this->company_name = (string) (Auth::user()->name ?? '');
            $this->accent_color = '#F26419';
            $this->footer_text = null;
            $this->contact_email = null;
            $this->contact_phone = null;
            $this->contact_address = null;
            $this->reply_to_email = null;
            $this->current_logo_url = null;
        }
    }

    private function findRow(): ?ReportBrandingModel
    {
        $userId = Auth::id();
        if ($this->scope === 'website' && ($this->websiteId !== null && $this->websiteId !== '')) {
            return ReportBrandingModel::query()
                ->where('website_id', $this->websiteId)
                ->first();
        }
        return ReportBrandingModel::query()
            ->where('user_id', $userId)
            ->whereNull('website_id')
            ->first();
    }

    private function findOrNewRow(): ReportBrandingModel
    {
        $row = $this->findRow();
        if ($row) {
            return $row;
        }
        $userId = Auth::id();
        if ($this->scope === 'website' && ($this->websiteId !== null && $this->websiteId !== '')) {
            return new ReportBrandingModel(['website_id' => $this->websiteId]);
        }
        return new ReportBrandingModel(['user_id' => $userId]);
    }

    private function getWebsite(): ?Website
    {
        if (($this->websiteId === null || $this->websiteId === '')) {
            return null;
        }
        $user = Auth::user();
        if (! $user?->canViewWebsiteId($this->websiteId)) {
            return null;
        }
        return Website::find($this->websiteId);
    }

    private function planAllowsWhitelabel(): bool
    {
        $flags = Auth::user()?->effectivePlanFeatures() ?? [];
        return ($flags['report_whitelabel'] ?? false) === true;
    }
}
