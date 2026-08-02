<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Forms\StepGraphInspector;

/**
 * The kinds of publish-time relevance-graph NOTICE {@see StepGraphInspector} can raise (Doc #27 §6).
 * Increment H21d1 — the increment that gave the notices a second surface and therefore a need to be
 * addressable one at a time.
 *
 * Until H21d1 a notice was a finished sentence in a flat `list<string>`, flashed after a publish that had
 * already succeeded. The logic canvas attaches each one to the node it names, so the kind and the node
 * membership had to stop being buried in the prose. This enum is the seam.
 *
 * NONE OF THESE IS A REFUSAL, and that is the whole posture of §6 rather than a property of any one case:
 * every check here would otherwise run against the DRAFT, and `PublishService::publish()` step 9 clones the
 * just-published structure forward, so a refusal would make an already-live form un-editable over a rule its
 * author wrote before the rule existed.
 *
 * A FOURTH KIND DELIBERATELY DOES NOT LIVE HERE — the per-node *syntax error*. The canvas raises it
 * client-side, live, from its own parse, because an author fixing a half-typed condition needs the answer
 * between keystrokes and not after the next autosave settles. Server-side, an unparseable expression is
 * simply the publish gate's refusal (`ExpressionValidationGate`), which is a different thing said by a
 * different class at a different time.
 */
enum GraphNoticeKind: string
{
    /** §3.1 — a `relevant_expression` that names something later in the form. Legal, late, and warned. */
    case ForwardReference = 'forward_reference';

    /** §3.2 — mutually dependent relevance, where the result depends on the order the settle runs in. */
    case Cycle = 'cycle';

    /** §4.1 — the decidable half of emptiness: a form that renders no step at all against no answers. */
    case EmptyAtOpen = 'empty_at_open';
}
