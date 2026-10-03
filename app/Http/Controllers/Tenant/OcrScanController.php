<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ocr\StoreOcrScanRequest;
use App\Models\Form;
use App\Models\OcrScan;
use App\Models\User;
use App\Services\Ocr\OcrScanService;
use Illuminate\Http\JsonResponse;

/**
 * The single-form OCR channel's two routes (M128 — groundwork 1): take a scan in, and report how reading
 * it went. JSON only: the screen that uploads and reviews is groundwork 2, and it will read exactly this.
 *
 * Session routes rather than `/api/v1`, the H14 precedent for a staff surface: a session carries no token.
 * The bearer twins the architecture names are a filed row.
 *
 * ⚠️ `{scan}` IS LOOKED UP UNDER ITS FORM HERE, NOT BY `scopeBindings()`. Laravel would resolve a scoped
 * child through a `scans()` relationship on `Form`, which does not exist, and a scan id from another form
 * — or, under RLS, another workspace — must answer 404 either way. The `where('form_id', …)` below is that
 * scope, stated once.
 */
final class OcrScanController extends Controller
{
    public function store(StoreOcrScanRequest $request, Form $form, OcrScanService $service): JsonResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        $scan = $service->store($form, $user, $request->pages());

        return response()->json(['data' => $this->present($scan)], 202);
    }

    public function show(Form $form, string $scan): JsonResponse
    {
        $row = OcrScan::query()->where('form_id', $form->id)->whereKey($scan)->firstOrFail();

        return response()->json(['data' => $this->present($row)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(OcrScan $scan): array
    {
        return [
            'id' => $scan->id,
            'status' => $scan->status->value,
            'pages' => count($scan->pages),
            'form_version_id' => $scan->form_version_id,
            'extraction' => $scan->extraction,
            'error' => $scan->error_code === null ? null : ['code' => $scan->error_code, 'message' => $scan->error_message],
            'created_at' => $scan->created_at->toIso8601String(),
            'read_at' => $scan->read_at?->toIso8601String(),
        ];
    }
}
