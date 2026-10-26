<?php

namespace App\Http\Requests\Organizations;

use App\Enums\OrganizationRole;
use App\Support\ActiveMembershipLocator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMembershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        $locator = app(ActiveMembershipLocator::class);
        $organization = $locator->organizationFrom($this);
        $membership = $locator->membership($organization, (string) $this->route('membership'));
        $this->attributes->set('resolved_membership', $membership);

        return $this->user()->can('update', $membership);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::enum(OrganizationRole::class)],
        ];
    }
}
