@php
    $navy = '#1d326d';
    $gold = '#c79d51';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
</head>
<body style="margin:0;padding:0;background:#f5f6fa;font-family:Poppins,'Segoe UI',Arial,sans-serif;color:#1f2937;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f6fa;padding:32px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb;">
                <tr>
                    <td style="background:{{ $navy }};padding:22px 32px;">
                        <span style="color:#ffffff;font-size:18px;font-weight:600;letter-spacing:.2px;">FMDQ <span style="color:{{ $gold }};">e-Offering</span></span>
                    </td>
                </tr>
                <tr><td style="height:4px;background:{{ $gold }};line-height:4px;font-size:0;">&nbsp;</td></tr>
                <tr>
                    <td style="padding:32px;">
                        <h1 style="margin:0 0 16px;font-size:20px;line-height:1.35;color:{{ $navy }};">{{ $title }}</h1>
                        @if ($name)
                            <p style="margin:0 0 12px;font-size:14px;">Dear {{ $name }},</p>
                        @endif
                        <p style="margin:0 0 20px;font-size:14px;line-height:1.7;">{{ $body }}</p>

                        @if ($code)
                            <div style="margin:0 0 24px;text-align:center;">
                                <span style="display:inline-block;font-size:30px;letter-spacing:10px;font-weight:700;color:{{ $navy }};background:#f8f6f0;border:1px solid {{ $gold }};border-radius:10px;padding:12px 22px;">{{ $code }}</span>
                            </div>
                        @endif

                        @if ($url)
                            <p style="margin:0 0 24px;">
                                <a href="{{ $url }}" style="display:inline-block;background:{{ $navy }};color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;padding:12px 26px;border-radius:8px;">{{ $button }}</a>
                            </p>
                            <p style="margin:0;font-size:12px;color:#6b7280;line-height:1.6;">If the button does not work, copy this link into your browser:<br><a href="{{ $url }}" style="color:{{ $navy }};word-break:break-all;">{{ $url }}</a></p>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td style="padding:18px 32px;background:#f8f9fa;border-top:1px solid #e5e7eb;font-size:11px;color:#6b7280;line-height:1.6;">
                        This is an automated message from the FMDQ e-Offering Platform. Please do not reply.<br>
                        Powered by iQx Consult Limited &middot; &copy; {{ date('Y') }} FMDQ Group PLC. All rights reserved.<br>
                        <em>FMDQ Depository Limited is registered and regulated by the Securities and Exchange Commission, Nigeria.</em>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
