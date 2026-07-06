<?php

namespace Tests\Feature;

use App\Mail\GrowthReportMail;
use App\Models\ReportBranding;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression test (found 2026-07-06 via the new /admin/ops dashboard):
 * GrowthReportMail holds a public ReportBranding property, and every
 * non-whitelabel recipient gets ReportBranding::ebqDefault() — an
 * in-memory, never-persisted model. SerializesModels converted it to a
 * null-id ModelIdentifier at queue time; the worker's firstOrFail()
 * re-fetch then threw ModelNotFoundException, silently killing every
 * default-branded growth-report email daily from 2026-06-18 to 2026-07-06.
 */
class GrowthReportMailSerializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_queued_mailable_with_unsaved_default_branding_survives_serialization(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'report-serialize.test']);
        $date = now()->subDay()->toDateString();

        $mail = new GrowthReportMail($user, $website, $date, $date, 'daily', ReportBranding::ebqDefault());

        // The exact queue round-trip that used to throw ModelNotFoundException.
        $restored = unserialize(serialize($mail));

        $this->assertSame('Serfix', $restored->branding->company_name);
        $this->assertFalse($restored->branding->exists);
    }

    public function test_persisted_branding_still_round_trips_via_identifier(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'report-persisted.test']);
        $row = ReportBranding::create([
            'user_id' => $user->id,
            'website_id' => null,
            'company_name' => 'Agency X',
            'accent_color' => '#123456',
        ]);
        $date = now()->subDay()->toDateString();

        $mail = new GrowthReportMail($user, $website, $date, $date, 'daily', $row);
        $restored = unserialize(serialize($mail));

        $this->assertSame('Agency X', $restored->branding->company_name);
        $this->assertTrue($restored->branding->exists);
        $this->assertTrue($restored->branding->is($row));
    }
}
