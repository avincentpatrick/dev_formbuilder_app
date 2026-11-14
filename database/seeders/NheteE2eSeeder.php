<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Form;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Xlsform\XlsformImporter;
use App\Services\Xlsform\XlsformWorkbookWriter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Nhete\NhetePlan;
use Tests\Support\Nhete\NheteWorkbook;

/**
 * M239 (`nhete-browser-walk`) — the NHETE pre-work module, published as a stepped guest form at `/f/nhete`, for
 * `tests/e2e/nhete-walk.spec.ts`.
 *
 * ⛔ ITS OWN SEEDER, NEVER A BLOCK INSIDE {@see E2eSeeder}. That seeder runs ten times in every Pest suite
 * (`AuthScanFixturesTest` five, `E2eSeederIdempotencyTest` five), and the second pins the number of published forms; an
 * 808-question import would slow every one of those runs and move that count. CI's E2E job runs this straight after it,
 * in the same step, and nothing else does.
 *
 * Built by the importer from the Meridian-dialect workbook (`NheteWorkbook`), the route `M237` measured publishing.
 * One section per screen, stepped (`single_page_mode` off), so the guest app shows one screen per page, as the module
 * was designed; save-and-resume on, so the walk can leave and come back.
 *
 * Needs `E2eSeeder`'s tenant and owner, and refuses rather than inventing them. Skips when a form already holds the
 * slug, so a re-seed is a no-op. Not by title, as that seeder's blocks are: the import renames the form to the
 * workbook's `form_title`.
 */
final class NheteE2eSeeder extends Seeder
{
    public const SLUG = 'nhete';

    private const OWNER_EMAIL = 'demo@meridian.test';

    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        $tenant = Tenant::query()->where('slug', 'acme')->first()
            ?? throw new RuntimeException('NheteE2eSeeder needs the acme tenant: run E2eSeeder first.');
        $owner = $this->owner();

        $plan = new NhetePlan;
        $sheets = (new NheteWorkbook($plan, NheteWorkbook::MERIDIAN))->sheets();
        $stub = tempnam(sys_get_temp_dir(), 'nhete-seed');
        if ($stub === false) {
            throw new RuntimeException('NheteE2eSeeder could not create a temporary file.');
        }
        $path = $stub.'.xlsx';
        rename($stub, $path);

        try {
            file_put_contents($path, app(XlsformWorkbookWriter::class)->writeToString($sheets));
            $workbook = new UploadedFile($path, 'nhete.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

            DB::transaction(function () use ($tenant, $owner, $workbook, $plan): void {
                TenantContext::applyLocal((string) $tenant->id, $owner->id);
                app(PermissionRegistrar::class)->setPermissionsTeamId((string) $tenant->id);

                if (Form::withTrashed()->where('public_slug', self::SLUG)->exists()) {
                    return;
                }

                $form = app(FormService::class)->create($tenant, $owner, $plan->title());
                app(XlsformImporter::class)->import($form, $workbook, $owner);
                app(PublishService::class)->publish($form->refresh(), $owner);

                $form->update([
                    // `xlsform-default-language-name`: the import stores the workbook's `default_language` NAME
                    // ("English") as the locale, and the guest page then writes `<html lang="English">`, which axe
                    // refuses on every page. Set to the code here, so the walk measures the respondent app, not
                    // the import; `NheteXlsformImportTest` pins what the import stores until the row is fixed.
                    'default_locale' => 'en',
                    'public_slug' => self::SLUG,
                    'allow_guest_submissions' => true,
                    'save_and_resume' => true,
                    'single_page_mode' => false,
                ]);
            });
        } finally {
            @unlink($path);
        }
    }

    /**
     * E2eSeeder's owner. The auth connection sees every user, which is how that seeder resolves them; the default
     * connection sees only what RLS lets the current context see, which is how a Pest case that has entered the
     * tenant as the owner hands it over (its user sits in an uncommitted transaction the auth connection cannot see).
     */
    private function owner(): User
    {
        $owner = User::on('pgsql_auth')->where('email', self::OWNER_EMAIL)->first();
        if ($owner !== null) {
            $owner->setConnection((string) config('database.default'));

            return $owner;
        }

        return User::query()->where('email', self::OWNER_EMAIL)->first()
            ?? throw new RuntimeException('NheteE2eSeeder needs '.self::OWNER_EMAIL.': run E2eSeeder first.');
    }
}
