<x-mail::message>
# Join {{ $organizationName }}

You have been invited to join {{ $organizationName }} as {{ $roleLabel }}.

<x-mail::button :url="$acceptUrl">
Accept invitation
</x-mail::button>

Open the link while signed in with this email address. The invitation expires in 7 days and works once. If you did not expect it, you can ignore this email.

Thanks,<br>
FlowMV
</x-mail::message>
