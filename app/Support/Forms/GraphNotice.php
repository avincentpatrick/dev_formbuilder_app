<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Enums\GraphNoticeKind;
use App\Services\Forms\StepGraphInspector;

/**
 * One relevance-graph notice, addressable (Doc #27 §6). Increment H21d1.
 *
 * Pure and static-shaped like {@see StepDescriptor}: {@see StepGraphInspector} decides everything, this
 * carries it. The reason it exists at all is that H21d1 gave the notices a SECOND surface with different
 * needs from the first:
 *
 *  - the publish flash wants ONE sentence per kind, joining every instance ("Forward reference: a…; b…."),
 *    because it appears once, after the fact, above a page the author is leaving;
 *  - the logic canvas wants each instance attached to the NODE it names, because the author is looking at
 *    that node and nothing else.
 *
 * Hence the two strings, and they are deliberately not one. {@see $message} is a complete sentence that
 * reads correctly alone, under a node, with no prefix; {@see $fragment} is the clause the flash joins with
 * its siblings and wraps in the kind's prefix and closing explanation. A single string cannot be both — the
 * cycle case proves it, where the fragment is “a” ⇄ “b” and reads as nothing at all on its own.
 */
final readonly class GraphNotice
{
    /**
     * @param  list<string>  $nodes  the section/field keys this notice is ABOUT, in the order they are named;
     *                               empty for a form-level notice that belongs to no single node
     * @param  string  $message  a standalone sentence, for display beside a node
     * @param  string  $fragment  the clause {@see StepGraphInspector::warnings()} joins into the flash sentence
     */
    public function __construct(
        public GraphNoticeKind $kind,
        public array $nodes,
        public string $message,
        public string $fragment,
    ) {}

    /** @return array{kind: string, nodes: list<string>, message: string} */
    public function toArray(): array
    {
        // `fragment` is deliberately NOT shipped to the client: it is the flash's joining clause and reads
        // as a sentence fragment anywhere else, which is exactly the mistake this class exists to prevent.
        return [
            'kind' => $this->kind->value,
            'nodes' => $this->nodes,
            'message' => $this->message,
        ];
    }
}
