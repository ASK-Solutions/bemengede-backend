<!doctype html>
<html lang="en">
<body style="font-family: Arial, sans-serif; color: #14211D; background: #F5F6F3; padding: 24px;">
<div style="max-width: 560px; margin: 0 auto; background: #FFFFFF; padding: 24px; border-radius: 12px;">
    <p style="font-size: 16px; line-height: 1.6;">{!! nl2br(e($body)) !!}</p>
    <hr style="border: none; border-top: 1px solid #E1E4DF; margin: 24px 0;">
    <p style="font-size: 13px; color: #5B6661; line-height: 1.6;">
        You're getting this because you opted in to Bemengede offers.
        <a href="{{ $unsubscribeUrl }}" style="color: #1E6B57;">Unsubscribe</a> at any time. It takes one click.
    </p>
    <p style="font-size: 12px; color: #5B6661;">{{ $business['legal_name'] }} · TIN {{ $business['tin'] }} · {{ $business['address'] }}</p>
</div>
</body>
</html>
