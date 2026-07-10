<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\FieldType;
use App\Enums\TenantUserStatus;
use App\Models\Form;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Forms\FormBuilderService;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Deterministic fixture for the Playwright e2e / responsive-axe gate (and a convenient local demo).
 * Creates a tenant `acme` reachable at acme.{central_domain}, an Owner login, and one pending invite so
 * the Members roster exercises multiple Badge variants. Guarded against production. Idempotent: safe to
 * re-run (identities are looked up on the pre-auth connection since the join-shape users RLS hides them).
 *
 * Login: demo@meridian.test / meridian-e2e-2026
 */
class E2eSeeder extends Seeder
{
    private const OWNER_EMAIL = 'demo@meridian.test';

    private const OWNER_PASSWORD = 'meridian-e2e-2026';

    private const PENDING_EMAIL = 'pending@meridian.test';

    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        // The fixed role/permission catalog must exist before we assign roles.
        $this->call(RolePermissionSeeder::class);

        // Tenant + subdomain (tenants/domains are RLS-exempt central tables).
        $tenant = Tenant::firstOrCreate(['slug' => 'acme'], ['name' => 'Acme Research']);
        if (! $tenant->domains()->where('domain', 'acme')->exists()) {
            $tenant->domains()->create(['domain' => 'acme']);
        }

        $owner = $this->resolveOrCreateUser(self::OWNER_EMAIL, 'Demo Owner', self::OWNER_PASSWORD);
        $pending = $this->resolveOrCreateUser(self::PENDING_EMAIL, 'Pending Teammate', Str::random(48));

        // Active Owner membership + a pending invite. Wrapped in a transaction so applyLocal's
        // transaction-local RLS GUC (app.current_tenant_id) is actually in force for the INSERTs —
        // outside a transaction it wouldn't stick and the strict RLS WITH CHECK would reject the rows.
        DB::transaction(function () use ($tenant, $owner, $pending): void {
            TenantContext::applyLocal((string) $tenant->id, $owner->id);
            app(PermissionRegistrar::class)->setPermissionsTeamId((string) $tenant->id);

            if (! TenantUser::query()->where('user_id', $owner->id)->exists()) {
                TenantUser::create([
                    'user_id' => $owner->id,
                    'status' => TenantUserStatus::Active,
                    'joined_at' => now(),
                    'invited_role_id' => $this->roleId('owner'),
                ]);
                $owner->syncRoles(['owner']);
            }

            if (! TenantUser::query()->where('user_id', $pending->id)->exists()) {
                TenantUser::create([
                    'user_id' => $pending->id,
                    'status' => TenantUserStatus::Invited,
                    'invited_role_id' => $this->roleId('viewer'),
                    'invited_by' => $owner->id,
                    'invited_at' => now(),
                    'invite_expires_at' => now()->addDays(7),
                    'invite_token' => hash('sha256', Str::random(48)),
                ]);
            }

            // A demo form for the Forms page (D3) + the builder (D4a): a section + a few fields on the
            // draft, published once (so version history is exercised) and cloned forward into the current
            // draft the builder edits. The content makes the builder auto-select a field so the config
            // panel actually mounts for the interaction/axe gate.
            if (Form::query()->where('title', 'Community Health Survey')->doesntExist()) {
                $form = app(FormService::class)->create(
                    $tenant, $owner, 'Community Health Survey', 'Monthly field data collection.'
                );
                $builder = app(FormBuilderService::class);
                $section = $builder->addSection($form);
                // Repeatable so the builder's section → Advanced tab renders the min/max MdsNumberInputs
                // by default (the interaction-driven builder-axe.spec.ts scans them without a stateful toggle).
                $section->update(['is_repeatable' => true, 'min_instances' => 1, 'max_instances' => 5]);
                $builder->addField($form, $owner, FieldType::ShortText, $section->id);
                $builder->addField($form, $owner, FieldType::SingleSelect, $section->id);
                $builder->addField($form, $owner, FieldType::Integer, null);
                app(PublishService::class)->publish($form->refresh(), $owner);
            }

            // A second form left as an empty draft — the builder's empty-canvas state must also be
            // axe-clean (Increment D4b builder-axe.spec.ts scans it).
            if (Form::query()->where('title', 'Blank Intake Form')->doesntExist()) {
                app(FormService::class)->create($tenant, $owner, 'Blank Intake Form', 'An empty draft.');
            }

            // An all-scalar, repeat-free published form — the manual-encode target (Increment F4b). One of
            // every Phase-1 encodable control (text, number, select, multi-select, yes/no, date, long text)
            // so the encode page's responsive/axe gate scans them all. The seeded "Community Health Survey"
            // has a repeatable section (unsupported for manual entry), so it can't be that target.
            if (Form::query()->where('title', 'Clinic Intake')->doesntExist()) {
                $intake = app(FormService::class)->create(
                    $tenant, $owner, 'Clinic Intake', 'All-scalar manual-encoding demo.'
                );
                $b = app(FormBuilderService::class);
                $section = $b->addSection($intake); // non-repeatable by default → its fields are encodable
                $b->addField($intake, $owner, FieldType::ShortText, $section->id);
                $b->addField($intake, $owner, FieldType::Integer, $section->id);
                $b->addField($intake, $owner, FieldType::SingleSelect, $section->id)
                    ->update(['config' => ['options' => [
                        ['value' => 'female', 'label' => 'Female'],
                        ['value' => 'male', 'label' => 'Male'],
                        ['value' => 'other', 'label' => 'Other'],
                    ]]]);
                $b->addField($intake, $owner, FieldType::MultiSelect, $section->id)
                    ->update(['config' => ['options' => [
                        ['value' => 'fever', 'label' => 'Fever'],
                        ['value' => 'cough', 'label' => 'Cough'],
                        ['value' => 'fatigue', 'label' => 'Fatigue'],
                    ]]]);
                $b->addField($intake, $owner, FieldType::YesNo, $section->id);
                $b->addField($intake, $owner, FieldType::Date, $section->id);
                $b->addField($intake, $owner, FieldType::LongText, $section->id);
                app(PublishService::class)->publish($intake->refresh(), $owner);

                // Guest-enable it so the public runtime SPA (Increment F6b) can be reached at
                // /f/clinic-intake, and give it two supported locales so the language switcher renders in
                // the public-runtime axe scan. Targeted update — leaves the publish columns untouched.
                $intake->update([
                    'public_slug' => 'clinic-intake',
                    'allow_guest_submissions' => true,
                    'supported_locales' => ['en', 'es'],
                ]);
            }
        });

        $tenant->forceFill(['owner_user_id' => $owner->id])->save();

        TenantContext::flush();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    /** Resolve an existing identity on the pre-auth connection (users RLS hides non-members), or create it. */
    private function resolveOrCreateUser(string $email, string $name, string $password): User
    {
        $existing = User::on('pgsql_auth')->where('email', $email)->first();
        if ($existing !== null) {
            $existing->setConnection((string) config('database.default'));

            return $existing;
        }

        return User::create(['name' => $name, 'email' => $email, 'password' => Hash::make($password)]);
    }

    private function roleId(string $name): string
    {
        return (string) Role::query()->whereNull('tenant_id')->where('name', $name)->value('id');
    }
}
