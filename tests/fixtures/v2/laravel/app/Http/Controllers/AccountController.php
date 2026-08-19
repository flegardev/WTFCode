<?php

namespace App\Http\Controllers;

use App\Models\Account;

final class AccountController
{
    public function show(): Account
    {
        return Account::findOrFail(1);
    }
}
