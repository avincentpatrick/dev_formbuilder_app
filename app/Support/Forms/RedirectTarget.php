<?php

declare(strict_types=1);

namespace App\Support\Forms;

/**
 * Where a respondent goes after the thank-you screen, as an author set it (M130, `R-db169c29`, `D76`): nowhere,
 * another form of the workspace, or a web address. One value rather than two nullable columns passed side by side,
 * so a caller cannot ask for both at once — the database refuses that too (`forms_redirect_one_target_check`).
 *
 * M138 (`R-df7f4b62`, `D91`): a destination also carries how long the thank-you screen waits before it moves —
 * one of {@see DELAYS}. Null leaves the stored delay as it is, which is what "stay on the thank-you screen" means for it.
 */
final readonly class RedirectTarget
{
    /** The delays a form builder may choose, in seconds; `forms_redirect_delay_seconds_check` holds the same list. */
    public const array DELAYS = [5, 10, 20, 30];

    /** `D76`'s delay, and still the default: the time WCAG 2.2.1 gives a person to stop a move. */
    public const int DEFAULT_DELAY = 20;

    private function __construct(
        public ?string $formId,
        public ?string $url,
        public ?int $delaySeconds = null,
    ) {}

    public static function none(): self
    {
        return new self(null, null);
    }

    public static function form(string $formId, ?int $delaySeconds = null): self
    {
        return new self($formId, null, $delaySeconds);
    }

    public static function url(string $url, ?int $delaySeconds = null): self
    {
        return new self(null, $url, $delaySeconds);
    }

    /** @return 'none'|'form'|'url' */
    public function kind(): string
    {
        return match (true) {
            $this->formId !== null => 'form',
            $this->url !== null => 'url',
            default => 'none',
        };
    }
}
