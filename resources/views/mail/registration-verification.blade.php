<x-mail::message>
# Verify your email

Verify your email to continue registering a caseworker account. This link expires in {{ $expiresInMinutes }} minutes and can be used once. Requesting another link invalidates this one.

<x-mail::button :url="$verificationUrl">
Verify email
</x-mail::button>

After verification, you will have 15 minutes to enter your name and password. Your completed account will await Director approval.

If you did not request registration, you can ignore this email.
</x-mail::message>
