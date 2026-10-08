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

    Since layout 4 (`M148`) the box-row gap prints the group's `separator` - `/` in a date, `:` in a
    time - and the caption-row gap stays empty, because the reader recognises the caption row only
    when every word on it is a caption. Layouts 2 and 3 printed nothing here AND drew the gap as an
    answer box (see the stylesheet's specificity note), so a date read as ten boxes and a respondent
    could write a slash the reader then counted as part of the day. Only ASCII separators: the
    WinAnsi round trip below applies to them too.

    -- border-collapse MUST STAY separate -----------------------------------------------------------
    `border-spacing` is what puts air between the cells. Collapsing the borders merges every cell
    wall into one continuous ruled line and the comb silently stops being a comb - the single change
    to this file most likely to destroy the increment's purpose while still rendering something that
    looks deliberate.

    -- `$sample` IS FOR THE INSTRUCTION BANNER ONLY (layout 2) ---------------------------------------
    A list of characters printed one per cell, so the banner can show what a filled comb looks like.
    Every question's comb passes null and prints empty cells: ink inside a comb cell is what the
    reader takes as the answer.
--}}
@use('Illuminate\Support\Arr')
<table class="comb">
    <tr>
        @php($cell = 0)
        @foreach ($groups as $group)
            @if (! $loop->first)
                <td class="comb__gap">{{ $group['separator'] ?? '' }}</td>
            @endif
            @for ($i = 0; $i < $group['cells']; $i++)
                <td>{{ $sample[$cell++] ?? '' }}</td>
            @endfor
        @endforeach
    </tr>
    @if (Arr::first($groups, fn (array $g): bool => $g['caption'] !== null) !== null)
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
