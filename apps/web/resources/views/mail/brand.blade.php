@php
    $primary = config('product.email.primary', '#059669');
    $ink = config('product.email.ink', '#0B1324');
    $accent = config('product.email.accent', '#7C3AED');
    $background = config('product.email.background', '#F7F8FA');
@endphp
/* Brand mail theme — colors from config/product.php (email.*). Inlined by Laravel's Markdown renderer. */

body,
body *:not(html):not(style):not(br):not(tr):not(code) {
    box-sizing: border-box;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    position: relative;
}

body {
    -webkit-text-size-adjust: none;
    background-color: {{ $background }};
    color: #334155;
    height: 100%;
    line-height: 1.5;
    margin: 0;
    padding: 0;
    width: 100% !important;
}

p, ul, ol, blockquote {
    line-height: 1.6;
    text-align: start;
}

a {
    color: {{ $primary }};
}

a img {
    border: none;
}

h1 {
    color: {{ $ink }};
    font-size: 22px;
    font-weight: 800;
    letter-spacing: -0.01em;
    margin-top: 0;
    text-align: start;
}

h2 {
    color: {{ $ink }};
    font-size: 17px;
    font-weight: 700;
    margin-top: 0;
    text-align: start;
}

h3 {
    color: {{ $ink }};
    font-size: 15px;
    font-weight: 700;
    margin-top: 0;
    text-align: start;
}

p {
    color: #334155;
    font-size: 16px;
    line-height: 1.6em;
    margin-top: 0;
    text-align: start;
}

p.sub {
    font-size: 12px;
}

img {
    max-width: 100%;
}

.wrapper {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    background-color: {{ $background }};
    margin: 0;
    padding: 0;
    width: 100%;
}

.content {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    margin: 0;
    padding: 0;
    width: 100%;
}

.header {
    padding: 32px 0 24px;
    text-align: center;
}

.header a {
    color: {{ $ink }};
    font-size: 19px;
    font-weight: 800;
    text-decoration: none;
}

.brand-mark {
    background-color: {{ $primary }};
    border-radius: 8px;
    color: #ffffff;
    display: inline-block;
    font-size: 15px;
    font-weight: 800;
    height: 28px;
    line-height: 28px;
    margin-right: 8px;
    text-align: center;
    vertical-align: middle;
    width: 28px;
}

.brand-name {
    color: {{ $ink }};
    vertical-align: middle;
}

.body {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    background-color: {{ $background }};
    border-bottom: 1px solid {{ $background }};
    border-top: 1px solid {{ $background }};
    margin: 0;
    padding: 0;
    width: 100%;
}

.inner-body {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 570px;
    background-color: #ffffff;
    border: 1px solid #E2E8F0;
    border-radius: 14px;
    box-shadow: 0 1px 2px rgba(11, 19, 36, 0.06), 0 8px 24px -12px rgba(11, 19, 36, 0.18);
    margin: 0 auto;
    overflow: hidden;
    padding: 0;
    width: 570px;
}

.accent-bar {
    background-color: {{ $primary }};
    background-image: linear-gradient(90deg, {{ $primary }}, {{ $accent }});
    height: 4px;
}

.inner-body a {
    word-break: break-all;
}

.subcopy {
    border-top: 1px solid #E2E8F0;
    margin-top: 25px;
    padding-top: 25px;
}

.subcopy p {
    color: #64748B;
    font-size: 13px;
}

.footer {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 570px;
    margin: 0 auto;
    padding: 0;
    text-align: center;
    width: 570px;
}

.footer p {
    color: #64748B;
    font-size: 12px;
    text-align: center;
}

.footer a {
    color: #64748B;
    text-decoration: underline;
}

.table table {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    margin: 30px auto;
    width: 100%;
}

.table th {
    border-bottom: 1px solid #E2E8F0;
    color: {{ $ink }};
    margin: 0;
    padding-bottom: 8px;
}

.table td {
    color: #334155;
    font-size: 15px;
    line-height: 18px;
    margin: 0;
    padding: 10px 0;
}

.content-cell {
    max-width: 100vw;
    padding: 36px;
}

.action {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    margin: 30px auto;
    padding: 0;
    text-align: center;
    width: 100%;
    float: unset;
}

.button {
    -webkit-text-size-adjust: none;
    border-radius: 10px;
    color: #ffffff;
    display: inline-block;
    font-weight: 700;
    overflow: hidden;
    text-decoration: none;
}

.button-blue,
.button-primary {
    background-color: {{ $primary }};
    border-bottom: 12px solid {{ $primary }};
    border-left: 24px solid {{ $primary }};
    border-right: 24px solid {{ $primary }};
    border-top: 12px solid {{ $primary }};
}

.button-green,
.button-success {
    background-color: #047857;
    border-bottom: 12px solid #047857;
    border-left: 24px solid #047857;
    border-right: 24px solid #047857;
    border-top: 12px solid #047857;
}

.button-red,
.button-error {
    background-color: #B91C1C;
    border-bottom: 12px solid #B91C1C;
    border-left: 24px solid #B91C1C;
    border-right: 24px solid #B91C1C;
    border-top: 12px solid #B91C1C;
}

.panel {
    border-left: {{ $primary }} solid 4px;
    margin: 21px 0;
}

.panel-content {
    background-color: {{ $background }};
    color: #334155;
    padding: 16px;
}

.panel-content p {
    color: #334155;
}

.panel-item {
    padding: 0;
}

.panel-item p:last-of-type {
    margin-bottom: 0;
    padding-bottom: 0;
}

.muted {
    color: #64748B;
}

.break-all {
    word-break: break-all;
}
