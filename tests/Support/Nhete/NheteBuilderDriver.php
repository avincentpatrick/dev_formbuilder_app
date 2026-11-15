<?php

declare(strict_types=1);

namespace Tests\Support\Nhete;

use App\Models\Form;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * Builds a {@see NhetePlan} through the builder's OWN HTTP routes — the requests `useBuilderStore.ts` sends when a
 * person clicks: an empty `POST …/sections` then its `PATCH`; `POST …/fields` then its `PATCH` with the version token
 * the last save returned; `…/other-specify`; `…/convert`; `…/content-images`. Nothing is written past the routes, so
 * whatever this driver can build, a person in the builder can build (`M237`).
 *
 * Every request is counted, because "possible by hand" and "practical by hand" are different findings.
 */
final class NheteBuilderDriver
{
    public int $requests = 0;

    /** @var array<string, string> element key → field id */
    public array $fieldIds = [];

    /** @var array<string, string> section key → section id */
    public array $sectionIds = [];

    private ?string $imageId = null;

    public function __construct(
        private readonly TestCase $test,
        private readonly User $user,
        private readonly Form $form,
        private readonly string $host = 'http://acme.meridian.test',
    ) {
        $this->test->withHeaders(['X-Builder-Draft' => (string) $form->draft_version_id]);
    }

    public function build(NhetePlan $plan): void
    {
        foreach ($plan->sections() as $section) {
            $sectionId = $this->section($section);
            foreach ($section['elements'] as $element) {
                $this->field($sectionId, $element);
            }
        }
    }

    /** @param  array{key: string, label: string, relevant: ?string}  $section */
    public function section(array $section): string
    {
        $made = $this->send('post', 'sections', [], 'add section '.$section['key']);
        $saved = $this->send('patch', "sections/{$made->json('id')}", [
            'key' => $section['key'],
            'label' => $section['label'],
            'description' => null,
            'is_repeatable' => false,
            'min_instances' => null,
            'max_instances' => null,
            'relevant_expression' => $section['relevant'],
            'version' => $made->json('version'),
        ], 'save section '.$section['key']);

        return $this->sectionIds[$section['key']] = (string) $saved->json('id');
    }

    /** @param  array<string, mixed>  $e */
    public function field(string $sectionId, array $e): string
    {
        $made = $this->send('post', 'fields', ['field_type' => $e['type'], 'section_id' => $sectionId], 'add '.$e['key']);
        $id = (string) $made->json('id');
        $saved = $this->send('patch', "fields/{$id}", $this->payload($e, (string) $made->json('version')), 'save '.$e['key']);

        if ($e['other'] !== null) {
            $this->otherSpecify($id, $e, $saved);
        }

        return $this->fieldIds[$e['key']] = $id;
    }

    /**
     * "Other, please specify": the builder's own pair. The choice it adds is relabelled with the module's words,
     * and its short-text box becomes a long text with the module's word cap — the two edits a person would make.
     *
     * @param  array<string, mixed>  $e
     */
    private function otherSpecify(string $parentId, array $e, TestResponse $saved): void
    {
        $pair = $this->send('post', "fields/{$parentId}/other-specify", [], 'other-specify '.$e['key']);
        $parent = $pair->json('parent');
        $child = $pair->json('child');

        $options = array_map(
            static fn (array $o): array => $o['value'] === 'other' ? [...$o, 'label' => $e['other']] : $o,
            $parent['config']['options'],
        );
        $this->send('patch', "fields/{$parentId}", [
            ...$this->payload([...$e, 'other' => null], (string) $parent['version']),
            'config' => [...$parent['config'], 'options' => $options],
        ], 'relabel other on '.$e['key']);

        $converted = $this->send('post', "fields/{$child['id']}/convert", ['to' => 'long_text', 'version' => $child['version']], 'convert '.$child['key']);
        $this->send('patch', "fields/{$child['id']}", [
            'key' => $converted->json('key'),
            'label' => $converted->json('label'),
            'hint' => null,
            'placeholder' => null,
            'is_required' => $converted->json('is_required'),
            'relevant_expression' => $converted->json('relevant_expression'),
            'appearance' => null,
            'config' => $converted->json('config') ?? [],
            'validations' => $this->rules(['max_words' => $e['other_words'], 'max_choices' => null, 'exclusive' => null, 'required_when' => []], []),
            'version' => $converted->json('version'),
        ], 'cap '.$child['key']);
        $this->fieldIds[(string) $child['key']] = (string) $child['id'];
    }

