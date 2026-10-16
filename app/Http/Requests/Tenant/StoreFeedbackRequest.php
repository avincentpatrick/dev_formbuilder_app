<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Enums\FeedbackType;
use App\Services\Attachments\AttachmentStorageService;
use App\Services\Feedback\FeedbackService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Validates one in-app feedback submission (PRD Feature #11, Increment I7a) — extracted from the
 * controller's inline `$request->validate()` when the payload gained a file and stopped being three
 * scalars. Authorization is the route middleware's concern (`can:feedback.submit`, held by every role),
 * so this request only shape-validates.
 *
 * ── THE `mimes:` RULE IS NOT THE SECURITY BOUNDARY, AND MUST NOT BE MISTAKEN FOR IT ──────────────────────
 * Laravel's `mimes:` guesses from the file's contents, which is genuine — but the allowlist that decides
 * what may reach storage lives in {@see AttachmentStorageService::storeFeedbackScreenshot()}, sniffed
 * again from the bytes, because that is the single place every upload channel passes through
 * (threat-model §6). This rule exists to give the reporter an inline field error instead of a thrown
 * exception. **SVG is excluded in both places**: this image is rendered back into the platform operator's
 * own console, same-origin on the central host, which is the highest-value stored-XSS target in the
 * product.
 *
 * `max:` is in KILOBYTES (Laravel's unit) and mirrors `config('attachments.feedback_screenshot.max_bytes')`;
 * the service re-checks the byte value independently, since the client's downscale is advice, not
 * enforcement.
 *
 * ── M175 (`R-2ce2f274`): THE KIND IS REQUIRED, AND THE PAGE ADDRESS MUST BE A WEB ADDRESS ────────────────────
 * `type` is one of {@see FeedbackType}. `page_url` is rendered as a LINK on the tenant list and in the
 * operator's console, so only `http`/`https` is accepted — a `javascript:` or `data:` address stored here would
 * run on a click. `browser_info` keys need a rule each or Laravel drops them; `app_version`/`app_commit` get none
 * on purpose, because the SERVER stamps them ({@see FeedbackService::submit()}).
 */
final class StoreFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKb = (int) (((int) config('attachments.feedback_screenshot.max_bytes')) / 1024);

        return [
            'type' => ['required', Rule::enum(FeedbackType::class)],
            'route' => ['required', 'string', 'max:255'],
            'page_url' => ['nullable', 'string', 'max:2048', 'url:http,https'],
            'page_title' => ['nullable', 'string', 'max:255'],
            'remarks' => ['required', 'string', 'max:5000'],
            'browser_info' => ['nullable', 'array'],
            'browser_info.userAgent' => ['nullable', 'string', 'max:500'],
            'browser_info.viewport' => ['nullable', 'string', 'max:50'],
            'browser_info.component' => ['nullable', 'string', 'max:100'],
            'browser_info.bundle' => ['nullable', 'string', 'max:40'],
            'screenshot' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:'.$maxKb],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required' => 'Choose what kind of feedback this is: a bug, a suggestion or a question.',
            'type.enum' => 'Choose what kind of feedback this is: a bug, a suggestion or a question.',
            'page_url.url' => 'The page address must be a web address.',
            'screenshot.mimes' => 'A screenshot must be a PNG, JPEG or WebP image.',
            'screenshot.max' => 'That screenshot is too large. Try capturing a single tab rather than the whole screen.',
        ];
    }

    /**
     * The validated scalars, without the file.
     *
     * @return array{type: string, route: string, remarks: string, page_url?: string|null, page_title?: string|null, browser_info?: array<string, mixed>|null}
     */
    public function payload(): array
    {
        /** @var array{type: string, route: string, remarks: string, page_url?: string|null, page_title?: string|null, browser_info?: array<string, mixed>|null} $data */
        $data = $this->safe()->only(['type', 'route', 'page_url', 'page_title', 'remarks', 'browser_info']);

        return $data;
    }

    public function screenshot(): ?UploadedFile
    {
        $file = $this->file('screenshot');

        return $file instanceof UploadedFile ? $file : null;
    }
}
