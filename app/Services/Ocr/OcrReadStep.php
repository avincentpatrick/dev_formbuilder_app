<?php

declare(strict_types=1);

namespace App\Services\Ocr;

/**
 * What one run of the reading job leaves to do next (M128). The job maps each to a queue action and
 * nothing else; the scan row already records why.
 */
enum OcrReadStep
{
    /** Nothing moved: a page is waiting for its virus scan, or the provider asked to be tried again later. */
    case Wait;

    /** A page was read and another is left: queue the next run now. */
    case Continue;

    /** The scan reached `read` or `failed`. */
    case Done;
}
