<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Notifications\VerifyEmailAddress;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendEmailVerification
{
    public function __invoke(User $user): void
    {
        if ($user->email_verified_at !== null) {
            return;
        }

        try {
            $user->notify(new VerifyEmailAddress);
        } catch (Throwable) {
            Log::warning('Verification email could not be sent.', [
                'user_id' => $user->id,
            ]);
        }
    }
}
