<?php

namespace App\Events;

use App\Models\AccountDataRequest;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The account's data is about to be deleted; a listener throwing stops it.
 */
class AccountDataPurging
{
    use Dispatchable;

    public function __construct(public AccountDataRequest $request) {}
}
