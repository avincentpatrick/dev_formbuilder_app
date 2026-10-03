<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

use App\Services\Forms\FormService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Let a form accept scans of its printed paper, or stop it (M129 — single-form OCR groundwork 2).
 *
 * Its own request — deliberately NOT folded into {@see FormMetadataRequest} — mirroring the
 * {@see UpdateSaveResumeRequest}, {@see UpdatePageModeRequest} and {@see AssignFormScopeRequest} precedents:
 * the write is a dedicated {@see FormService::setOcrScanning} and never mass-assignment, because
 * `allow_ocr_single` IS in `Form::$fillable`.
 *
 * The workspace's half of the gate is at the route, and in save-and-resume's shape plus one: `can:update,form`,
 * then the `ocr_single` module toggle BEFORE the plan, so a workspace that switched scanning off is told so
 * rather than told to upgrade — the order the scan routes themselves use.
 *
 * ⚠️ The payload key is the COLUMN name while the request, route and section are named for the SETTING, for
 * `UpdatePageModeRequest`'s reason: the rule, the service and the column then read as one.
 */
final class UpdateOcrScanningRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // route middleware (can:update,form, module:ocr_single, feature:ocr_single) owns authorization
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'allow_ocr_single' => ['required', 'boolean'],
        ];
    }
}
