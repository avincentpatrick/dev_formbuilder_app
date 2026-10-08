<?php

declare(strict_types=1);

use App\Exceptions\Ocr\OcrProviderException;
use App\Services\Ocr\GoogleVisionClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| M128 — the one class that talks to Cloud Vision.
|--------------------------------------------------------------------------
| Two of the bodies below are REAL: Google's answers on 2026-10-03 to the platform's own key (billing off)
| and to a wrong key, with the project number zeroed. They are why the reason decides before the status —
| a wrong key comes back as a 400, which a status-first map would call an unreadable FILE.
*/

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('ocr.google_vision.key', 'test-key-not-real');
});

/** @return array<string, mixed> */
function visionClientFixture(string $name): array
{
    $decoded = json_decode((string) file_get_contents(base_path("tests/fixtures/ocr/{$name}")), true);

    return is_array($decoded) ? $decoded : [];
}

/** The provider failure a call raised, or null when it did not raise one. */
function visionClientFailure(Closure $call): ?OcrProviderException
{
    try {
        $call();
    } catch (OcrProviderException $e) {
        return $e;
    }

    return null;
}

it('sends a photo to images:annotate with the key in a header and never in the URL', function (): void {
    Http::fake(['vision.googleapis.com/*' => Http::response(['responses' => [['fullTextAnnotation' => ['pages' => []]]]])]);

    $answer = app(GoogleVisionClient::class)->annotate('jpeg-bytes', 'image/jpeg');

    expect($answer)->toHaveKey('responses');
    Http::assertSent(static fn (Request $r): bool => $r->url() === 'https://vision.googleapis.com/v1/images:annotate'
        && $r->hasHeader('X-Goog-Api-Key', 'test-key-not-real')
        && ! str_contains($r->url(), 'key=')
        && $r['requests'][0]['image']['content'] === base64_encode('jpeg-bytes')
        && $r['requests'][0]['features'][0]['type'] === 'DOCUMENT_TEXT_DETECTION');
});

it('sends a PDF inline to files:annotate, naming its first five pages', function (): void {
    Http::fake(['vision.googleapis.com/*' => Http::response(['responses' => [['responses' => [], 'totalPages' => 1]]])]);

    app(GoogleVisionClient::class)->annotate('%PDF-bytes', 'application/pdf');

    Http::assertSent(static fn (Request $r): bool => $r->url() === 'https://vision.googleapis.com/v1/files:annotate'
        && $r['requests'][0]['inputConfig']['mimeType'] === 'application/pdf'
        && $r['requests'][0]['pages'] === [1, 2, 3, 4, 5]);
});

it('asks for handwriting in its language hint, for a photo and a PDF alike (M149)', function (): void {
    // The bake-off's thirty pages, read again with the hint: 60.0% → 54.4% of fields needing correction, one silent
    // error fewer, and no more Cyrillic letters in Tagalog answers — the printed labels were all still found.
    Http::fake(['vision.googleapis.com/*' => Http::response(['responses' => [['fullTextAnnotation' => ['pages' => []], 'responses' => [], 'totalPages' => 1]]])]);

    app(GoogleVisionClient::class)->annotate('jpeg-bytes', 'image/jpeg');
    app(GoogleVisionClient::class)->annotate('%PDF-bytes', 'application/pdf');

    Http::assertSentCount(2);
    Http::assertSent(static fn (Request $r): bool => str_ends_with($r->url(), '/images:annotate')
        && ($r['requests'][0]['imageContext']['languageHints'] ?? null) === ['en-t-i0-handwrit']);
    Http::assertSent(static fn (Request $r): bool => str_ends_with($r->url(), '/files:annotate')
        && ($r['requests'][0]['imageContext']['languageHints'] ?? null) === ['en-t-i0-handwrit']);
});

it('sends nothing at all when no key is configured', function (): void {
    config()->set('ocr.google_vision.key', '');
    Http::fake();

    $failure = visionClientFailure(static fn () => app(GoogleVisionClient::class)->annotate('x', 'image/png'));

    expect($failure?->errorCode)->toBe('provider_not_configured')
        ->and($failure?->retryable)->toBeFalse();
    Http::assertNothingSent();
});

it('names billing as the fix when the project has none — the real answer', function (): void {
    Http::fake(['vision.googleapis.com/*' => Http::response(visionClientFixture('vision-403-billing-disabled.json'), 403)]);

    $failure = visionClientFailure(static fn () => app(GoogleVisionClient::class)->annotate('x', 'image/png'));

    expect($failure?->errorCode)->toBe('provider_billing_disabled')
        ->and($failure?->retryable)->toBeFalse()
        ->and($failure?->getMessage())->toContain('enable billing');
});

it('reads a wrong key as a credential failure although it arrives as a 400 — the real answer', function (): void {
    Http::fake(['vision.googleapis.com/*' => Http::response(visionClientFixture('vision-400-api-key-invalid.json'), 400)]);

    $failure = visionClientFailure(static fn () => app(GoogleVisionClient::class)->annotate('x', 'image/png'));

    expect($failure?->errorCode)->toBe('provider_unauthorized')
        ->and($failure?->retryable)->toBeFalse();
});

it('reads a 400 with no credential reason as a file the provider could not open', function (): void {
    Http::fake(['vision.googleapis.com/*' => Http::response(['error' => ['code' => 400, 'message' => 'Bad image data.', 'status' => 'INVALID_ARGUMENT']], 400)]);

    $failure = visionClientFailure(static fn () => app(GoogleVisionClient::class)->annotate('x', 'image/png'));

    expect($failure?->errorCode)->toBe('unreadable_file')
        ->and($failure?->getMessage())->toContain('Bad image data.');
});

it('treats rate limiting, an outage and no answer in time as worth retrying', function (int|string $status): void {
    if ($status === 'timeout') {
        Http::fake(static fn () => throw new ConnectionException('timed out'));
    } else {
        Http::fake(['vision.googleapis.com/*' => Http::response(['error' => ['code' => $status]], $status)]);
    }

    $failure = visionClientFailure(static fn () => app(GoogleVisionClient::class)->annotate('x', 'image/png'));

    expect($failure?->errorCode)->toBe('provider_unavailable')
        ->and($failure?->retryable)->toBeTrue();
})->with([429, 500, 503, 'timeout']);

it('reads an error the provider embeds inside a 200, per page', function (int $code, string $expected): void {
    Http::fake(['vision.googleapis.com/*' => Http::response(['responses' => [['responses' => [
        ['fullTextAnnotation' => ['pages' => []]],
        ['error' => ['code' => $code, 'message' => 'page trouble']],
    ]]]])]);

    $failure = visionClientFailure(static fn () => app(GoogleVisionClient::class)->annotate('%PDF', 'application/pdf'));

    expect($failure?->errorCode)->toBe($expected);
})->with([
    'invalid argument' => [3, 'unreadable_file'],
    'unavailable' => [14, 'provider_unavailable'],
    'permission denied' => [7, 'provider_unauthorized'],
]);
