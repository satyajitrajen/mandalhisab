<x-mail::message>
# Reset your password

Hello {{ $name }},

We received a request to reset your MandalHishob password. This link expires in **60 minutes** and can be used only once.

<x-mail::button :url="$resetUrl">
Reset password
</x-mail::button>

If the button does not work, copy your one-time reset code into the app:

<x-mail::panel>
{{ $token }}
</x-mail::panel>

If you did not request this, you can safely ignore this email. Your password will not change.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
