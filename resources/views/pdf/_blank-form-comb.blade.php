{{--
    One comb field's answer area (Increment I12) - a row of separated character boxes, optionally
    under a second row of captions. `$groups` is a list of {cells, caption} from
    BlankFormPrintPresenter::combGroups().

    Extracted into its own partial only because it is the one area with real structure; every other
    area in `pdf.blank-form` is a div or a short loop.

    -- WHY TWO ROWS AND NOT A CAPTION PER CELL ------------------------------------------------------
    A date reads `[ ][ ] [ ][ ] [ ][ ][ ][ ]` with DD / MM / YYYY centred UNDER each group, so the
    caption spans its group rather than repeating per box. That is the whole reason a handwritten
    date is machine-readable at all: 03/04 is the 3rd of April or the 4th of March depending on who
    filled it in, and no recognizer can resolve that from the ink alone.

    The caption row is emitted only when some group HAS a caption, so a plain text field gets one row
    and no empty second one - an empty row still takes vertical space in dompdf.

    -- THE GAP CELLS ARE STRUCTURAL, NOT DECORATION -------------------------------------------------
    Groups are separated by a borderless spacer cell rather than by margin, because the caption row
    has to line up with the boxes above it and only a shared table geometry guarantees that. The gap
    is skipped before the first group.

    -- border-collapse MUST STAY separate -----------------------------------------------------------
    `border-spacing` is what puts air between the cells. Collapsing the borders merges every cell
    wall into one continuous ruled line and the comb silently stops being a comb - the single change
    to this file most likely to destroy the increment's purpose while still rendering something that
    looks deliberate.
--}}
@php
    $hasCaptions = false;
    foreach ($groups as $group) {
        if ($group['caption'] !== null) {
            $hasCaptions = true;
            break;
        }
    }
@endphp
<table class="comb">
    <tr>
        @foreach ($groups as $group)
            @if (! $loop->first)
                <td class="comb__gap"></td>
            @endif
            @for ($i = 0; $i < $group['cells']; $i++)
                <td></td>
            @endfor
        @endforeach
    </tr>
    @if ($hasCaptions)
        <tr class="comb__caption">
            @foreach ($groups as $group)
                @if (! $loop->first)
                    <td class="comb__gap"></td>
                @endif
                <td colspan="{{ $group['cells'] }}">{{ $group['caption'] }}</td>
            @endforeach
        </tr>
    @endif
</table>
