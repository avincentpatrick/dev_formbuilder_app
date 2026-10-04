<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

/**
 * Renaming a forms-list folder (M131, `D79`): the same name rules as creating one. Its own class so the
 * route's request reads as what it does, and so the two can diverge without a flag.
 */
final class UpdateFormFolderRequest extends StoreFormFolderRequest {}
