{{--
    The Meridian transactional-email template (H23a4, ADR-0014 §D8) — the top-level markdown view every
    outbound notification renders through, in place of the framework's `notifications::email`.

    ── WHY WE OWN THIS FILE RATHER THAN PUBLISHING THE FRAMEWORK'S ─────────────────────────────────
    A Blade component does NOT inherit the calling view's variables: `Factory::renderComponent()` renders
    with `componentData()` alone. So `$brand` reaches `<x-mail::layout>` and `<x-mail::header>` only if it
    is passed AT THE TAG — and the only way to control those tags is to own the view that writes them.
    Publishing `notifications::email` would work equally well and cost an extra vendor file to keep in sync
    on every Laravel upgrade; this is the same content under our own name.

    Going straight to `<x-mail::layout>` rather than through `<x-mail::message>` is what removes the need
    to publish `message.blade.php` too — that component's only job is to supply the header and footer slots
    we are replacing anyway.

    The BODY below is `notifications::email` copied verbatim, deliberately: all eight notifications build a
    stock MailMessage with `->subject()/->line()/->action()`, and the framework's greeting/intro/action/
    outro/salutation/subcopy structure is exactly what renders those. Diverging from it here would silently
    change the wording of the two auth emails, whose whole reason for subclassing the framework's
    notifications is to reuse its localized strings.

    $brand is guaranteed present: CarriesTenantBrand::branded() substitutes BrandPalette::product() when a
    dispatch site supplied none.
--}}
<x-mail::layout>
{{-- Header — the tenant's name, or the product's when unbranded (H23a4). --}}
<x-slot:header>
<x-mail::header :url="$brand['url']" :logo="$brand['logo_url']">
{{ $brand['name'] }}
</x-mail::header>
</x-slot:header>

{{-- Greeting --}}
@if (! empty($greeting))
# {{ $greeting }}
@else
@if ($level === 'error')
# @lang('Whoops!')
@else
# @lang('Hello!')
@endif
@endif

{{-- Intro Lines --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Action Button --}}
@isset($actionText)
<?php
    $color = match ($level) {
        'success', 'error' => $level,
        default => 'primary',
    };
?>
<x-mail::button :url="$actionUrl" :color="$color">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Outro Lines --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach

{{-- Salutation --}}
@if (! empty($salutation))
{{ $salutation }}
@else
@lang('Regards,')<br>
{{ $brand['name'] }}
@endif

{{-- Subcopy --}}
@isset($actionText)
<x-slot:subcopy>
<x-mail::subcopy>
@lang(
    "If you're having trouble clicking the \":actionText\" button, copy and paste the URL below\n".
    'into your web browser:',
    [
        'actionText' => $actionText,
    ]
) <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{--
    Footer — the attribution line is NOT decoration. A branded tenant's email carries the tenant's name in
    the header while the From address stays the platform's (per-tenant From needs per-domain DKIM/SPF,
    which ADR-0014 files under "full white-label — not addressed here"). A branded header over an
    unexplained platform sender reads as a spoof; naming the sender is what makes the split honest.
--}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ $brand['name'] }}. {{ __('All rights reserved.') }}
@if ($brand['name'] !== config('app.name'))

{{ __('Sent via :app.', ['app' => config('app.name')]) }}
@endif
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
