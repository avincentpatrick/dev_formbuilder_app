<?php

declare(strict_types=1);

use App\Support\Migrations\PublishedVersionGuard;
use Illuminate\Database\Migrations\Migration;

/**
 * Increment H25 / ADR-0013 — Risk R5: published-version immutability, enforced at the DATABASE.
 *
 * R5 has been open since the architecture doc was written: *"Published-version immutability is enforced
 * only at the application layer … a future code change (or a raw query bypassing the service layer)
 * accidentally mutates a published version's fields, silently corrupting the historical meaning of past
 * submissions."* It was folded into H21a and lost its forcing moment when the 2026-07-21 decision chose
 * relevance-derived step-skipping over a stored step graph; this is the hardening PR it became.
 *
 * ── WHAT WAS ALREADY GUARDED, AND WHAT WAS NOT ────────────────────────────────────────────────────
 * A published version's CONTENT has been frozen in Postgres since Increment D: the draft-child RLS shape
 * gates every write on `form_sections`/`form_fields`/`form_field_validations` behind
 * `EXISTS (form_versions fv WHERE fv.status = 'draft')`. Its OWN ROW was not, because the `form_version`
 * shape leaves UPDATE deliberately status-blind so the publish transaction can flip
 * draft -> published -> superseded. So `schema_snapshot`, `checksum`, `version_number`, `title`,
 * `published_at` were all freely rewritable on a published row — and `status` could be set back to
 * `draft`, which RE-OPENS every child row, since the child guard keys on exactly that value.
 *
 * ── WHY A TRIGGER, WHEN THIS SCHEMA SAYS "RLS, NOT A TRIGGER" ─────────────────────────────────────
 * `docs/form-versioning-schema-migration.md` §2 resolved the child-table guard onto RLS, explicitly
 * rejecting a trigger so the schema would keep ONE DB-level-guard idiom. That resolution stands, and it
 * is built. It cannot be extended to the parent row: a policy sees only OLD in `USING` and only NEW in
 * `WITH CHECK`, and no clause can compare them — but a per-column immutability rule IS an OLD-vs-NEW
 * comparison. A CHECK constraint cannot see OLD either, and `ADD CONSTRAINT ... CHECK` additionally
 * validates every existing row at DDL time, where `CREATE TRIGGER` cannot fail on legacy data. ADR-0013
 * records that boundary: row-scoped invariants stay RLS; OLD-vs-NEW invariants get a trigger, and this
 * is the only one that qualifies today.
 *
 * ── SCOPE: UPDATE ONLY, DELIBERATELY ──────────────────────────────────────────────────────────────
 * No DELETE trigger. A BEFORE DELETE row trigger fires on FK cascades too — `form_versions.form_id`
 * and `.tenant_id` are both ON DELETE CASCADE — so it would make tenant hard-delete and form
 * hard-delete raise instead of cascading, which is a behaviour change far beyond a hardening PR.
 * Direct DELETE of a non-draft version is already a silent zero-row no-op under the existing RLS
 * policy. The residual — a hard-delete of a `forms` or `tenants` row wipes published versions through
 * the cascade, because referential actions bypass RLS, and `TRUNCATE` bypasses row triggers entirely —
 * is recorded as Risk R12 rather than silently inherited. The guarantee this migration ships is
 * "a published version cannot be EDITED", never "cannot be destroyed".
 *
 * ── BREAK-GLASS ───────────────────────────────────────────────────────────────────────────────────
 * There is no GUC escape hatch, because a custom `app.*` GUC is settable by any role and so would be no
 * obstacle to the very threat this guard addresses. A deliberate repair — re-deriving a `checksum` after
 * a serializer fix, backfilling a new snapshot key — runs
 * `ALTER TABLE form_versions DISABLE TRIGGER form_versions_published_immutable_trg;` … `ENABLE TRIGGER`
 * inside its OWN migration, on the ordinary connection: loud, reviewable, and in version control. The H8
 * precedent shows the need is real and also shows the better answer where one exists — it put its
 * retroactive correction on `forms.capability_flags` rather than on the frozen version.
 *
 * Alter-only, so `scripts/migration-lint.php` skips it (it keys on `Schema::create`). That means this
 * migration's CI green is VACUOUS: the only things standing over the guard are
 * `tests/Unit/PublishedVersionGuardTest.php` and the two feature packs in `tests/Feature/Forms/`.
 */
return new class extends Migration
{
    public function up(): void
    {
        PublishedVersionGuard::apply();
    }

    public function down(): void
    {
        PublishedVersionGuard::drop();
    }
};
