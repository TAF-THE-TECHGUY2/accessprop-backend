<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset your Access Properties password</title>
</head>
<body style="margin:0; padding:0; background-color:#f5f5f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color:#1a1a1a;">
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color:#f5f5f5; padding:40px 0;">
        <tr>
            <td align="center">
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="600" style="max-width:600px; width:100%; background-color:#ffffff; border-radius:12px; overflow:hidden;">
                    <tr>
                        <td style="padding:40px 48px 24px 48px; border-bottom:1px solid #e5e5e5;">
                            <span style="font-size:14px; letter-spacing:0.08em; color:#666666; text-transform:uppercase;">
                                Access Properties
                            </span>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:32px 48px 8px 48px;">
                            <p style="margin:0 0 12px 0; font-size:12px; letter-spacing:0.12em; color:#888888; text-transform:uppercase;">
                                Password Reset
                            </p>
                            <h1 style="margin:0; font-family: Georgia, 'Times New Roman', serif; font-size:28px; line-height:1.25; color:#0b0b0b; font-weight:normal;">
                                Reset your password
                            </h1>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:24px 48px 8px 48px; font-size:15px; line-height:1.7; color:#1a1a1a;">
                            <p style="margin:0 0 20px 0;">Dear {{ $firstName }},</p>

                            <p style="margin:0 0 20px 0;">
                                We received a request to reset the password for your Access Properties
                                investor account. Click the button below to choose a new password.
                            </p>

                            <p style="margin:0 0 28px 0; text-align:center;">
                                <a href="{{ $resetUrl }}" style="display:inline-block; background-color:#0b0b0b; color:#ffffff; text-decoration:none; font-size:15px; padding:14px 36px; border-radius:10px;">
                                    Reset password
                                </a>
                            </p>

                            <p style="margin:0 0 20px 0; font-size:13px; color:#777777;">
                                This link expires in 60 minutes. If the button doesn&rsquo;t work, copy and
                                paste this URL into your browser:
                            </p>
                            <p style="margin:0 0 24px 0; font-size:13px; word-break:break-all;">
                                <a href="{{ $resetUrl }}" style="color:#0b0b0b;">{{ $resetUrl }}</a>
                            </p>

                            <p style="margin:0 0 20px 0;">
                                If you didn&rsquo;t request a password reset, you can safely ignore this
                                email &mdash; your password will remain unchanged.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:32px 48px 40px 48px;">
                            <hr style="border:none; border-top:1px solid #eeeeee; margin:0 0 20px 0;">
                            <p style="margin:0; font-size:12px; line-height:1.6; color:#999999;">
                                This email was sent to {{ $investor->email }} because a password reset was
                                requested for this Access Properties account. If this wasn&rsquo;t you,
                                please contact support immediately.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
