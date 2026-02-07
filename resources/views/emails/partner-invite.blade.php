<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Partner Invite</title>

    <style>
        /* Email-safe basics */
        html, body { margin:0 !important; padding:0 !important; height:100% !important; width:100% !important; }
        * { -ms-text-size-adjust:100%; -webkit-text-size-adjust:100%; }
        table, td { mso-table-lspace:0pt; mso-table-rspace:0pt; }
        img { -ms-interpolation-mode:bicubic; border:0; outline:none; text-decoration:none; display:block; }
        a { text-decoration:none; }
        .ExternalClass { width:100%; }
        .ExternalClass, .ExternalClass p, .ExternalClass span, .ExternalClass font, .ExternalClass td, .ExternalClass div { line-height:100%; }

        /* Theme */
        .bg { background:#0F0F0F; }
        .container { width:100%; max-width:600px; }
        .card { background:#141414; border:1px solid rgba(255,119,0,0.22); border-radius:18px; }
        .divider {
            height:1px;
            background:linear-gradient(90deg, rgba(255,119,0,0) 0%, rgba(255,119,0,0.70) 50%, rgba(255,119,0,0) 100%);
        }
        .text { color:#F5F5F5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; }
        .muted { color:rgba(245,245,245,0.65); }
        .subtle { color:rgba(245,245,245,0.50); }
        .accent { color:#FF7700; }
        .h1 { font-size:34px; line-height:1.15; font-weight:800; letter-spacing:-0.02em; margin:0; }
        .p { font-size:15px; line-height:1.6; margin:0; }
        .li { font-size:15px; line-height:1.6; margin:0; padding:0; }
        .badge {
            display:inline-block;
            padding:8px 12px;
            border-radius:999px;
            border:1px solid rgba(255,119,0,0.25);
            background:rgba(255,119,0,0.10);
            font-size:12px;
            letter-spacing:0.02em;
        }

        /* Preheader hidden */
        .preheader {
            display:none !important;
            visibility:hidden;
            opacity:0;
            color:transparent;
            height:0;
            width:0;
            max-height:0;
            max-width:0;
            overflow:hidden;
            mso-hide:all;
        }

        @media screen and (max-width: 640px) {
            .px { padding-left:18px !important; padding-right:18px !important; }
            .py { padding-top:22px !important; padding-bottom:22px !important; }
            .h1 { font-size:30px !important; }
        }
    </style>
</head>

<body class="bg" style="background:#0F0F0F; margin:0; padding:0;">
    {{-- Preheader --}}
    <div class="preheader">
        {{ $invite->inviter->name }} invited you to support “{{ $invite->goal->title }}” on Trendsetter.
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="bg" style="background:#0F0F0F;">
        <tr>
            <td align="center" style="padding:28px 12px;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" class="container" style="max-width:600px; width:100%;">
                    {{-- Header --}}
                    <tr>
                        <td class="px" style="padding:0 26px 18px 26px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td align="left">
                                        <div class="text" style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;">
                                            <span class="accent" style="color:#FF7700; font-size:22px; font-weight:900; letter-spacing:-0.02em;">🔥</span>
                                            <span class="text" style="color:#F5F5F5; font-size:22px; font-weight:900; letter-spacing:-0.02em; margin-left:8px;">Trendsetter</span>
                                        </div>
                                    </td>
                                    <td align="right">
                                        <span class="badge text" style="border:1px solid rgba(255,119,0,0.25); background:rgba(255,119,0,0.10); color:#F5F5F5; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif; font-size:12px; border-radius:999px; padding:8px 12px; display:inline-block;">
                                            Partner Invite
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td class="px" style="padding:0 26px 18px 26px;">
                            <div class="divider" style="height:1px; background:linear-gradient(90deg, rgba(255,119,0,0) 0%, rgba(255,119,0,0.70) 50%, rgba(255,119,0,0) 100%);"></div>
                        </td>
                    </tr>

                    {{-- Main Card --}}
                    <tr>
                        <td class="px" style="padding:0 26px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="card"
                                   style="background:#141414; border:1px solid rgba(255,119,0,0.22); border-radius:18px;">
                                <tr>
                                    <td class="py" style="padding:26px;">
                                        <h1 class="h1 text" style="margin:0; color:#F5F5F5; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif; font-size:34px; line-height:1.15; font-weight:800; letter-spacing:-0.02em;">
                                            You’ve been invited.
                                        </h1>

                                        <p class="p muted" style="margin:12px 0 0 0; color:rgba(245,245,245,0.65); font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif; font-size:15px; line-height:1.6;">
                                            <span class="text" style="color:#F5F5F5; font-weight:700;">{{ $invite->inviter->name }}</span>
                                            wants you as an accountability partner for
                                            <span class="accent" style="color:#FF7700; font-weight:800;">“{{ $invite->goal->title }}”</span>.
                                        </p>

                                        <div style="height:16px; line-height:16px; font-size:16px;">&nbsp;</div>

                                        <p class="p subtle" style="margin:0; color:rgba(245,245,245,0.50); font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif; font-size:15px; line-height:1.6;">
                                            You’ll only see progress signals:
                                        </p>

                                        <div style="height:10px; line-height:10px; font-size:10px;">&nbsp;</div>

                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td width="34" valign="top" style="padding:10px 0;">
                                                    <span class="accent" style="color:#FF7700; font-size:18px;">🔥</span>
                                                </td>
                                                <td valign="top" style="padding:10px 0;">
                                                    <p class="p text" style="margin:0; color:#F5F5F5; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif; font-size:15px; line-height:1.6;">
                                                        Streak and consistency trends
                                                    </p>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td width="34" valign="top" style="padding:10px 0;">
                                                    <span class="accent" style="color:#FF7700; font-size:18px;">🕒</span>
                                                </td>
                                                <td valign="top" style="padding:10px 0;">
                                                    <p class="p text" style="margin:0; color:#F5F5F5; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif; font-size:15px; line-height:1.6;">
                                                        Recent log activity and pace status
                                                    </p>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td width="34" valign="top" style="padding:10px 0;">
                                                    <span class="accent" style="color:#FF7700; font-size:18px;">📅</span>
                                                </td>
                                                <td valign="top" style="padding:10px 0;">
                                                    <p class="p text" style="margin:0; color:#F5F5F5; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif; font-size:15px; line-height:1.6;">
                                                        Next upcoming scheduled event
                                                    </p>
                                                </td>
                                            </tr>
                                        </table>

                                        <div style="height:14px; line-height:14px; font-size:14px;">&nbsp;</div>

                                        <p class="p subtle" style="margin:0; color:rgba(245,245,245,0.50); font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif; font-size:15px; line-height:1.6;">
                                            You will <span class="text" style="color:#F5F5F5; font-weight:700;">not</span> see private reflections, mood notes, or AI plan text.
                                        </p>

                                        <div style="height:18px; line-height:18px; font-size:18px;">&nbsp;</div>

                                        <div class="divider" style="height:1px; background:linear-gradient(90deg, rgba(255,119,0,0) 0%, rgba(255,119,0,0.35) 50%, rgba(255,119,0,0) 100%);"></div>

                                        <div style="height:18px; line-height:18px; font-size:18px;">&nbsp;</div>

                                        <p class="p muted" style="margin:0; color:rgba(245,245,245,0.65); font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif; font-size:15px; line-height:1.6;">
                                            This invite expires on
                                            <span class="text" style="color:#F5F5F5; font-weight:700;">
                                                {{ $invite->expires_at?->toDayDateTimeString() }}
                                            </span>.
                                        </p>

                                        <div style="height:16px; line-height:16px; font-size:16px;">&nbsp;</div>

                                        {{-- Bulletproof button (Outlook-friendly) --}}
                                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto;">
                                            <tr>
                                                <td align="center">
                                                    <!--[if mso]>
                                                    <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word"
                                                        href="{{ $inviteUrl }}"
                                                        style="height:52px;v-text-anchor:middle;width:220px;" arcsize="20%" stroke="f" fillcolor="#FF7700">
                                                        <w:anchorlock/>
                                                        <center style="color:#0F0F0F;font-family:Arial,sans-serif;font-size:16px;font-weight:bold;">
                                                            Open Invite
                                                        </center>
                                                    </v:roundrect>
                                                    <![endif]-->
                                                    <!--[if !mso]><!-- -->
                                                    <a href="{{ $inviteUrl }}"
                                                       style="background:#FF7700; border-radius:14px; color:#0F0F0F; display:inline-block; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif; font-size:16px; font-weight:800; line-height:52px; text-align:center; width:220px;">
                                                        Open Invite
                                                    </a>
                                                    <!--<![endif]-->
                                                </td>
                                            </tr>
                                        </table>

                                        <div style="height:12px; line-height:12px; font-size:12px;">&nbsp;</div>

                                        <p class="p subtle" style="margin:0; color:rgba(245,245,245,0.50); font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif; font-size:13px; line-height:1.6; text-align:center;">
                                            This starts tracking immediately once accepted.
                                        </p>

                                        <div style="height:14px; line-height:14px; font-size:14px;">&nbsp;</div>

                                        <p class="p subtle" style="margin:0; color:rgba(245,245,245,0.50); font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif; font-size:12px; line-height:1.6;">
                                            If the button doesn’t work, copy and paste this link:
                                            <br />
                                            <a href="{{ $inviteUrl }}" class="accent" style="color:#FF7700; word-break:break-all;">{{ $inviteUrl }}</a>
                                        </p>

                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td class="px" style="padding:18px 26px 0 26px;">
                            <p class="p subtle" style="margin:0; color:rgba(245,245,245,0.50); font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif; font-size:12px; line-height:1.6; text-align:center;">
                                You received this because a Trendsetter user invited you as an accountability partner for one of their goals.
                            </p>

                            <div style="height:10px; line-height:10px; font-size:10px;">&nbsp;</div>

                            <p class="p subtle" style="margin:0; color:rgba(245,245,245,0.45); font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif; font-size:12px; line-height:1.6; text-align:center;">
                                If you didn’t expect this, you can ignore this email.
                            </p>

                            <div style="height:24px; line-height:24px; font-size:24px;">&nbsp;</div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
