<?php

namespace App\Services\Content\Publishing;

use App\Models\ContentIntegration;

/**
 * A driver whose destination needs the user to pick a target (which blog,
 * which collection, which dataset) before publishing can work.
 *
 * Convention: verify() fetches the option lists and caches them in the
 * integration's plain `config` under `available_*` keys, so targets() is a
 * cheap config read (no HTTP). selectTarget() persists a choice — and may do
 * follow-up fetches (e.g. Webflow loads the collection's field schema).
 *
 * The connect UI loops: while targets() returns steps, auto-select any step
 * with exactly one option, otherwise show a dropdown. The integration flips
 * to `connected` only when targets() returns [].
 */
interface ProvidesTargets
{
    /**
     * Remaining unresolved choice steps, in order.
     *
     * @return list<array{key: string, label: string, options: list<array{id: string, label: string}>}>
     */
    public function targets(ContentIntegration $integration): array;

    /** Persist the chosen option for a step into the integration's config. */
    public function selectTarget(ContentIntegration $integration, string $key, string $id): PublishResult;
}
