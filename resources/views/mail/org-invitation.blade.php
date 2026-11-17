<x-mail::message>
# Join {{ $organizationName }}

@if ($inviterName)
{{ $inviterName }} has invited you to join **{{ $organizationName }}** as **{{ $roleNames }}**.
@else
You have been invited to join **{{ $organizationName }}** as **{{ $roleNames }}**.
@endif

<x-mail::button :url="$acceptUrl">
Accept invitation
</x-mail::button>

This invitation expires on {{ $expiresAt }}. If you weren't expecting it, you can ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
