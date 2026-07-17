<?php

namespace App\Support;

class TeamPermissions
{
    public const ROLE_OWNER = 'owner';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MEMBER = 'member';

    /**
     * Features that can be toggled per member/invitation.
     * Key = permission key stored in the JSON column.
     * Value = metadata for the UI.
     *
     * @var array<string, array{label: string, description: string, route: string}>
     */
    public const FEATURES = [
        'dashboard' => [
            'label' => 'Dashboard',
            'description' => 'Overview metrics and summary charts.',
            'route' => 'dashboard',
        ],
        'keywords' => [
            'label' => 'Keywords',
            'description' => 'Search Console keyword performance.',
            'route' => 'keywords.index',
        ],
        'content' => [
            'label' => 'Content',
            'description' => 'Automatic content calendar: planned, written, and published articles.',
            'route' => 'content.index',
        ],
        'rank_tracking' => [
            'label' => 'Rank Tracking',
            'description' => 'Tracked SERP positions and history.',
            'route' => 'rank-tracking.index',
        ],
        'pages' => [
            'label' => 'Pages',
            'description' => 'Per-page traffic and indexing status.',
            'route' => 'pages.index',
        ],
        'sitemaps' => [
            'label' => 'Sitemaps',
            'description' => 'Manage and track XML sitemaps.',
            'route' => 'sitemaps.index',
        ],
        'link_structure' => [
            'label' => 'Link Explorer',
            'description' => 'Per-page internal link structure from the site crawl (inbound/outbound links, orphans).',
            'route' => 'link-structure.index',
        ],
        'audits' => [
            'label' => 'Audits',
            'description' => 'On-demand page audits and reports.',
            'route' => 'custom-audit.index',
        ],
        'backlinks' => [
            'label' => 'Backlinks',
            'description' => 'Backlink inventory and audits.',
            'route' => 'backlinks.index',
        ],
        'reports' => [
            'label' => 'Reports',
            'description' => 'Scheduled and on-demand reports.',
            'route' => 'reports.index',
        ],
        'ai_studio' => [
            'label' => 'AI Studio',
            'description' => 'AI Studio — 47 tools for research, writing, improvement, marketing, eCommerce, media.',
            'route' => 'ai-studio.index',
        ],
        'settings' => [
            'label' => 'Settings',
            'description' => 'Site configuration and integrations.',
            'route' => 'settings.index',
        ],
        'team' => [
            'label' => 'Team',
            'description' => 'Invite, remove, and manage teammates.',
            'route' => 'team.index',
        ],
    ];

    /** @return list<string> */
    public static function featureKeys(): array
    {
        return array_keys(self::FEATURES);
    }

    /** @return list<string> */
    public static function roles(): array
    {
        return [self::ROLE_ADMIN, self::ROLE_MEMBER];
    }

    /**
     * Normalize a permissions payload. Owner/admin always evaluate to full access
     * (handled by the caller). Returns null when the member has every feature
     * (saves space + lets the UI show "Full access").
     *
     * @param  iterable<string>|null  $input
     * @return list<string>|null
     */
    public static function normalize(?iterable $input): ?array
    {
        if ($input === null) {
            return null;
        }

        $valid = self::featureKeys();
        $out = [];
        foreach ($input as $key) {
            if (! is_string($key)) {
                continue;
            }
            $k = trim($key);
            if ($k !== '' && in_array($k, $valid, true)) {
                $out[$k] = true;
            }
        }

        $keys = array_keys($out);

        if (count($keys) === count($valid)) {
            return null;
        }

        sort($keys);

        return $keys;
    }

    /**
     * Whether the given role + permissions combo grants access to a feature.
     *
     * @param  list<string>|null  $permissions
     */
    public static function allows(string $role, ?array $permissions, string $feature): bool
    {
        if ($role === self::ROLE_OWNER || $role === self::ROLE_ADMIN) {
            return true;
        }

        if ($permissions === null) {
            return true;
        }

        return in_array($feature, $permissions, true);
    }
}
