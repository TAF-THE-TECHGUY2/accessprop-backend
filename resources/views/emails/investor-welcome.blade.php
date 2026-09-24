<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to Access Properties</title>
</head>
<body style="margin:0; padding:0; background-color:#f5f5f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color:#1a1a1a;">
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color:#f5f5f5; padding:40px 0;">
        <tr>
            <td align="center">
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="600" style="max-width:600px; width:100%; background-color:#ffffff; border-radius:12px; overflow:hidden;">
                    <tr>
                        <td style="padding:40px 48px 24px 48px; border-bottom:1px solid #e5e5e5;">
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                <tr>
                                    <td style="font-size:14px; letter-spacing:0.08em; color:#666666; text-transform:uppercase;">
                                        Access Properties
                                    </td>
                                    <td align="right" style="font-size:12px; letter-spacing:0.08em; color:#999999; text-transform:uppercase;">
                                        Ref: {{ strtoupper($investorCode) }}
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:32px 48px 8px 48px;">
                            <h1 style="margin:0; font-family: Georgia, 'Times New Roman', serif; font-size:28px; line-height:1.25; color:#0b0b0b; font-weight:normal;">
                                Welcome to Access Properties
                            </h1>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:24px 48px 8px 48px; font-size:15px; line-height:1.7; color:#1a1a1a;">
                            <p style="margin:0 0 24px 0; color:#444444;">
                                From the Desk of Dionysios Kaskarelis, Founder and Chief Executive Officer
                            </p>

                            <p style="margin:0 0 20px 0;">Dear {{ $firstName }},</p>

                            <p style="margin:0 0 20px 0;">
                                Welcome to Access Properties, and thank you for creating your investor account.
                            </p>

                            <p style="margin:0 0 20px 0;">
                                I founded Access Properties with a simple objective: to make professionally managed
                                private real estate investing more accessible, transparent, and straightforward through
                                a modern investor experience.
                            </p>

                            <p style="margin:0 0 20px 0;">
                                Your account gives you access to the accredited investor pathway for
                                <strong>Access Real Estate Fund I</strong>, our Greater Boston residential real estate
                                investment vehicle. Through the investor portal, you can review the offering documents
                                and complete each step of the investment process securely online.
                            </p>

                            <p style="margin:0 0 20px 0;">
                                The process includes reviewing the offering materials, verifying your identity and
                                accredited investor status, completing the applicable subscription documents, and
                                submitting investment funding. <strong>Creating an account does not commit you to
                                invest, and any investment remains subject to completion of the applicable
                                qualification, subscription, acceptance, and funding process.</strong>
                            </p>

                            <p style="margin:0 0 20px 0;">
                                Access Real Estate Fund I is advised by <strong>Access Investment Management,
                                Inc.</strong>, a Massachusetts-registered investment adviser. The fund pursues income
                                and long-term value through residential real estate investment, supported by active
                                investment oversight and property-level execution.
                            </p>

                            <p style="margin:0 0 20px 0;">
                                Transparency and communication are central to the Access experience. As you proceed,
                                your investor portal will provide access to offering documents, account information,
                                communications, and, once an investment has been accepted, ongoing reporting and
                                investment information.
                            </p>

                            <p style="margin:0 0 24px 0;">
                                Thank you again for your interest in Access Properties. I look forward to having you
                                explore the offering and learn more about what we are building.
                            </p>

                            <p style="margin:0 0 4px 0;">Best regards,</p>
                            <p style="margin:0; font-family: Georgia, 'Times New Roman', serif; font-size:18px; color:#0b0b0b;">
                                Dionysios Kaskarelis
                            </p>
                            <p style="margin:0 0 24px 0; font-size:13px; color:#777777;">
                                Founder &amp; CEO | Access Properties LLC
                            </p>

                            {{-- Wordmark drawn in HTML rather than linked as an image: remote images
                                 are blocked by default in most clients, and the alt text of a broken
                                 logo is a worse first impression than no logo at all. --}}
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td width="56" height="56" align="center" valign="middle" style="width:56px; height:56px; background-color:#0b0b0b; border-radius:4px; font-family: Georgia, 'Times New Roman', serif; font-size:20px; letter-spacing:0.04em; color:#ffffff;">
                                        AP
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:32px 48px 40px 48px;">
                            <hr style="border:none; border-top:1px solid #eeeeee; margin:0 0 20px 0;">
                            <p style="margin:0; font-size:12px; line-height:1.6; color:#999999;">
                                This email was sent to {{ $investor->email }} because an investor account was created
                                with Access Properties. If you did not create this account, please contact support
                                immediately.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
