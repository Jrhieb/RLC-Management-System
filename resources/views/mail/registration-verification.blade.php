<x-mail::message>
# Verify your email

@if ($verificationCode !== null)
Enter this verification code in the RLCP registration screen:

# {{ $verificationCode }}

This code expires in {{ $expiresInMinutes }} minutes and can be used once. Requesting another code invalidates the previous code. Return to the browser where you requested it.
@else
Verify your email to continue registering a caseworker account. This link expires in {{ $expiresInMinutes }} minutes and can be used once. Requesting another link invalidates this one.

<x-mail::button :url="$verificationUrl">
Verify email
</x-mail::button>
@endif

After verification, you will have 15 minutes to enter your name and password. Your completed account will await Director approval.

If you did not request registration, you can ignore this email.
</x-mail::message>
