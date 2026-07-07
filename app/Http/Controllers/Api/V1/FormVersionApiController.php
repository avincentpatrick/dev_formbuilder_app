<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\FormVersionStatus;
use App\Exceptions\Forms\FormException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PublishVersionRequest;
use App\Http\Resources\Api\V1\FormVersionResource;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\Forms\PublishService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Form version inspection + publish over /api/v1 (Increment E). Versions are scope-bound to their form
 * (a version of another form 404s); publish reuses {@see PublishService} verbatim — the FormException /
 * PublishValidationException it throws map to the 422 error envelope in bootstrap/app.php.
 */
final class FormVersionApiController extends Controller
{
    /**
     * Cursor-paginated version history for a form, newest version first.
     */
    public function index(Request $request, Form $form): JsonResponse
    {
        $limit = min(max($request->integer('limit', 25), 1), 100);

        $versions = $form->versions()
            ->orderByDesc('version_number')
            ->orderByDesc('id')
            ->cursorPaginate($limit);

        return response()->json([
            'data' => FormVersionResource::collection($versions->getCollection()),
            'meta' => [
                'next_cursor' => $versions->nextCursor()?->encode(),
                'has_more' => $versions->hasMorePages(),
            ],
        ]);
    }

    /**
     * Fetch a single form version, including its immutable schema snapshot.
     */
    public function show(Form $form, FormVersion $version): FormVersionResource
    {
        return FormVersionResource::make($version);
    }

    /**
     * Publish the form's current draft. The URL names the draft version; a non-draft {version} is rejected
     * before delegating to the 9-step publish transaction.
     */
    public function publish(PublishVersionRequest $request, Form $form, FormVersion $version, PublishService $publisher): FormVersionResource
    {
        if ($version->status !== FormVersionStatus::Draft) {
            throw FormException::versionNotPublishable();
        }

        /** @var User $user */
        $user = $request->user();

        $note = $request->string('note')->toString();

        $published = $publisher->publish($form, $user, $note !== '' ? $note : null);

        return FormVersionResource::make($published);
    }
}