    /**
     * @param  array<string, mixed>  $e
     * @return array<string, mixed>
     */
    private function payload(array $e, string $version): array
    {
        $config = [];
        if ($e['options'] !== []) {
            $config['options'] = array_map(static fn (array $o): array => ['value' => $o[0], 'label' => $o[1]], $e['options']);
        }
        if ($e['type'] === 'note') {
            $config['content'] = $e['content'] === null ? [] : $this->withImages($e['content']);
        }
        if ($e['type'] === 'calculated') {
            $config['calculated_formula'] = $e['formula'];
            $config['calculated_type'] = $e['calculated_type'] ?? 'text';
        }

        return [
            'key' => $e['key'],
            'label' => $e['label'],
            'hint' => $e['hint'],
            'placeholder' => $e['placeholder'],
            'is_required' => $e['required'],
            'relevant_expression' => $e['relevant'],
            'appearance' => null,
            'config' => $config,
            'validations' => $this->rules($e, $this->fieldIds),
            'version' => $version,
        ];
    }

    /**
     * The answer rules a person sets in the Validation pane.
     *
     * @param  array<string, mixed>  $e
     * @param  array<string, string>  $ids
     * @return list<array<string, mixed>>
     */
    public function rules(array $e, array $ids): array
    {
        $rules = [];
        if ($e['max_words'] !== null) {
            $rules[] = self::rule(['rule_type' => 'pattern', 'rule_value' => self::wordCapPattern((int) $e['max_words']),
                'error_message' => 'Please keep this to '.number_format((int) $e['max_words']).' words or fewer.']);
        }
        if ($e['max_choices'] !== null) {
            $rules[] = self::rule(['expression' => "count(.) <= {$e['max_choices']}", 'error_message' => "You can choose up to {$e['max_choices']}."]);
        }
        if ($e['exclusive'] !== null) {
            $rules[] = self::rule(['expression' => "not(selected(., '{$e['exclusive']}')) or count(.) = 1",
                'error_message' => 'No view or opinion cannot be combined with another answer.']);
        }
        foreach ($e['required_when'] as $n => [$relatedKey, $value]) {
            $rules[] = self::rule([
                'rule_type' => 'required_if', 'operator' => 'eq', 'rule_value' => $value, 'related_field_key' => $relatedKey,
                // A new group's token is any [A-Za-z0-9-] string the client mints (UpdateFieldRequest).
                'logic_group' => 'when-'.str_replace('_', '-', (string) $e['key']), 'logic_operator' => $n === 0 ? null : 'or',
                'error_message' => 'Please tell us more here.',
            ]);
        }

        return $rules;
    }

    /** A whole-answer pattern that admits at most `$max` words (the pattern rule is anchored, Unicode-aware). */
    public static function wordCapPattern(int $max): string
    {
        return '\s*(?:\S+(?:\s+\S+){0,'.($max - 1).'})?\s*';
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function rule(array $overrides): array
    {
        return [
            'rule_type' => null, 'operator' => null, 'rule_value' => null, 'expression' => null, 'error_message' => null,
            'related_field_key' => null, 'logic_group' => null, 'logic_operator' => null, ...$overrides,
        ];
    }

    /**
     * A diagram is an image the author uploads into the note (`POST …/content-images`), once, and points at.
     *
     * @param  list<array<string, mixed>>  $content
     * @return list<array<string, mixed>>
     */
    private function withImages(array $content): array
    {
        return array_map(function (array $block): array {
            if ($block['type'] !== 'image') {
                return $block;
            }
            if ($this->imageId === null) {
                $png = UploadedFile::fake()->image('nhete-diagram.png', 640, 360);
                $this->requests++;
                $upload = $this->test->actingAs($this->user)
                    ->post("{$this->host}/forms/{$this->form->id}/content-images", ['file' => $png], ['Accept' => 'application/json']);
                if ($upload->status() !== 201) {
                    throw new RuntimeException("upload diagram → {$upload->status()}: ".mb_substr($upload->getContent(), 0, 600));
                }
                $this->imageId = (string) $upload->json('data.id');
            }

            return [...$block, 'attachment_id' => $this->imageId];
        }, $content);
    }

    /** @param  array<string, mixed>  $body */
    public function send(string $method, string $path, array $body, string $what): TestResponse
    {
        $this->requests++;
        $url = "{$this->host}/forms/{$this->form->id}/{$path}";
        $response = $this->test->actingAs($this->user)->{$method.'Json'}($url, $body);
        if ($response->status() >= 300) {
            throw new RuntimeException("{$what} → {$response->status()}: ".mb_substr((string) $response->getContent(), 0, 900));
        }

        return $response;
    }
}
