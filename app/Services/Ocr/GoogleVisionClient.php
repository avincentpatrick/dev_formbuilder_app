<?php

declare(strict_types=1);

namespace App\Services\Ocr;

use App\Exceptions\Ocr\OcrProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cloud Vision over REST with the platform's API key (M128). The only class that talks to the provider;
 * it returns the decoded answer and leaves meaning to {@see VisionDocumentParser} and the matcher.
 *
 * ── THE KEY GOES IN A HEADER, NEVER IN THE URL ──────────────────────────────────────────────────────
 * Google accepts `?key=` or `X-Goog-Api-Key`. The query form puts the credential into the URL, and a URL
 * is what an HTTP client exception, a proxy and a log line all print. The header form keeps it out of all
 * three; `ReadOcrScanJobTest` asserts the URL carries no key.
 *
 * ── ONE REQUEST, ONE FILE ───────────────────────────────────────────────────────────────────────────
 * A photo goes to `images:annotate`; a PDF goes to `files:annotate` with its content inline, which the
 * provider allows for at most five pages, so the PDF's first five are named explicitly. The reading job
 * calls this once per stored page file, so one call is the unit of a job run.
 *
 * Every failure becomes an {@see OcrProviderException} with a code and a message a reviewer can act on;
 * nothing here retries, because the job owns retrying and counts it on the scan row.
 */
final class GoogleVisionClient
{
    private const string FEATURE = 'DOCUMENT_TEXT_DETECTION';

    /**
     * `google.rpc.ErrorInfo` reasons that mean "this server's credential or project setup is wrong", which
     * arrive under several HTTP statuses — `API_KEY_INVALID` as a 400 (measured). None of them is about
     * the file.
     *
     * @var list<string>
     */
    private const array CREDENTIAL_REASONS = [
        'API_KEY_INVALID',
        'API_KEY_EXPIRED',
        'API_KEY_SERVICE_BLOCKED',
        'API_KEY_HTTP_REFERRER_BLOCKED',
        'API_KEY_IP_ADDRESS_BLOCKED',
        'SERVICE_DISABLED',
        'CONSUMER_INVALID',
    ];

    /**
     * @return array<string, mixed> the decoded answer
     *
     * @throws OcrProviderException
     */
    public function annotate(string $bytes, string $mime): array
    {
        $key = (string) config('ocr.google_vision.key');
        if ($key === '') {
            throw OcrProviderException::notConfigured();
        }

        $endpoint = rtrim((string) config('ocr.google_vision.endpoint'), '/');
        $features = [['type' => self::FEATURE]];

        if ($mime === 'application/pdf') {
            $url = $endpoint.'/files:annotate';
            $body = ['requests' => [[
                'inputConfig' => ['content' => base64_encode($bytes), 'mimeType' => 'application/pdf'],
                'features' => $features,
                'pages' => range(1, max(1, (int) config('ocr.upload.max_pages', 5))),
            ]]];
        } else {
            $url = $endpoint.'/images:annotate';
            $body = ['requests' => [[
                'image' => ['content' => base64_encode($bytes)],
                'features' => $features,
            ]]];
        }

        try {
            $response = Http::withHeaders(['X-Goog-Api-Key' => $key])
                ->withOptions(['allow_redirects' => false])
                ->acceptJson()
                ->connectTimeout((int) config('ocr.google_vision.connect_timeout', 5))
                ->timeout((int) config('ocr.google_vision.timeout', 25))
                ->post($url, $body);
        } catch (ConnectionException) {
            throw OcrProviderException::unavailable('no answer in time');
        }

        $decoded = $response->json();
        if (! is_array($decoded)) {
            throw $response->successful()
                ? OcrProviderException::unavailable('an answer that was not JSON')
                : $this->refusal($response, []);
        }

        /** @var array<string, mixed> $decoded */
        if (! $response->successful()) {
            throw $this->refusal($response, $decoded);
        }

        $this->assertNoEmbeddedError($decoded);

        return $decoded;
    }

    /**
     * Map a refused request to a coded failure.
     *
     * ⛔ THE PROVIDER'S `reason` DECIDES BEFORE THE HTTP STATUS DOES, AND THAT ORDER IS MEASURED. A wrong
     * or revoked key is answered `400 INVALID_ARGUMENT` with reason `API_KEY_INVALID` (probed 2026-10-03),
     * not a 401 — so a status-first mapping would tell every user their FILE is unreadable and to rescan it,
     * when the fix is a server setting. Billing off is `403` with reason `BILLING_DISABLED` (probed the same
     * day against the platform's own key).
     *
     * @param  array<string, mixed>  $decoded
     */
    private function refusal(Response $response, array $decoded): OcrProviderException
    {
        $status = $response->status();
        $reason = $this->reasonOf($decoded);

        if ($reason === 'BILLING_DISABLED') {
            return OcrProviderException::billingDisabled();
        }

        if ($reason !== null && in_array($reason, self::CREDENTIAL_REASONS, true)) {
            return OcrProviderException::unauthorized($status);
        }

        if ($status === 429 || $status >= 500) {
            return OcrProviderException::unavailable("HTTP {$status}");
        }

        if ($status === 401 || $status === 403) {
            return OcrProviderException::unauthorized($status);
        }

        return OcrProviderException::unreadableFile($this->messageOf($decoded) ?? "HTTP {$status}");
    }

    /**
     * A 200 can still carry a per-request `error` — the batch endpoints report one per file and per page.
     * The codes are google.rpc ones: 3 is INVALID_ARGUMENT (the file), 4/8/14 are deadline, quota and
     * unavailable (worth retrying), and 7/16 are permission and authentication.
     *
     * @param  array<string, mixed>  $decoded
     *
     * @throws OcrProviderException
     */
    private function assertNoEmbeddedError(array $decoded): void
    {
        $responses = $decoded['responses'] ?? null;
        $first = is_array($responses) && is_array($responses[0] ?? null) ? $responses[0] : [];

        $errors = [$first['error'] ?? null];
        $inner = $first['responses'] ?? null;
        if (is_array($inner)) {
            foreach ($inner as $page) {
                $errors[] = is_array($page) ? ($page['error'] ?? null) : null;
            }
        }

        foreach ($errors as $error) {
            if (! is_array($error)) {
                continue;
            }

            $code = is_int($error['code'] ?? null) ? $error['code'] : 0;
            $message = is_string($error['message'] ?? null) ? $error['message'] : "provider error {$code}";

            throw match (true) {
                in_array($code, [4, 8, 14], true) => OcrProviderException::unavailable($message),
                in_array($code, [7, 16], true) => OcrProviderException::unauthorized(403),
                default => OcrProviderException::unreadableFile($message),
            };
        }
    }

    /**
     * The `ErrorInfo.reason` of a google.rpc error body — `BILLING_DISABLED`, `API_KEY_INVALID`, and so on.
     *
     * @param  array<string, mixed>  $decoded
     */
    private function reasonOf(array $decoded): ?string
    {
        $error = $decoded['error'] ?? null;
        $details = is_array($error) ? ($error['details'] ?? null) : null;
        if (! is_array($details)) {
            return null;
        }

        foreach ($details as $detail) {
            if (is_array($detail) && is_string($detail['reason'] ?? null)) {
                return $detail['reason'];
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $decoded */
    private function messageOf(array $decoded): ?string
    {
        $error = $decoded['error'] ?? null;
        $message = is_array($error) ? ($error['message'] ?? null) : null;

        return is_string($message) && $message !== '' ? $message : null;
    }
}
