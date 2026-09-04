<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The fields a claim must answer, pinned so the check that derives them cannot go blind (M69).
|--------------------------------------------------------------------------
| `docs/claims/TEMPLATE.md` is the ONE authority for what a claim has to answer, and
| `scripts/preflight.php` refuses an ACTIVE claim that is missing any field the template declares.
| Deriving rather than restating is deliberate — a second copy of the protocol is the exact defect
| that template was created to end, after it lived as a duplicate in both lane files and drifted.
|
| ⛔ BUT A DERIVED CHECK INHERITS ITS SOURCE'S FAILURES, AND THAT IS WHAT THIS FILE IS FOR. Delete a
| heading from the template and preflight does not go red — it quietly stops requiring that field,
| which is worse than a missing gate because the gate still reports `[ok]`. The blast radius is one
| that has already been paid for: the premise field exists because M45, M60, M67 and M68 each shipped
| with a row whose evidence held, whose remedy was implementable, and whose PREMISE had expired.
|
| ⚠️ THIS IS THE STRUCTURAL HALF OF A PAIR, AND M43 IS THE REASON IT IS ONLY A HALF. That increment
| measured a gate whose four structural cases stayed green after the production mechanism they named
| was deleted — fully green and entirely decorative. So the behavioural half is a mutation control on
| `preflight.php` itself (drop the heading from a real claim's active block, watch it refuse), and it
| is recorded in the M69 release. Neither half is evidence on its own.
|
| ⚠️ WHAT THIS FILE DOES NOT CLAIM. It never asserts that a claim's premise answer is CORRECT — no
| more than `BacklogProvenanceTest` can check that a liveness verdict is right. What is gated is that
| the question is ASKED and that an active claim ANSWERS it. A claim that writes "held" under every
| heading passes everything here, and that residual is filed rather than papered over.
*/

use Illuminate\Support\Str;

/**
 * The `###` headings declared inside the template's `## Opening a claim` fenced block.
 *
 * Deliberately a second, independent implementation of `claim_template_fields()` in
 * `scripts/preflight.php` rather than a call into it: that file is a top-to-bottom script which runs
 * `git fetch` and probes Docker on include, so it cannot be required from a test. Two readers of one
 * document is the acceptable duplication here — the DOCUMENT stays the single authority, and the
 * pair disagreeing is itself a signal.
 *
 * @return list<string>
 */
function templateClaimFields(string $template): array
{
    $fields = [];
    $inSection = false;
    $inFence = false;

    // explode(), never a `\R` split without /u — that matches the byte 0x85 INSIDE a UTF-8 character
    // (`✅` is E2 9C 85) and silently shifts every line after the first (M42). This file is full of them.
    foreach (explode("\n", $template) as $line) {
        $line = rtrim($line, "\r");

        // The fence is tested BEFORE the heading: inside it, a `## ` line is example content, and the
        // template's example opens with `## Status: ACTIVE CLAIM …`.
        if ($inFence) {
            if (Str::startsWith($line, '```')) {
                break;
            }

            if (Str::startsWith($line, '### ')) {
                $fields[] = trim(Str::after($line, '### '));
            }

            continue;
        }

        if (Str::startsWith($line, '```')) {
            $inFence = $inSection;

            continue;
        }

        if (Str::startsWith($line, '## ')) {
            if ($inSection) {
                break;
            }

            $inSection = Str::startsWith($line, '## Opening a claim');
        }
    }

    return $fields;
}

it('declares exactly the three fields a claim must answer, in order', function (): void {
    $fields = templateClaimFields(file_get_contents(base_path('docs/claims/TEMPLATE.md')));

    // Compared as a whole ORDERED list rather than three `toContain` calls — partly so a failure
    // prints both lists side by side, and partly because `toContain`'s needles are VARIADIC, which is
    // how M30 watched a case stay green with the wrong value sitting in the array.
    expect($fields)->toBe([
        'Evidence verified',
        'Premise verified',
        'Remedy verdict',
    ]);
});

it('keeps preflight the only enforcer, and keeps it deriving rather than restating', function (): void {
    $preflight = file_get_contents(base_path('scripts/preflight.php'));

    // The check must READ the template. If someone replaces the derivation with a hardcoded list,
    // this file's first arm goes on passing while the two descriptions drift — the defect the
    // template exists to prevent, reintroduced one level up.
    expect($preflight)->toContain('docs/claims/TEMPLATE.md')
        ->and($preflight)->toContain('claim_template_fields');

    // ⚠️ Asserted as an ABSENCE of the literal headings, which is the only mechanisable form of
    // "it did not copy them". A negative about an artifact is checkable; a negative about behaviour
    // is not, and this gate deliberately makes no claim about the latter.
    foreach (['### Evidence verified', '### Premise verified', '### Remedy verdict'] as $heading) {
        expect(Str::contains($preflight, "'".$heading."'"))->toBeFalse()
            ->and(Str::contains($preflight, '"'.$heading.'"'))->toBeFalse();
    }
});
