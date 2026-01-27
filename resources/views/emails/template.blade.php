<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $emailSubject }}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #374151;
            margin: 0;
            padding: 40px 0;
            background-color: #f9fafb;
        }
        .email-wrapper {
            width: 100%;
            table-layout: fixed;
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
        }
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 12px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            overflow: hidden;
        }
        .email-content {
            padding: 40px;
            white-space: pre-wrap;
            word-wrap: break-word;
            font-size: 14px;
        }
        /* Match Vue Preview prose-sm styles */
        .email-content p {
            margin-top: 0;
            margin-bottom: 1.25em;
        }
        .email-content a {
            color: #1299A7;
            text-decoration: underline;
            font-weight: 500;
        }
        .email-content ul, .email-content ol {
            margin-top: 1.25em;
            margin-bottom: 1.25em;
            padding-left: 1.625em;
        }
        .email-content li {
            margin-top: 0.5em;
            margin-bottom: 0.5em;
        }
        .footer {
            padding: 24px 40px;
            background-color: #f9fafb;
            border-top: 1px solid #e5e7eb;
            font-size: 12px;
            color: #6b7280;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="email-wrapper">
        <div class="email-container">
            <div class="email-content">{!! $emailBody !!}</div>
        </div>
    </div>
</body>
</html>
