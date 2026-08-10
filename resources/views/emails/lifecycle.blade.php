<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #1e293b; line-height: 1.65; margin: 0; padding: 0; background: #f1f5f9; }
        .container { max-width: 600px; margin: 0 auto; padding: 28px 14px; }
        .card { background: #ffffff; border-radius: 14px; padding: 30px 28px; }
        p { font-size: 15px; color: #334155; margin: 0 0 16px; }
        ul { margin: 0 0 16px; padding-left: 22px; }
        li { font-size: 15px; color: #334155; margin-bottom: 4px; }
        .question { font-weight: 700; color: #0f172a; }
        .btn-wrap { margin: 22px 0; }
        .btn { display: inline-block; background: #F26419; color: #ffffff !important; text-decoration: none; border-radius: 10px; padding: 13px 26px; font-size: 14px; font-weight: 700; }
        .signoff { margin-top: 24px; }
        .footer { text-align: center; color: #94a3b8; font-size: 12px; margin-top: 18px; line-height: 1.7; }
        .footer a { color: #94a3b8; }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <p>{{ __('Hi :name,', ['name' => $firstName]) }}</p>

        @if ($segment === 1 && $stage === 'initial')
            <p>{{ __('Thanks for trying SERFIX.') }}</p>
            <p>{{ __("You've already started building content for your website, so I wanted to ask you one quick question:") }}</p>
            <p class="question">{{ __('What were you hoping SERFIX would make easier for you when you signed up?') }}</p>
            <p>{{ __('It could be planning what to write, creating content, saving time, keeping SEO consistent, or something completely different.') }}</p>
            <p>{{ __('Even a one-line reply would be genuinely useful as we improve the product.') }}</p>
            <p>{{ __("And if there's anything getting in your way while using SERFIX, just reply here and I'll help.") }}</p>
        @elseif ($segment === 1 && $stage === 'followup')
            <p>{{ __('One last question from me.') }}</p>
            <p class="question">{{ __('If SERFIX could take one SEO or content task completely off your plate, what would you choose?') }}</p>
            <ul>
                <li>{{ __('Research?') }}</li>
                <li>{{ __('Planning what to write?') }}</li>
                <li>{{ __('Creating the content?') }}</li>
                <li>{{ __('Publishing?') }}</li>
                <li>{{ __('Tracking performance?') }}</li>
                <li>{{ __('Or something else entirely?') }}</li>
            </ul>
            <p>{{ __('Just hit reply, even a few words is enough.') }}</p>
        @elseif ($segment === 2 && $stage === 'initial')
            <p>{{ __("You've created your SERFIX account, the next step is simply adding your website.") }}</p>
            <p>{{ __('Once you do, SERFIX can start understanding your site and finding content opportunities based on your actual business, rather than giving you generic ideas.') }}</p>
            @if ($ctaUrl)
                <div class="btn-wrap"><a href="{{ $ctaUrl }}" class="btn">{{ $ctaLabel }}</a></div>
            @endif
            <p>{{ __('It only takes a moment to get started.') }}</p>
            <p>{{ __("If there's something that stopped you from adding your site, just reply and tell me. That feedback genuinely helps us improve SERFIX.") }}</p>
        @elseif ($segment === 2 && $stage === 'followup')
            <p class="question">{{ __('What were you hoping to improve when you signed up for SERFIX?') }}</p>
            <p>{{ __('Maybe you wanted to:') }}</p>
            <ul>
                <li>{{ __('get more organic traffic,') }}</li>
                <li>{{ __('know what content to create,') }}</li>
                <li>{{ __('spend less time managing SEO,') }}</li>
                <li>{{ __('publish more consistently,') }}</li>
                <li>{{ __('or something completely different.') }}</li>
            </ul>
            <p>{{ __('Just hit reply with whatever comes to mind.') }}</p>
            <p>{{ __("I'm asking because we want to build SERFIX around the problems people actually need solved, not what we assume they need.") }}</p>
        @elseif ($segment === 3 && $stage === 'initial')
            <p>{{ __('Your website is already set up in SERFIX.') }}</p>
            <p>{{ __('The next step is where SERFIX starts turning what it learns about your website and search opportunities into a structured content plan.') }}</p>
            <p>{{ __('Instead of figuring out what to write next yourself, SERFIX helps identify and organize the opportunities worth working on.') }}</p>
            @if ($ctaUrl)
                <div class="btn-wrap"><a href="{{ $ctaUrl }}" class="btn">{{ $ctaLabel }}</a></div>
            @endif
            <p>{{ __('You can continue from where you left off.') }}</p>
            <p>{{ __("And if something wasn't clear during setup, just reply here. I'm happy to help.") }}</p>
        @elseif ($segment === 3 && $stage === 'followup')
            <p>{{ __("You started setting up your content strategy in SERFIX but didn't finish it.") }}</p>
            <p>{{ __("I'm trying to understand where we can make that experience better.") }}</p>
            <p class="question">{{ __('Was there anything specific that made you stop?') }}</p>
            <p>{{ __("Maybe something wasn't clear, it took longer than expected, you didn't see what would happen next, or you simply got busy.") }}</p>
            <p>{{ __('A one-line reply is more than enough.') }}</p>
            <p>{{ __('Thanks.') }}</p>
        @elseif ($segment === 4 && $stage === 'initial')
            <p>{{ __('Your content strategy is already underway in SERFIX.') }}</p>
            <p>{{ __('The next step is connecting your website so you can move from planning and creating content to publishing it directly from SERFIX.') }}</p>
            <p>{{ __('Right now, you can connect using:') }}</p>
            <ul>
                <li>{{ __('WordPress plugin') }}</li>
                <li>{{ __('Laravel') }}</li>
                <li>{{ __('Webhooks') }}</li>
            </ul>
            @if ($ctaUrl)
                <div class="btn-wrap"><a href="{{ $ctaUrl }}" class="btn">{{ $ctaLabel }}</a></div>
            @endif
            <p>{{ __("Once connected, you'll be able to review your content and publish without having to manually move everything between SERFIX and your website.") }}</p>
            <p>{{ __("If you're using a different CMS or something is stopping you from connecting, just reply and let me know.") }}</p>
        @elseif ($segment === 4 && $stage === 'followup')
            <p>{{ __('Quick question about your SERFIX setup.') }}</p>
            <p>{{ __("You've already started building your strategy, but your website isn't connected for publishing yet.") }}</p>
            <p class="question">{{ __('What platform is your website built on?') }}</p>
            <p>{{ __("Right now SERFIX supports WordPress, Laravel and webhooks, and we're deciding which integrations to add next.") }}</p>
            <p>{{ __("If you're already using one of those and something made the connection difficult, I'd also love to know what happened.") }}</p>
            <p>{{ __('A one-line reply is enough.') }}</p>
        @endif

        <p class="signoff">{{ __('Fuaad from SERFIX') }}</p>
    </div>

    <div class="footer">
        <p>
            {{ config('app.name') }}
            @if ($unsubscribeUrl)
                · <a href="{{ $unsubscribeUrl }}">{{ __('Unsubscribe from these emails') }}</a>
            @endif
        </p>
    </div>
</div>
</body>
</html>
