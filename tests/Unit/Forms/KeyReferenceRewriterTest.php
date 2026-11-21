<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormFieldValidation;
use App\Models\FormSection;
use App\Services\Forms\KeyReferenceRewriter;

// M242 (`R-ade0b598`) — what the one rewriting engine reports when a rename meets a text it cannot read. Pure Unit: the
// models are built in memory and never saved; no container, no DB.
//
// EVERY `${` literal below is SINGLE-quoted: PHP 8.3 removed `${var}` interpolation (ExpressionLexerTest's gotcha).

it('reports each expression column that names a key but cannot be read, and leaves it as written', function (): void {
    $field = (new FormField)->forceFill([
        'field_type' => FieldType::Calculated,
        'label' => 'Score for ${age}',
        'relevant_expression' => '${age} = \'unfinished',
        'default_value' => '${age} + ',          // reads: an unfinished formula is still tokens
        'default_value_is_expression' => true,
        'config' => ['calculated_formula' => '${age} * \'open'],
    ]);

    $unread = (new KeyReferenceRewriter)->field($field, ['age' => 'years']);

    expect($unread)->toBe(['relevant_expression', 'calculated_formula'])
        ->and($field->relevant_expression)->toBe('${age} = \'unfinished')
        ->and($field->config['calculated_formula'])->toBe('${age} * \'open')
        ->and($field->default_value)->toBe('${years} + ')
        ->and($field->label)->toBe('Score for ${years}');
});

it('reports nothing for text it can read, or text that names no key at all', function (): void {
    $rewriter = new KeyReferenceRewriter;
    $section = (new FormSection)->forceFill(['label' => 'S', 'relevant_expression' => '${age} > 1']);
    $rule = (new FormFieldValidation)->forceFill(['expression' => '. > 1 and \'open']); // no `${`: never read for keys

    expect($rewriter->section($section, ['age' => 'years']))->toBe([])
        ->and($section->relevant_expression)->toBe('${years} > 1')
        ->and($rewriter->validation($rule, ['age' => 'years']))->toBe([]);
});

it('reports a custom rule it cannot read under its own column', function (): void {
    $rule = (new FormFieldValidation)->forceFill(['expression' => '. >= ${age} and \'open']);

    expect((new KeyReferenceRewriter)->validation($rule, ['age' => 'years']))->toBe(['expression']);
});

it('rewrites the thank-you message and its translations, and answers null when nothing in them changes', function (): void {
    $form = (new Form)->forceFill([
        'confirmation_message' => 'Thanks — ${age}',
        'confirmation_message_translations' => ['fil' => 'Salamat — ${age}', 'ceb' => 'Salamat'],
    ]);
    $rewriter = new KeyReferenceRewriter;

    expect($rewriter->confirmation($form, ['age' => 'years']))->toBe([
        'message' => 'Thanks — ${years}',
        'translations' => ['fil' => 'Salamat — ${years}', 'ceb' => 'Salamat'],
    ])
        ->and($rewriter->confirmation($form, ['weight' => 'mass']))->toBeNull()
        ->and($rewriter->confirmation((new Form)->forceFill(['confirmation_message' => null]), ['age' => 'years']))->toBeNull();
});
