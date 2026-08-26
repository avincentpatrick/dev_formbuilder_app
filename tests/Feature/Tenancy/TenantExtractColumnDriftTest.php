<?php

declare(strict_types=1);

use App\Support\Tenancy\TenantExtractColumns;
use App\Support\Tenancy\TenantScopedTables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The column census vs. the database (Phase 4, P2b — ADR-0018 §D3).
|--------------------------------------------------------------------------
| TenantExtractColumns holds a small DECISION — which columns are too dangerous or too meaningless to
| put in an artefact somebody will be handed. This file holds the DRIFT, and the drift is the whole
| reason the decision can afford to be small.
|
| ⚠️ THE CENSUS BELOW IS WHY A WITHHELD-LIST IS SAFE. The extractor selects "every column the catalog
| reports, minus the withheld ones", so left to itself a migration that adds `users.recovery_token`
| would put it straight into the next extract with nobody deciding anything. This constant is the
| decision point: adding, dropping or renaming ANY column of ANY extracted table fails this file, and
| the only way to make it pass is to look at the new column and choose. The bulk is the point — it is
| test data, where bulk is cheap, and it keeps the production constant short enough to be read.
|
| Whoever reddens this: update BOTH halves. Add the column here, and add it to
| TenantExtractColumns::WITHHELD if it is a live credential, a fact about a subject other than this
| tenant, or a derived column. Otherwise it will be in the next extract.
*/

/**
 * table => space-separated column names, sorted. Sorted rather than ordinal because ordinal position is
 * an artefact of migration order — a column re-added by a later migration lands at the end and would
 * redden this file for a change that is not a change.
 *
 * @var array<string, string>
 */
