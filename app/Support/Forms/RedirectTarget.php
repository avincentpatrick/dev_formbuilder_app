<?php

declare(strict_types=1);

namespace App\Support\Forms;

/**
 * Where a respondent goes after the thank-you screen, as an author set it (M130, `R-db169c29`, `D76`): nowhere,
 * another form of the workspace, or a web address. One value rather than two nullable columns passed side by side,
 * so a caller cannot ask for both at once — the database refuses that too (`forms_redirect_one_target_check`).
 */
final readonly class RedirectTarget
{
    private function __construct(
        public ?string $formId,
        public ?string $url,
    ) {}

    public static function none(): self
    {
        return new self(null, null);
    }

    public static function form(string $formId): self
    {
        return new self($formId, null);
    }

    public static function url(string $url): self
    {
        return new self(null, $url);
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
