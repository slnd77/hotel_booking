<?php

namespace App\Events;

use App\Models\AccountDataRequest;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Every row and file of the account was deleted (its deletion request is kept, completed).
 */
class AccountDataPurged
{
    use Dispatchable;

    public function __construct(public AccountDataRequest $request) {}
}
