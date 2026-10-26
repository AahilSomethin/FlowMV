<?php

namespace App\Http\Requests\Organizations;

use App\Support\InvitationPlaintext;
use Illuminate\Foundation\Http\FormRequest;

class AcceptInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:200'],
        ];
    }

    public function invitationToken(): string
    {
        $token = $this->attributes->get('invitation_token');

        return $token instanceof InvitationPlaintext ? $token->value() : '';
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $token = $this->invitationToken();

            if ($token !== '' && strlen($token) > 200) {
                $validator->errors()->add('token', 'This invitation is no longer valid.');
            }
        });
    }
}
