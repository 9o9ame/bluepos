<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>BluePOS {{ $heading }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f6f8;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1f2933;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f4f6f8;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="560" cellspacing="0" cellpadding="0" style="width:560px;max-width:100%;background-color:#ffffff;border:1px solid #d9e2ec;border-radius:8px;">
                    <tr>
                        <td style="background-color:#0f2744;color:#ffffff;padding:20px 28px;">
                            <div style="font-size:12px;letter-spacing:0.16em;font-weight:700;">BLUEPOS</div>
                            <div style="font-size:20px;font-weight:700;margin-top:4px;">{{ $heading }}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px;">
                            <p style="margin:0 0 16px;font-size:15px;line-height:1.5;">
                                A verification code was requested to {{ $reason }}.
                            </p>
                            <p style="margin:0 0 8px;font-size:13px;font-weight:700;letter-spacing:0.04em;color:#486581;">VERIFICATION CODE</p>
                            <p style="margin:0 0 16px;font-size:32px;letter-spacing:0.28em;font-weight:700;color:#0f2744;">{{ $code }}</p>
                            <p style="margin:0 0 16px;font-size:15px;line-height:1.5;">
                                This code expires in <strong>{{ $ttl_minutes }} minutes</strong>.
                            </p>
                            <p style="margin:0;font-size:13px;line-height:1.5;color:#627d98;">
                                If you did not request this action, you can ignore this email and review your account security.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 28px;border-top:1px solid #e4ebf1;font-size:12px;color:#829ab1;">
                            BluePOS never asks for your password, device secret, or session token by email.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
