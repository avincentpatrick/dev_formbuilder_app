{{--
    A note's content blocks on paper (M245, `R-c083b711`): the blank printed form and a response's PDF, from
    `App\Support\Forms\PrintedContent::blocks()`. Printed in place of the note's label, as every screen shows them.

    The escaping contract is the including view's: EVERY interpolation uses the escaping braces, and Blade's raw-output
    directive appears nowhere (SubmissionPdfRendererTest scans this directory for it). Every literal this partial
    contributes is ASCII - the list markers are `-` and `1.` - because the PDF fonts are WinAnsi.

    A details block prints OPEN: its summary bold, its blocks set in below it. A list is a two-column table, because
    dompdf implements CSS 2.1 and a hanging marker is a table cell there. Runs sit on one source line each, so Blade
    adds no space between a bold word and the next.
--}}
@foreach ($blocks as $block)
    @if ($block['kind'] === 'heading')
        <p class="nc-h nc-h--{{ $block['level'] }}">{{ $block['text'] }}</p>
    @elseif ($block['kind'] === 'paragraph' || $block['kind'] === 'callout')
        <p @class(['nc-p', 'nc-callout' => $block['kind'] === 'callout'])>@foreach ($block['runs'] as $run)<span @class(['nc-b' => $run['bold'], 'nc-i' => $run['italic']])>{{ $run['text'] }}</span>@endforeach</p>
    @elseif ($block['kind'] === 'list')
        <table class="nc-list">
            @foreach ($block['items'] as $position => $runs)
                <tr><td class="nc-list__mark">{{ $block['ordered'] ? ($position + 1).'.' : '-' }}</td><td class="nc-list__item">@foreach ($runs as $run)<span @class(['nc-b' => $run['bold'], 'nc-i' => $run['italic']])>{{ $run['text'] }}</span>@endforeach</td></tr>
            @endforeach
        </table>
    @elseif ($block['kind'] === 'divider')
        <div class="nc-rule"></div>
    @elseif ($block['kind'] === 'picture')
        <p class="nc-p nc-picture">{{ $block['text'] }}</p>
    @elseif ($block['kind'] === 'details')
        @if ($block['summary'] !== '')
            <p class="nc-summary">{{ $block['summary'] }}</p>
        @endif
        <div class="nc-inner">@include('pdf._note-content', ['blocks' => $block['blocks']])</div>
    @endif
@endforeach
