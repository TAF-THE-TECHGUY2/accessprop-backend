<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subscription agreement ready for countersignature</title>
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
                                Countersignature
                            </p>
                            <h1 style="margin:0; font-family: Georgia, 'Times New Roman', serif; font-size:28px; line-height:1.25; color:#0b0b0b; font-weight:normal;">
                                A subscription agreement is ready for your signature
                            </h1>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:24px 48px 8px 48px; font-size:15px; line-height:1.7; color:#1a1a1a;">
                            <p style="margin:0 0 20px 0;">Dear {{ $firstName }},</p>

                            <p style="margin:0 0 20px 0;">
                                The subscription documents for {{ $investorName }} in {{ $fundName }} are ready for the fund&rsquo;s countersignature. Once you have signed, the investor will be sent them to sign.
                            </p>

                            <p style="margin:0 0 28px 0; text-align:center;">
                                <a href="{{ $signUrl }}" style="display:inline-block; background-color:#0b0b0b; color:#ffffff; text-decoration:none; font-size:15px; padding:14px 36px; border-radius:10px;">
                                    Review and sign
                                </a>
                            </p>

                            <p style="margin:0 0 20px 0; font-size:13px; color:#777777;">
                                If the button doesn&rsquo;t work, copy and paste this link into your browser:
                            </p>
                            <p style="margin:0 0 24px 0; font-size:13px; word-break:break-all;">
                                <a href="{{ $signUrl }}" style="color:#0b0b0b;">{{ $signUrl }}</a>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:32px 48px 40px 48px;">
                            <hr style="border:none; border-top:1px solid #eeeeee; margin:0 0 20px 0;">
                            <p style="margin:0; font-size:12px; line-height:1.6; color:#999999;">
                                This link opens the documents for signing without a sign-in, so please don&rsquo;t forward this email.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