const EXTRACTED_COLUMN_CENSUS = [
    'attachments' => 'attachable_id attachable_type checksum_sha256 created_at deleted_at disk duration_seconds height id is_encrypted_at_rest is_pii kind mime_type ocr_confidence_avg original_filename path size_bytes tenant_id updated_at uploaded_by virus_scan_status width',
    'audits' => 'acting_as_user_id auditable_id auditable_type created_at event id ip_address is_system_action new_values old_values redacted_fields tenant_id user_agent user_id',
    'badge_awards' => 'awarded_at badge created_at id tenant_id updated_at user_id',
    'connection_subscriptions' => 'config connection_id consecutive_failure_count created_at created_by deleted_at event_types form_id id last_failure_at last_success_at name status tenant_id updated_at',
    'connections' => 'access_token connected_by created_at deleted_at external_account_id external_account_label id last_error last_error_at last_refreshed_at provider refresh_token scopes status tenant_id token_expires_at updated_at',
    'domains' => 'activated_at created_at domain id is_primary tenant_id token_issued_at updated_at verification_checked_at verification_failure_reason verification_token verified_at',
    'feedback_reports' => 'browser_info id remarks resolved_at resolved_by route screenshot_attachment_id status submitted_at tenant_id user_id',
    'field_library' => 'category created_at created_by default_config default_hint default_label default_label_translations default_validations deleted_at description field_type id is_active name tenant_id updated_at usage_count',
    'form_field_validations' => 'created_at error_message error_message_translations expression form_field_id form_version_id id logic_group logic_operator operator related_form_field_id rule_type rule_value sequence tenant_id updated_at',
    'form_fields' => 'appearance config created_at created_by default_value default_value_is_expression field_type form_section_id form_version_id hint hint_translations id indexed_data_type is_pii is_queryable is_required is_sensitive key label label_translations placeholder relevant_expression section_sequence sequence tenant_id updated_at updated_by',
    'form_sections' => 'color created_at description description_translations form_version_id icon id is_repeatable key label label_translations max_instances min_instances relevant_expression sequence tenant_id updated_at',
    'form_templates' => 'category cover_image_attachment_id created_at created_by deleted_at description id is_public name schema_blueprint source_form_version_id tenant_id updated_at usage_count',
    'form_versions' => 'change_summary checksum created_at description form_id id published_at published_by schema_snapshot status superseded_at tenant_id title updated_at version_number',
    'forms' => 'allow_api_import allow_guest_submissions allow_manual_encoding allow_ocr_linelist allow_ocr_single allow_offline_sync archived_at bot_challenge capability_flags closes_at confirmation_message confirmation_message_translations created_at created_by current_published_version_id default_locale deleted_at description draft_version_id guest_rate_limit_per_minute id max_responses opens_at owner_user_id public_slug published_at save_and_resume schedule_state scope_node_id search_vector single_page_mode status supported_locales tenant_id theme timezone title updated_at updated_by',
    'global_probes' => 'created_at id note tenant_id updated_at',
    'google_auth_requests' => 'completed_at consumed_at created_at expires_at google_email google_email_verified google_name google_sub handoff_expires_at handoff_hash id ip_address issued_at return_to state_id tenant_id updated_at',
    'impersonation_tokens' => 'consumed_at created_at expires_at id ip_address operator_id target_user_id tenant_id token_hash updated_at',
    'legacy_overrides' => 'created_at feature_flags id tenant_id updated_at',
    'model_has_permissions' => 'model_id model_type permission_id tenant_id',
    'model_has_roles' => 'model_id model_type role_id tenant_id',
    'notification_preferences' => 'created_at email_enabled id in_app_enabled notification_type tenant_id updated_at user_id',
    'notifications' => 'created_at data emailed_at id read_at tenant_id type updated_at user_id',
    'permissions' => 'created_at guard_name id name tenant_id updated_at',
    'personal_access_tokens' => 'abilities created_at expires_at id last_used_at name tenant_id token tokenable_id tokenable_type updated_at',
    'point_awards' => 'awarded_at created_at id points rule subject_id subject_type tenant_id updated_at user_id',
    'probes' => 'created_at id note tenant_id updated_at',
    'resource_grants' => 'capacity created_at granted_by id includes_descendants scopeable_id scopeable_type tenant_id updated_at user_id',
    'roles' => 'created_at guard_name id name tenant_id updated_at',
    'saved_report_views' => 'created_at definition id name tenant_id updated_at user_id',
    'scope_nodes' => 'code created_at created_by depth id is_active name node_type parent_id path position tenant_id updated_at',
    'settings' => 'created_at id key tenant_id updated_at updated_by value',
    'sso_auth_failures' => 'created_at id ip_address occurred_at reason request_id sso_connection_id subject_email tenant_id updated_at',
    'sso_auth_requests' => 'completed_at consumed_at created_at expires_at force_authn id intent ip_address issued_at request_id resolved_user_id return_to sso_connection_id tenant_id updated_at user_id verified_at',
    'sso_connections' => 'attribute_map created_at created_by default_role_name id idp_certificates idp_certificates_fingerprint idp_entity_id idp_metadata_imported_at idp_metadata_sha256 idp_sso_url jit_provisioning_enabled last_login_at name_id_format protocol status tenant_id updated_at',
    // M18. `verification_token` IS extracted and is NOT withheld, matching `domains` above rather than
    // diverging from it: the token is published in public DNS at a name only the zone's controller can
    // write, so it is not a credential — the decision recorded at
    // `2026_08_04_000001_add_custom_domain_verification_to_domains.php`, applied to its sibling. Nothing
    // else here is a fact about a subject outside this tenant.
    'sso_verified_domains' => 'created_at created_by domain id tenant_id token_issued_at updated_at verification_checked_at verification_failure_reason verification_token verified_at',
    'submission_answer_index' => 'created_at field_key form_field_id form_version_id id submission_id tenant_id updated_at value_boolean value_date value_datetime value_number value_text',
    'submission_answers' => 'answers answers_content_checksum answers_schema_checksum attachment_refs completeness_percent created_at form_version_id last_saved_at submission_id tenant_id updated_at',
    'submission_geo_index' => 'captured_accuracy created_at field_key form_field_id form_version_id geom geometry_type id submission_id tenant_id updated_at',
    'submissions' => 'app_version client_submission_uuid completeness_percent created_at deleted_at device_id draft_current_step draft_expires_at finalized_at form_id form_version_id guest_contact_email guest_ip guest_token guest_user_agent id last_saved_at locale pii_erased_at reference remarks respondent_user_id returned_reason search_vector source source_batch_id status submitted_at tenant_id updated_at validated_at validated_by',
    'subscriptions' => 'billing_interval canceled_at cancels_at created_at current_period_ends_at current_period_starts_at ended_at id name plan_id quantity stripe_customer_id stripe_status stripe_subscription_id tenant_id trial_ends_at updated_at',
    // `onboarding_dismissed_at` (J5b) is EXTRACTED rather than withheld, and the choice was made by looking
    // at it as this file's preamble asks. It is a plain, non-derived fact about this tenant's own membership
    // row — no credential, and no fact about a subject other than this tenant — so it fails all three
    // WITHHELD tests. A member reading their own extract sees when they dismissed the getting-started card,
    // which is exactly as informative as `joined_at` beside it.
    'tenant_users' => 'created_at id invite_expires_at invite_token invited_at invited_by invited_role_id joined_at onboarding_dismissed_at removed_at removed_by status tenant_id updated_at user_id',
    'tenants' => 'brand_ramp created_at data default_locale draft_ttl_days id logo_attachment_id maintenance_message maintenance_mode name owner_user_id primary_color slug status supported_locales updated_at',
    'usage_counters' => 'created_at id last_incremented_at limit_snapshot metric period_end period_start subscription_id tenant_id updated_at value',
    'users' => 'created_at deleted_at email email_verified_at google_id id is_super_admin last_active_tenant_id name password privacy_policy_accepted_at remember_token tos_accepted_at two_factor_confirmed_at two_factor_recovery_codes two_factor_secret updated_at',
    'webhook_deliveries' => 'attempt_count connection_subscription_id created_at event_id event_type id last_attempted_at max_attempts next_retry_at payload payload_attachment_id response_body_excerpt response_status_code response_time_ms signature status tenant_id unconfirmed_write_at updated_at webhook_endpoint_id',
    'webhook_endpoints' => 'consecutive_failure_count created_at created_by deleted_at disabled_reason event_types form_id id last_failure_at last_success_at name secret secret_previous secret_previous_expires_at signing_algorithm status tenant_id updated_at url',
];

