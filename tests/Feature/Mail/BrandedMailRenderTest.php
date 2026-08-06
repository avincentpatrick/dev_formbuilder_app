<?php

declare(strict_types=1);

use App\Notifications\Concerns\CarriesTenantBrand;
use App\Support\Branding\BrandPalette;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The Meridian mail template layer, proved by RENDERING it (H23a4, ADR-0014 §D8).
 *
 * **These are the first assertions in this repository that render an email to HTML.** Every existing mail
 * test is `Notification::fake()` + `assertSentOnDemand`, which never calls `toMail()` at all — so before
 * this file, nothing in the build would have noticed a mail template that threw, a theme that failed to
 * resolve, or a brand colour that never reached the markup. All three are silent-in-production failures:
 * the queue swallows them and the recipient just receives nothing, or something grey.
 *
 * The assertions are deliberately on the INLINED output, not on the theme file. ADR-0014 §D8's whole
 * argument is that mail clients ignore `<style>` and `var()`, so "the hex appears in the stylesheet" would
 * prove nothing about what a recipient sees — `CssToInlineStyles` has to have moved it onto the element.
 *
 * A local stub notification rather than a real one: this file's subject is the TEMPLATE, and it should keep
 * passing when the eight real notifications change their wording. Their wiring is asserted separately in
 * `QueuedMailContractTest`.
 */
final class BrandedStubNotification extends Notification
{
    use CarriesTenantBrand;

    public function __construct(private readonly bool $withAction = true) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Stub subject')
            ->line('First intro line.');

        if ($this->withAction) {
            $mail->action('Do the thing', 'https://acme.meridian.test/dashboard');
        }

        return $this->branded($mail->line('An outro line.'));
    }
}

/**
 * @param  array<string, string>  $brand
 */
function renderStubMail(array $brand = [], bool $withAction = true): string
{
    $notification = new BrandedStubNotification($withAction);

    if ($brand !== []) {
        $notification->withBrand($brand);
    }

    return (string) $notification->toMail(new stdClass)->render();
}

/**
 * A branded palette that is nothing like Blueprint, so no assertion below can pass by accident on the
 * product default.
 *
 * @return array<string, string>
 */
function stubBrandPalette(): array
{
    return [
        'name' => 'Acme Health',
        'url' => 'https://acme.meridian.test/dashboard',
        'logo_url' => 'https://acme.meridian.test/branding/logo',
        'bg' => '#7A2E6B',
        'bg_hover' => '#5F2453',
        'bg_active' => '#471B3E',
        'fg' => '#7A2E6B',
        'tint' => '#F6EAF3',
        'ring' => '#5F2453',
    ];
}

it('inlines the tenant fill onto the action button', function (): void {
    $html = renderStubMail(stubBrandPalette());

    // The button is an <a class="button button-primary">; after inlining it must carry the fill as an
    // INLINE style. Asserting the class alone would pass even if the theme never resolved.
    expect($html)->toContain('background-color: #7A2E6B')
        ->and($html)->toContain('Do the thing')
        ->and($html)->not->toContain('#1C4B72');
});

it('keeps white on the brand fill rather than reaching for the fg role', function (): void {
    $html = renderStubMail(stubBrandPalette());

    // In the LIGHT ramp the generator aliases fg to bg, so a template that used `fg` for button text would
    // paint the fill colour on the fill. The ramp measures white-on-fill and nothing else.
    //
    // The inliner preserves the case it was given, so these assertions match the theme's own casing — a
    // `strtolower()` here would pass today only by accident of how CssToInlineStyles happens to normalise.
    expect($html)->toContain('color: '.BrandPalette::ON_BRAND);
});

it('inlines the tenant tint onto a panel and leaves neutrals alone', function (): void {
    $html = renderStubMail(stubBrandPalette());

    // ADR-0014 §D7 carried into mail: the brand paints actions, never the page. The canvas and the card
    // stay Meridian neutrals no matter what the tenant picked.
    expect($html)->toContain('#F3F4F1')   // --mds-neutral-50, the wrapper canvas
        ->and($html)->toContain('#DDE1DA'); // --mds-color-border-default
});

it('renders the tenant name and logo in the header', function (): void {
    $html = renderStubMail(stubBrandPalette());

    expect($html)->toContain('src="https://acme.meridian.test/branding/logo"')
        ->and($html)->toContain('alt="Acme Health"')
        ->and($html)->toContain('href="https://acme.meridian.test/dashboard"');
});

it('names the platform as the sender when the header carries a tenant name', function (): void {
    // The From address stays the platform's (per-tenant From needs per-domain DKIM/SPF — ADR-0014's
    // "full white-label, not addressed here"), so an unexplained tenant header would read as a spoof.
    expect(renderStubMail(stubBrandPalette()))->toContain('Sent via '.config('app.name'));
});

it('falls back to the product palette when no brand was carried', function (): void {
    // The worker path that matters: a dispatch site that never called withBrand() must still send a
    // correct, Meridian-themed email rather than throwing "undefined variable $brand" on a queue worker.
    $html = renderStubMail();

    expect($html)->toContain('background-color: '.BrandPalette::PRODUCT['bg'])
        ->and($html)->toContain((string) config('app.name'))
        ->and($html)->not->toContain('<img src=')          // no logo when unbranded
        ->and($html)->not->toContain('Sent via ');          // and no attribution to disambiguate
});

it('renders without an action button', function (): void {
    // Three of the eight notifications send no action link at all; the subcopy block is @isset-guarded on
    // the same variable, so a template that assumed one would fatal for exactly those three.
    $html = renderStubMail(stubBrandPalette(), withAction: false);

    expect($html)->toContain('An outro line.')
        ->and($html)->not->toContain('button-primary');
});

it('renders markdown link syntax in an interpolated name as inert text', function (): void {
    // security-threat-model.md §5's markdown-mail row, closed by H23a4 (`Markdown::withSecuredEncoding()`
    // in AppServiceProvider). Script was already blocked incidentally — htmlspecialchars runs before
    // CommonMark — but `[` was live, so a tenant named `[Reset your password](https://evil.example)` would
    // have rendered a WORKING phishing link inside a platform-branded email, and the `![…]` form a remote
    // tracking pixel. Both vectors need `[`.
    $brand = stubBrandPalette();
    $brand['name'] = '[Reset your password](https://evil.example)';

    $html = renderStubMail($brand);

    expect($html)->not->toContain('href="https://evil.example"')
        ->and($html)->not->toContain('src="https://evil.example')
        // The text still reaches the reader — this is escaping, not stripping.
        ->and($html)->toContain('Reset your password');
});

it('resolves the meridian theme and not the framework default', function (): void {
    $html = renderStubMail(stubBrandPalette());

    // #18181b is the framework default theme's link/heading colour and appears nowhere in Meridian's
    // palette — the cheapest possible proof that `->theme('meridian')` actually took effect.
    expect($html)->not->toContain('#18181b')
        ->and($html)->not->toContain('#52525b');
});
