<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Per-version lifecycle (data-dictionary §3). `draft` = the single mutable, editable version;
 * `published` = immutable and currently live; `superseded` = immutable, replaced by a later publish
 * but still addressable by historical submissions. The published-immutability RLS guard on the three
 * content child tables keys on `draft` (form-versioning-schema-migration.md §2).
 */
enum FormVersionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Superseded = 'superseded';
}