/** @return list<string> */
function catalogColumnsOf(string $table): array
{
    /** @var list<object{column_name: string}> $rows */
    $rows = DB::select(
        "select column_name from information_schema.columns
         where table_schema = 'public' and table_name = ?
         order by column_name",
        [$table]
    );

    return array_map(static fn (object $r): string => (string) $r->column_name, $rows);
}

/** Every table the extractor opens a file for, which is the census's denominator. */
function extractedTableNames(): array
{
    return [
        ...TenantScopedTables::all(),
        ...array_keys(TenantScopedTables::UNPROTECTED_TENANT_TABLES),
        'tenants',
        'users',
    ];
}

it('has a census entry for every table the extractor reads', function (): void {
    // The REVERSE direction, and the one that earns its keep — exactly as TenantTableClassificationDriftTest
    // found `sso_auth_failures`. A table newly classified in TenantScopedTables becomes extractable the
    // moment it is added there, with no second edit anywhere; without this assertion its columns would
    // enter the artefact having never been looked at by anybody.
    $missing = array_values(array_diff(extractedTableNames(), array_keys(EXTRACTED_COLUMN_CENSUS)));

    expect($missing)->toBe([]);
});

it('does not carry a census entry for a table nobody extracts', function (): void {
    // The forward direction: a stale entry is not merely untidy, it is a claim that something is being
    // checked when nothing is.
    $extra = array_values(array_diff(array_keys(EXTRACTED_COLUMN_CENSUS), extractedTableNames()));

    expect($extra)->toBe([]);
});

it('matches the live catalog column for column', function (string $table, string $census): void {
    // ⚠️ THE POINT OF THE WHOLE FILE. Redden here and DO NOT just paste the new list in: the extractor
    // selects catalog-minus-withheld, so any column added below is a column added to the next artefact.
    // Decide first, then paste.
    expect(implode(' ', catalogColumnsOf($table)))->toBe($census);
})->with(array_map(
    static fn (string $t): array => [$t, EXTRACTED_COLUMN_CENSUS[$t]],
    array_keys(EXTRACTED_COLUMN_CENSUS)
));

it('withholds only columns that exist', function (): void {
    // The static twin of TenantExtractColumns::assertWithheldColumnsExist(), which is the runtime guard.
    // A withheld name matching no column is a withholding that has silently stopped happening — the entry
    // is still there, so every assertion phrased as "password is withheld" keeps passing while the column
    // it was protecting flows into the artefact under its new name.
    foreach (TenantExtractColumns::WITHHELD as $table => $columns) {
        $absent = array_values(array_diff(array_keys($columns), catalogColumnsOf($table)));

        expect($absent)->toBe([], "{$table} withholds columns that do not exist: ".implode(', ', $absent));
    }
});

