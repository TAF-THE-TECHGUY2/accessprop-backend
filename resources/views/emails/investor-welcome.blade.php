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
                            <p style="margin:0 0 12px 0; font-size:12px; letter-spacing:0.12em; color:#888888; text-transform:uppercase;">
                                Welcome Letter
                            </p>
                            <h1 style="margin:0; font-family: Georgia, 'Times New Roman', serif; font-size:28px; line-height:1.25; color:#0b0b0b; font-weight:normal;">
                                Welcome to Access Properties
                            </h1>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:24px 48px 8px 48px; font-size:15px; line-height:1.7; color:#1a1a1a;">
                            <p style="margin:0 0 24px 0; color:#444444;">
                                From the Desk of Dionysios Kaskarelis, Founder and Chief Executive Manager
                            </p>

                            <p style="margin:0 0 20px 0;">Dear {{ $firstName }},</p>

                            <p style="margin:0 0 20px 0;">
                                Welcome to Access Properties &mdash; we&rsquo;re truly thrilled to have you on board.
                                I want to personally thank you for placing your trust in us and to welcome you as a new Member.
                            </p>

                            <p style="margin:0 0 20px 0;">
                                At Access Properties, Members are not just investors &mdash; they are long-term partners.
                                Your participation makes you part of a growing community that shares a belief that real estate
                                investing should be accessible, transparent, and built for the long term.
                            </p>

                            <p style="margin:0 0 20px 0;">
                                Access Properties was created to broaden access to professionally managed real estate investing
                                through a fund-based model. Rather than investing deal-by-deal, Members invest into diversified
                                real estate investment funds where capital is pooled, and each Member owns a proportional interest
                                based on their investment amount. This structure is designed to support scale, diversification, and
                                a more institutional approach to real estate investing &mdash; while still keeping the experience
                                approachable and Member-first.
                            </p>

                            <p style="margin:0 0 20px 0;">
                                Your participation supports our current offering, <strong>Access Properties Real Estate
                                Diversified Income Fund I</strong>, and contributes to building a diversified portfolio
                                designed for long-term performance and stability.
                            </p>

                            <p style="margin:0 0 20px 0;">
                                At Access Properties, we place a strong emphasis on transparency and communication &mdash;
                                your Investor Dashboard is where this comes to life, giving you direct access to performance
                                updates, reporting, and key documents.
                            </p>

                            <p style="margin:0 0 24px 0;">
                                Thank you again for joining us and for your confidence in our mission. We&rsquo;re excited to
                                build the future of Access Properties together &mdash; and we&rsquo;re honored to have you with us
                                as a Member.
                            </p>

                            <p style="margin:0 0 4px 0;">Best regards,</p>
                            <p style="margin:0; font-family: Georgia, 'Times New Roman', serif; font-size:18px; color:#0b0b0b;">
                                Dionysios Kaskarelis
                            </p>
                            <p style="margin:0; font-size:13px; color:#777777;">
                                Founder and Chief Executive Manager
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:32px 48px 40px 48px;">
                            <hr style="border:none; border-top:1px solid #eeeeee; margin:0 0 20px 0;">
                            <p style="margin:0; font-size:12px; line-height:1.6; color:#999999;">
                                This email was sent to {{ $investor->email }} because you completed onboarding with Access Properties.
                                If you did not register, please contact support immediately.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
