<?php

declare(strict_types=1);

namespace Database\Seeders\Data;

use App\Services\Forms\SchemaBlueprintMaterializer;
use App\Services\Forms\SchemaSnapshotSerializer;
use Illuminate\Support\Str;

/**
 * The platform (system) form-template gallery content (Increment G9a / onboarding-content-plan §3): 10
 * starter templates spanning the two personas the product targets — 5 M&E/research + 5 business/general.
 * English-only at launch (§5).
 *
 * Each entry is a plain, reviewable definition whose `blueprint` reuses the id-free, key-referenced
 * `form_versions.schema_snapshot` shape ({@see SchemaSnapshotSerializer}); the seeder
 * stores it verbatim into a NULL-tenant `form_templates` row, and instantiate materializes it back into a
 * new form's draft via {@see SchemaBlueprintMaterializer}. The materializer fills
 * defaults for every omitted column, so these definitions stay minimal — only the keys that carry meaning.
 *
 * Where a template would naturally use a Phase-2 feature not yet fully supported at runtime, it ships a
 * Phase-1-compatible subset (§3, §4) — e.g. the Household Survey's per-member repeat is a plain section for
 * now. The PlatformTemplateSeedTest is the drift guard: it validates every blueprint against the current
 * FieldType catalog and proves each materializes, on every release.
 */