it('gives every withheld column a reason somebody could act on', function (): void {
    // Same argument as TenantScopedTables::UNPROTECTED_TENANT_TABLES: an entry with no rationale is
    // indistinguishable from a column somebody removed out of caution and nobody can ever put back. The
    // length floor is crude on purpose — it catches "secret" and "PII", which are labels, not reasons.
    //
    // ⚠️ COLLECTED, NOT ASSERTED PER COLUMN, AND THAT IS NOT A STYLE CHOICE. Written as an `expect()`
    // inside the loop this stops at the FIRST offender, so a run reports one violation, the author fixes
    // it, and the next run reports the next — which is exactly what happened while writing this file: the
    // author's own `submissions.search_vector` hid `users.remember_token` behind it. A gate that reveals
    // its findings one per run teaches people to distrust its "green".
    $thin = [];

    foreach (TenantExtractColumns::WITHHELD as $table => $columns) {
        foreach ($columns as $column => $reason) {
            if (mb_strlen($reason) <= 60) {
                $thin[] = "{$table}.{$column} (".mb_strlen($reason).' chars)';
            }
        }
    }

    expect($thin)->toBe([], 'These withheld columns have a label where a reason belongs: '.implode(', ', $thin));
});

it('withholds every transformed column from the verbatim select list', function (): void {
    // ⚠️ NOT A TIDINESS RULE. The two lists do different jobs on the same column: WITHHELD removes it from
    // the verbatim list, TRANSFORMED adds it back under an expression. Present in TRANSFORMED alone, the
    // column is selected TWICE — once raw and once transformed — and PostgreSQL is perfectly happy to
    // return both. The raw copy is the one that would have defeated the point of transforming it.
    foreach (TenantExtractColumns::TRANSFORMED as $table => $columns) {
        foreach (array_keys($columns) as $column) {
            expect(TenantExtractColumns::withheldFor($table))->toHaveKey($column);
        }
    }
});

it('aliases every transformed expression to the column key it is stored under', function (): void {
    // ⚠️ SILENT IF IT DRIFTS. The encoder looks a transformed column's type up by the ARRAY KEY, so an
    // expression aliased to anything else produces a result column the encoder has never heard of: it
    // falls through to the `text` default, the GeoJSON arrives as a JSON-encoded string instead of an
    // object, and the artefact is well-formed and subtly wrong. The alias lives inside the SQL — rather
    // than being concatenated on at query time — so that every byte of raw SQL sits in one readable
    // constant; this assertion is the cost of that.
    foreach (TenantExtractColumns::TRANSFORMED as $table => $columns) {
        foreach ($columns as $column => $spec) {
            expect($spec['sql'])->toEndWith(' as "'.$column.'"', "{$table}.{$column} aliases to something else");
        }
    }
});

it('withholds every credential column on users', function (): void {
    // Named individually rather than counted, because a count passes when one is swapped for another.
    // These are the ones that authenticate or globally identify a CENTRAL identity — ADR-0017 Context §2:
    // one human, one password, N workspaces — so extracting any of them hands one tenant material for an
    // account that is still live in workspaces it has nothing to do with.
    //
    // ⚠️ `google_id` JOINED THIS LIST BY WAY OF A CROSS-LANE FAILURE WORTH RECORDING. It arrived in J3c2,
    // this file arrived in P2b, and each merged green on a base that did not contain the other — so the
    // integration branch went red on the census the moment they met, and the column had no withholding at
    // all until then. The census guarded the SHAPE and this list guards the DECISION; a census entry alone
    // would have gone green while shipping the column into the next artefact, which is precisely what the
    // "decide first, then paste" warning at the top of this file exists to prevent.
    expect(array_keys(TenantExtractColumns::withheldFor('users')))
        ->toContain('password')
        ->toContain('remember_token')
        ->toContain('two_factor_secret')
        ->toContain('two_factor_recovery_codes')
        ->toContain('last_active_tenant_id')
        ->toContain('google_id');
});

it('withholds the live half of an in-flight Google sign-in', function (): void {
    // The same decision on the other table J3c2 added. `google_auth_requests` rows are extracted — a tenant
    // may legitimately see THAT a sign-in was attempted and when, on the `impersonation_tokens` precedent —
    // but `handoff_hash` finishes that sign-in for whoever holds the preimage, and `google_sub` is the same
    // globally unique join key as `users.google_id` reaching a second table.
    expect(array_keys(TenantExtractColumns::withheldFor('google_auth_requests')))
        ->toContain('handoff_hash')
        ->toContain('state_id')
        ->toContain('google_sub');

    // ⭐ And the row itself must still be extractable, or the guard above would be vacuously satisfied by a
    // table nobody reads. `google_email` is deliberately NOT withheld: the attempt was made against THIS
    // workspace, so it is this workspace's record.
    expect(TenantExtractColumns::withheldFor('google_auth_requests'))->not->toHaveKey('google_email');
});
