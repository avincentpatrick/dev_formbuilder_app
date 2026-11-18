{{--
    A form's glossary on paper (M245, `R-d0bbb7bb`): each term and what it means, at the end of the blank printed form
    and of a response's PDF, from `App\Support\Forms\Glossary::fromSnapshot()`. A note's marked words print as plain
    text; this is where their meanings are.

    The escaping contract is the including view's, and every literal here is ASCII. `PrintedFormMatcher` knows each
    line this prints (`PrintedContent::glossaryLines()`), so a scan never reads the glossary as an answer.
--}}
<div class="gloss">
    <h2 class="gloss__title">Glossary</h2>
    @foreach ($glossary as $entry)
        <p class="gloss__entry"><span class="nc-b">{{ $entry['term'] }}</span> - {{ $entry['definition'] }}</p>
    @endforeach
</div>
