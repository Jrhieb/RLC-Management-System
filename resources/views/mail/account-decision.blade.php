<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="font-family: sans-serif; line-height: 1.6; color: #18181b;">
    <h1 style="font-size: 22px;">{{ $approved ? __('Your account has been approved') : __('Your account request was rejected') }}</h1>
    <p>{{ $approved ? __('A Director has approved your Caseworker account. You can sign in to continue.') : __('A Director has rejected your account request. Sign in to view the reason on your account-status screen.') }}</p>
    <p><a href="{{ $url }}">{{ $approved ? __('Sign In') : __('View account status') }}</a></p>
</body>
</html>