final class PlatformTemplateBlueprints
{
    /**
     * @return list<array{name: string, description: string, category: string, blueprint: array<string, mixed>}>
     */
    public static function all(): array
    {
        return [
            // ── M&E / Research ──────────────────────────────────────────────────────────────────────
            [
                'name' => 'Household Survey',
                'description' => 'Collect household demographics and living conditions in the field.',
                'category' => 'M&E / Research',
                'blueprint' => self::blueprint(
                    [self::section('household', 'Household', 1), self::section('members', 'Household members', 2)],
                    [
                        self::field('respondent_name', 'short_text', 'Respondent name', ['section' => 'household', 'required' => true, 'seq' => 1]),
                        self::field('region', 'single_select', 'Region', ['section' => 'household', 'seq' => 2, 'options' => ['North', 'South', 'East', 'West']]),
                        self::field('household_size', 'integer', 'Number of people in household', ['section' => 'household', 'seq' => 3]),
                        self::field('water_source', 'single_select', 'Main water source', ['section' => 'household', 'seq' => 4, 'options' => ['Piped', 'Well', 'Borehole', 'Surface water']]),
                        self::field('has_electricity', 'yes_no', 'Has electricity', ['section' => 'household', 'seq' => 5]),
                        self::field('member_name', 'short_text', 'Member name', ['section' => 'members', 'seq' => 6]),
                        self::field('member_age', 'integer', 'Member age', ['section' => 'members', 'seq' => 7]),
                    ],
                ),
            ],
            [
                'name' => 'Health Facility Assessment',
                'description' => 'Assess a health facility’s staffing, services, and readiness.',
                'category' => 'M&E / Research',
                'blueprint' => self::blueprint(
                    [self::section('facility', 'Facility', 1)],
                    [
                        self::field('facility_name', 'short_text', 'Facility name', ['section' => 'facility', 'required' => true, 'seq' => 1]),
                        self::field('facility_type', 'dropdown', 'Facility type', ['section' => 'facility', 'seq' => 2, 'options' => ['Hospital', 'Health centre', 'Clinic', 'Dispensary']]),
                        self::field('staff_count', 'integer', 'Number of clinical staff', ['section' => 'facility', 'seq' => 3]),
                        self::field('services_offered', 'multi_select', 'Services offered', ['section' => 'facility', 'seq' => 4, 'options' => ['Outpatient', 'Maternity', 'Laboratory', 'Pharmacy', 'Immunization']]),
                        self::field('cleanliness', 'likert_scale', 'Overall cleanliness', ['section' => 'facility', 'seq' => 5, 'options' => ['Poor', 'Fair', 'Good', 'Excellent']]),
                        self::field('notes', 'long_text', 'Assessor notes', ['section' => 'facility', 'seq' => 6]),
                    ],
                ),
            ],
            [
                'name' => 'Beneficiary Registration',
                'description' => 'Register beneficiaries with identity, location, and consent.',
                'category' => 'M&E / Research',
                'blueprint' => self::blueprint(
                    [self::section('beneficiary', 'Beneficiary', 1)],
                    [
                        self::field('full_name', 'short_text', 'Full name', ['section' => 'beneficiary', 'required' => true, 'seq' => 1]),
                        self::field('date_of_birth', 'date', 'Date of birth', ['section' => 'beneficiary', 'seq' => 2]),
                        self::field('sex', 'single_select', 'Sex', ['section' => 'beneficiary', 'seq' => 3, 'options' => ['Female', 'Male', 'Prefer not to say']]),
                        self::field('phone', 'phone', 'Phone number', ['section' => 'beneficiary', 'seq' => 4]),
                        self::field('location', 'geopoint', 'GPS location', ['section' => 'beneficiary', 'seq' => 5]),
                        self::field('photo', 'image_capture', 'Photo', ['section' => 'beneficiary', 'seq' => 6]),
                        self::field('consent', 'yes_no', 'Consent to data collection', ['section' => 'beneficiary', 'required' => true, 'seq' => 7]),
                    ],
                ),
            ],
            [
                'name' => 'Post-Distribution Monitoring',
                'description' => 'Check that distributed items reached beneficiaries and gather feedback.',
                'category' => 'M&E / Research',
                'blueprint' => self::blueprint(
                    [self::section('pdm', 'Monitoring', 1)],
                    [
                        self::field('beneficiary_id', 'short_text', 'Beneficiary ID', ['section' => 'pdm', 'required' => true, 'seq' => 1]),
                        self::field('received_items', 'yes_no', 'Received all items', ['section' => 'pdm', 'seq' => 2]),
                        self::field('satisfaction', 'likert_scale', 'Satisfaction with distribution', ['section' => 'pdm', 'seq' => 3, 'options' => ['Very dissatisfied', 'Dissatisfied', 'Neutral', 'Satisfied', 'Very satisfied']]),
                        self::field('issues', 'long_text', 'Any issues encountered', ['section' => 'pdm', 'seq' => 4]),
                        self::field('followup_needed', 'yes_no', 'Follow-up needed', ['section' => 'pdm', 'seq' => 5]),
                    ],
                ),
            ],
            [
                'name' => 'Training Attendance & Feedback',
                'description' => 'A single-page sign-in and feedback form for a workshop or training.',
                'category' => 'M&E / Research',
                'blueprint' => self::blueprint(
                    [],
                    [
                        self::field('participant_name', 'short_text', 'Participant name', ['required' => true, 'seq' => 1]),
                        self::field('email', 'email', 'Email', ['seq' => 2]),
                        self::field('session', 'dropdown', 'Session attended', ['seq' => 3, 'options' => ['Morning', 'Afternoon', 'Full day']]),
                        self::field('overall_rating', 'likert_scale', 'Overall rating', ['seq' => 4, 'options' => ['Poor', 'Fair', 'Good', 'Very good', 'Excellent']]),
                        self::field('comments', 'long_text', 'Comments', ['seq' => 5]),
                    ],
                ),
            ],

            // ── Business / general ──────────────────────────────────────────────────────────────────
            [
                'name' => 'Event Registration',
                'description' => 'Register attendees for an event, with date and guest count.',
                'category' => 'Event Registration',
                'blueprint' => self::blueprint(
                    [],
                    [
                        self::field('full_name', 'short_text', 'Full name', ['required' => true, 'seq' => 1]),
                        self::field('email', 'email', 'Email', ['required' => true, 'seq' => 2]),
                        self::field('phone', 'phone', 'Phone', ['seq' => 3]),
                        self::field('attendance_date', 'date', 'Attendance date', ['seq' => 4]),
                        self::field('num_guests', 'integer', 'Number of guests', ['seq' => 5]),
                        self::field('dietary', 'multi_select', 'Dietary requirements', ['seq' => 6, 'options' => ['None', 'Vegetarian', 'Vegan', 'Halal', 'Gluten-free']]),
                    ],
                ),
            ],
            [
                'name' => 'Job Application',
                'description' => 'Collect applications with resume upload and experience details.',
                'category' => 'Recruitment',
                'blueprint' => self::blueprint(
                    [],
                    [
                        self::field('full_name', 'short_text', 'Full name', ['required' => true, 'seq' => 1]),
                        self::field('email', 'email', 'Email', ['required' => true, 'seq' => 2]),
                        self::field('phone', 'phone', 'Phone', ['seq' => 3]),
                        self::field('position', 'dropdown', 'Position applied for', ['seq' => 4, 'options' => ['Engineering', 'Operations', 'Sales', 'Support']]),
                        self::field('years_experience', 'integer', 'Years of experience', ['seq' => 5]),
                        self::field('resume', 'file_upload', 'Resume / CV', ['required' => true, 'seq' => 6]),
                        self::field('cover_letter', 'long_text', 'Cover letter', ['seq' => 7]),
                    ],
                ),
            ],
            [
                'name' => 'Customer Feedback Survey',
                'description' => 'A short single-page satisfaction and NPS survey.',
                'category' => 'Customer Feedback',
                'blueprint' => self::blueprint(
                    [],
                    [
                        self::field('satisfaction', 'likert_scale', 'How satisfied are you overall?', ['seq' => 1, 'options' => ['Very dissatisfied', 'Dissatisfied', 'Neutral', 'Satisfied', 'Very satisfied']]),
                        self::field('liked', 'multi_select', 'What did you like?', ['seq' => 2, 'options' => ['Quality', 'Price', 'Service', 'Speed', 'Support']]),
                        self::field('improvements', 'long_text', 'What could we improve?', ['seq' => 3]),
                        self::field('recommend', 'yes_no', 'Would you recommend us?', ['seq' => 4]),
                    ],
                ),
            ],
            [
                'name' => 'Contact / Lead Capture',
                'description' => 'A minimal contact form to capture leads.',
                'category' => 'Lead Generation',
                'blueprint' => self::blueprint(
                    [],
                    [
                        self::field('name', 'short_text', 'Name', ['required' => true, 'seq' => 1]),
                        self::field('email', 'email', 'Email', ['required' => true, 'seq' => 2]),
                        self::field('company', 'short_text', 'Company', ['seq' => 3]),
                        self::field('message', 'long_text', 'Message', ['seq' => 4]),
                    ],
                ),
            ],
            [
                'name' => 'Employee Onboarding Checklist',
                'description' => 'Track a new hire’s onboarding details and setup needs.',
                'category' => 'Onboarding',
                'blueprint' => self::blueprint(
                    [self::section('personal', 'Personal', 1), self::section('setup', 'Setup', 2)],
                    [
                        self::field('full_name', 'short_text', 'Full name', ['section' => 'personal', 'required' => true, 'seq' => 1]),
                        self::field('start_date', 'date', 'Start date', ['section' => 'personal', 'seq' => 2]),
                        self::field('department', 'dropdown', 'Department', ['section' => 'personal', 'seq' => 3, 'options' => ['Engineering', 'Operations', 'Sales', 'Finance', 'People']]),
                        self::field('equipment_needed', 'multi_select', 'Equipment needed', ['section' => 'setup', 'seq' => 4, 'options' => ['Laptop', 'Monitor', 'Phone', 'Headset']]),
                        self::field('it_account', 'yes_no', 'IT account created', ['section' => 'setup', 'seq' => 5]),
                        self::field('building_access', 'yes_no', 'Building access granted', ['section' => 'setup', 'seq' => 6]),
                    ],
                ),
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    private static function blueprint(array $sections, array $fields): array
    {
        return ['sections' => $sections, 'fields' => $fields];
    }

    /**
     * @return array<string, mixed>
     */
    private static function section(string $key, string $label, int $sequence): array
    {
        return ['key' => $key, 'label' => $label, 'sequence' => $sequence];
    }

    /**
     * @param  array{section?: ?string, required?: bool, seq?: int, options?: list<string>}  $opts
     * @return array<string, mixed>
     */
    private static function field(string $key, string $type, string $label, array $opts = []): array
    {
        $hasOptions = isset($opts['options']);

        return [
            'key' => $key,
            'section_key' => $opts['section'] ?? null,
            'field_type' => $type,
            'label' => $label,
            'is_required' => ($opts['required'] ?? false) ? 'required' : 'optional',
            'sequence' => $opts['seq'] ?? 0,
            'config' => $hasOptions ? self::options($opts['options']) : [],
        ];
    }

    /**
     * The choice-field config shape (data-dictionary §4a): `config.options = [{value, label}]`.
     *
     * @param  list<string>  $labels
     * @return array<string, mixed>
     */
    private static function options(array $labels): array
    {
        return [
            'options' => array_map(
                static fn (string $label): array => ['value' => Str::slug($label, '_'), 'label' => $label],
                $labels,
            ),
        ];
    }
}
