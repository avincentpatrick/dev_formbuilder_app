<?php

declare(strict_types=1);

namespace App\Http\Requests\Ocr;

use App\Services\Ocr\OcrScanConfirmation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Saving a reviewed scan (M129 — single-form OCR groundwork 2). Authorization is the scan routes' gates
 * (`can:create` on a submission for the form, then the module and the plan). This request asserts only the
 * coarse shape, `answers` as a key⇒value map; every real check is the pipeline's, as on manual encoding.
 *
 * ⚠️ `answers` AND NOTHING ELSE, which is the difference from `EncodeSubmissionRequest`. That request takes a
 * caller-chosen `client_submission_uuid` and a draft baseline. Here the server owns the idempotency key — it is
 * the scan's own id ({@see OcrScanConfirmation}) — and there is no draft, so accepting either would be a
 * second identifier for a request whose subject the URL already names.
 */
final class ConfirmOcrScanRequest extends FormRequest
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
        return [
            'answers' => ['present', 'array'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function answers(): array
    {
        $answers = $this->input('answers', []);

        return is_array($answers) ? $answers : [];
    }
}
