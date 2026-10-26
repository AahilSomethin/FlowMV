<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\SendEmailVerification;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EmailVerificationController extends Controller
{
    public function verify(Request $request, string $id, string $hash): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User || (string) $user->getKey() !== $id) {
            abort(403);
        }

        $expected = sha1($user->email);

        if (strlen($hash) !== strlen($expected) || ! hash_equals($expected, $hash)) {
            abort(403);
        }

        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return response()->json([
            'data' => ['verified' => true],
        ]);
    }

    public function store(Request $request, SendEmailVerification $send): Response
    {
        $send($request->user());

        return response()->noContent();
    }
}
