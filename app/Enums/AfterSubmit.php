<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a form offers a respondent after Submit — its "After submitting" setting (M248, `D232`).
 *
 * Guests do not sign in, so `Amend` and `Once` hold for one device only: the device remembers it sent a response, and a
 * respondent on another phone or in another browser starts afresh. That is the decision, not a gap (`D232` answer 2).
 */
enum AfterSubmit: string
{
    /** The thank-you page offers "Submit another response". The default, and what every form did before M248. */
    case Another = 'another';

    /**
     * The respondent may change the response they sent while the form still accepts responses and staff have not acted
     * on it (`D232` answer 3): reopening the form's link on the same device offers "Change my answers", and the thank-you
     * page gives a link to it that works on any device.
     */
    case Amend = 'amend';

    /** Nothing more: reopening the form's link on that device says the form was already answered. */
    case Once = 'once';
}
