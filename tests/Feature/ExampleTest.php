<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `/` serves the public marketing landing page (it stopped redirecting
     * to the dashboard when the marketing site was built).
     */
    public function test_the_application_serves_the_landing_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(route('features'));
    }
}
