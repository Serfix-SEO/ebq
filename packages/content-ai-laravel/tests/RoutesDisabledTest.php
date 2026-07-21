<?php

namespace Serfix\ContentAi\Tests;

use Serfix\ContentAi\Models\Article;

/**
 * A host rendering articles inside their own app turns the bundled pages off
 * — but must still be able to RECEIVE them. Registering the webhook route
 * independently of the blog routes is what makes that work.
 */
class RoutesDisabledTest extends TestCase
{
    protected bool $routesEnabled = false;

    public function test_blog_pages_are_gone_but_the_webhook_still_receives(): void
    {
        $this->get('/blog/anything')->assertNotFound();

        $this->deliver($this->articlePayload())->assertOk();

        $this->assertSame(1, Article::query()->count());
    }
}
