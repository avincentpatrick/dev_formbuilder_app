<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Services\Feedback\FeedbackLifecycle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * A message in a feedback report's thread, with an optional picture (M179, `R-129c44c3`, `D151`) — the one
 * request behind It works, Still a problem and Add a comment.
 *
 * The body is `nullable` here on purpose: whether a move NEEDS words is the lifecycle's rule (It works may say
 * nothing; Still a problem and a comment must), kept in one place so a single mutation can turn it red. The
 * length cap is shared with the lifecycle ({@see FeedbackLifecycle::BODY_MAX}). The picture is held to the
 * Feedback button's own rules (`attachments.feedback_screenshot`), and re-sniffed when it is stored.
 */
final class FeedbackThreadRequest extends FormRequest
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
            'body' => ['nullable', 'string', 'max:'.FeedbackLifecycle::BODY_MAX],
            'screenshot' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:'.$maxKb],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.max' => 'Keep it to '.number_format(FeedbackLifecycle::BODY_MAX).' characters.',
            'screenshot.mimes' => 'A screenshot must be a PNG, JPEG or WebP image.',
            'screenshot.max' => 'That screenshot is too large. Try capturing a single tab rather than the whole screen.',
        ];
    }

    public function body(): ?string
    {
        $body = $this->validated('body');

        return is_string($body) ? $body : null;
    }

    public function screenshot(): ?UploadedFile
    {
        $file = $this->file('screenshot');

        return $file instanceof UploadedFile ? $file : null;
    }
}
