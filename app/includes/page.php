<?php
/**
 * page.php: the simple card layout used by backup.php and setup.php (same look as the
 * calendar, light or dark following the device).
 */

function page_start($title) {
    $title = h($title);
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root { --bg: #f3f4f6; --card: #ffffff; --text: #1f2937; --muted: #6b7280; --border: #e5e7eb; --btn: #1f2937; --btn-hover: #000000; --btn-text: #ffffff; --error-bg: #f8d7da; --error-text: #721c24; --ok-bg: #d4edda; --ok-text: #155724; --box: #f9fafb; color-scheme: light; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #111827; --card: #1f2937; --text: #f3f4f6; --muted: #9ca3af; --border: #374151; --btn: #3788d8; --btn-hover: #2b6cb0; --btn-text: #ffffff; --error-bg: #4c1d1d; --error-text: #fecaca; --ok-bg: #14532d; --ok-text: #bbf7d0; --box: #18212e; color-scheme: dark; }
        }
        body { font-family: 'Inter', system-ui, sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 40px 16px; }
        .card { max-width: 460px; margin: 0 auto; background: var(--card); padding: 28px 24px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        h1 { font-size: 1.4rem; margin: 0 0 8px 0; }
        p { color: var(--muted); font-size: 0.95rem; line-height: 1.6; margin: 0 0 16px 0; }
        label { display: block; font-size: 0.85rem; font-weight: 600; color: var(--muted); margin: 16px 0 6px 0; }
        .hint { font-size: 0.8rem; margin: 6px 0 0 0; }
        input[type=text], input[type=password] { width: 100%; box-sizing: border-box; height: 44px; padding: 0 12px; font: inherit; font-size: 16px; border: 1px solid var(--border); border-radius: 6px; background: var(--bg); color: var(--text); }
        input[type=file] { width: 100%; box-sizing: border-box; font: inherit; font-size: 0.9rem; color: var(--text); }
        .box { border: 1px solid var(--border); background: var(--box); border-radius: 8px; padding: 4px 14px 14px; margin-top: 20px; }
        button, .button { display: block; box-sizing: border-box; width: 100%; margin-top: 20px; background: var(--btn); color: var(--btn-text); border: none; padding: 12px; border-radius: 8px; font: inherit; font-weight: 600; cursor: pointer; text-align: center; text-decoration: none; }
        button:hover, .button:hover { background: var(--btn-hover); }
        button:disabled, input:disabled { opacity: 0.5; cursor: not-allowed; }
        .error, .ok { padding: 10px 12px; border-radius: 6px; font-weight: 500; line-height: 1.5; }
        .error { background: var(--error-bg); color: var(--error-text); }
        .ok { background: var(--ok-bg); color: var(--ok-text); }
        .note { font-size: 0.85rem; margin: 20px 0 0 0; padding-top: 16px; border-top: 1px solid var(--border); }
        code { font-size: 0.85em; }
        a { color: inherit; }
    </style>
</head>
<body>
    <main class="card">
HTML;
}

function page_end() {
    echo "\n    </main>\n</body>\n</html>\n";
}
