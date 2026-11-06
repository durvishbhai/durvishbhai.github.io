<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;

/**
 * The one look for every email the platform sends: a 600px centred card on a soft grey canvas, a navy header with the KIS logo and name, a small status label,
 * a clear heading, a greeting, short paragraphs, an optional details table, at most one action button, secondary notes and a standard footer. Table-based
 * markup with inline CSS (Gmail, Outlook, Apple Mail and phones), no scripts, no external styles. Every dynamic value is escaped. A plain-text version is built
 * from the same description, so the two can never disagree.
 *
 * A message is described, not written as HTML:
 *   subject     required, the email subject
 *   category    small label above the heading ("Payments", "Account security")
 *   tone        success | info | warning | danger: colours the label and the left edge of the details card
 *   heading     required, what happened, as a sentence
 *   name        recipient's name for the greeting (omit for "Hello,")
 *   paragraphs  list of strings
 *   details     [label => value] rows; empty values are left out
 *   button      ['label' => ..., 'url' => absolute http(s) URL] (leave out unless an action is genuinely needed)
 *   notes       small secondary paragraphs (security guidance, what happens next)
 *   preheader   the preview text some clients show next to the subject
 */
final class EmailTemplate
{
    private const NAVY = '#0b1130';
    private const INK = '#0f1b3d';
    private const MUTED = '#5b6785';
    private const LINE = '#e3e8f4';
    private const CANVAS = '#f3f5fb';
    private const ACCENT = '#4f7cff';
    private const TONES = ['success' => ['#0b8a5d', '#e8f7f1'], 'info' => ['#2f5fe3', '#eaf0ff'], 'warning' => ['#b86b00', '#fff4e0'], 'danger' => ['#c0283d', '#fdebee']];

    /**
     * @param array<string,mixed> $m
     * @return array{subject:string,html:string,text:string}
     */
    public static function render(array $m): array
    {
        $subject = trim(preg_replace('/[\r\n]+/', ' ', (string) ($m['subject'] ?? 'Notification from ' . self::company())) ?? '');
        $company = self::company();
        $tone = isset(self::TONES[$m['tone'] ?? '']) ? (string) $m['tone'] : 'info';
        [$toneInk, $toneBg] = self::TONES[$tone];
        $heading = trim((string) ($m['heading'] ?? $subject));
        $name = trim((string) ($m['name'] ?? ''));
        $greeting = $name !== '' ? 'Hello ' . self::clip($name, 80) . ',' : 'Hello,';
        $paras = array_values(array_filter(array_map(static fn ($p): string => trim((string) $p), (array) ($m['paragraphs'] ?? [])), static fn (string $p): bool => $p !== ''));
        $notes = array_values(array_filter(array_map(static fn ($p): string => trim((string) $p), (array) ($m['notes'] ?? [])), static fn (string $p): bool => $p !== ''));
        $details = [];
        foreach ((array) ($m['details'] ?? []) as $label => $value) {
            $v = trim((string) $value);
            if ($v !== '') {
                $details[(string) $label] = self::clip($v, 300);
            }
        }
        $button = null;
        $b = $m['button'] ?? null;
        if (is_array($b) && trim((string) ($b['label'] ?? '')) !== '' && preg_match('#^https?://[^\s"<>]+$#i', (string) ($b['url'] ?? ''))) {
            $button = ['label' => self::clip(trim((string) $b['label']), 60), 'url' => (string) $b['url']];
        }
        $category = trim((string) ($m['category'] ?? ''));
        $pre = trim((string) ($m['preheader'] ?? ($paras[0] ?? $heading)));
        $footer = self::footer();
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // ---- HTML
        $h = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="x-apple-disable-message-reformatting"><meta name="color-scheme" content="light"><title>' . $e($subject) . '</title></head>';
        $h .= '<body style="margin:0;padding:0;background:' . self::CANVAS . ';-webkit-text-size-adjust:100%;">';
        $h .= '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:' . self::CANVAS . ';">' . $e(self::clip($pre, 140)) . '&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;</div>';
        $h .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:' . self::CANVAS . ';"><tr><td align="center" style="padding:24px 12px;">';
        $h .= '<table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid ' . self::LINE . ';border-radius:14px;overflow:hidden;font-family:Segoe UI,Helvetica,Arial,sans-serif;color:' . self::INK . ';">';
        // header
        $logo = self::logoUrl();
        $h .= '<tr><td style="background:' . self::NAVY . ';padding:18px 28px;"><table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr>';
        if ($logo !== '') {
            $h .= '<td style="padding-right:12px;vertical-align:middle;"><img src="' . $e($logo) . '" alt="' . $e($company) . '" width="96" style="display:block;border:0;outline:none;height:auto;max-width:96px;"></td>';
        }
        $h .= '<td style="vertical-align:middle;font-size:15px;font-weight:700;letter-spacing:0.6px;color:#ffffff;">' . $e(strtoupper($company)) . '</td></tr></table></td></tr>';
        $h .= '<tr><td style="height:3px;line-height:3px;font-size:0;background:' . self::ACCENT . ';background-image:linear-gradient(90deg,#19d3ff,#4f7cff,#7c5cff);">&nbsp;</td></tr>';
        // body
        $h .= '<tr><td style="padding:30px 32px 8px 32px;">';
        if ($category !== '') {
            $h .= '<span style="display:inline-block;padding:4px 10px;border-radius:999px;background:' . $toneBg . ';color:' . $toneInk . ';font-size:11px;font-weight:700;letter-spacing:0.8px;text-transform:uppercase;">' . $e($category) . '</span>';
        }
        $h .= '<h1 style="margin:14px 0 14px 0;font-size:24px;line-height:1.3;font-weight:700;color:' . self::INK . ';">' . $e($heading) . '</h1>';
        $h .= '<p style="margin:0 0 14px 0;font-size:15px;line-height:1.6;">' . $e($greeting) . '</p>';
        foreach ($paras as $p) {
            $h .= '<p style="margin:0 0 14px 0;font-size:15px;line-height:1.6;color:#27335a;">' . self::linkify($e($p)) . '</p>';
        }
        $h .= '</td></tr>';
        if ($details !== []) {
            $h .= '<tr><td style="padding:4px 32px 12px 32px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#fafbff;border:1px solid ' . self::LINE . ';border-left:4px solid ' . $toneInk . ';border-radius:8px;">';
            $i = 0;
            foreach ($details as $label => $value) {
                $border = $i++ === 0 ? '' : 'border-top:1px solid ' . self::LINE . ';';
                $h .= '<tr><td width="38%" valign="top" style="padding:10px 14px;' . $border . 'font-size:13px;color:' . self::MUTED . ';">' . $e((string) $label) . '</td><td valign="top" style="padding:10px 14px;' . $border . 'font-size:14px;font-weight:600;color:' . self::INK . ';word-break:break-word;">' . $e($value) . '</td></tr>';
            }
            $h .= '</table></td></tr>';
        }
        if ($button !== null) {
            $h .= '<tr><td style="padding:14px 32px 10px 32px;"><table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr><td align="center" bgcolor="' . self::NAVY . '" style="border-radius:8px;background:' . self::NAVY . ';"><a href="' . $e($button['url']) . '" style="display:inline-block;padding:13px 26px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:8px;">' . $e($button['label']) . '</a></td></tr></table>';
            $h .= '<p style="margin:12px 0 0 0;font-size:12px;line-height:1.5;color:' . self::MUTED . ';">If the button does not work, copy this address into your browser:<br><a href="' . $e($button['url']) . '" style="color:' . self::ACCENT . ';word-break:break-all;">' . $e($button['url']) . '</a></p></td></tr>';
        }
        if ($notes !== []) {
            $h .= '<tr><td style="padding:12px 32px 4px 32px;">';
            foreach ($notes as $n) {
                $h .= '<p style="margin:0 0 10px 0;font-size:13px;line-height:1.6;color:' . self::MUTED . ';">' . self::linkify($e($n)) . '</p>';
            }
            $h .= '</td></tr>';
        }
        // footer
        $h .= '<tr><td style="padding:22px 32px 26px 32px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td style="border-top:1px solid ' . self::LINE . ';padding-top:16px;font-size:12px;line-height:1.6;color:' . self::MUTED . ';">';
        $h .= '<strong style="color:' . self::INK . ';">' . $e($company) . '</strong><br>' . $e($footer['automated']) . '<br>' . $e($footer['why']);
        $h .= '<br>' . ($footer['support'] !== '' ? 'Support: <a href="mailto:' . $e($footer['support']) . '" style="color:' . self::ACCENT . ';text-decoration:none;">' . $e($footer['support']) . '</a>' : '') . ($footer['support'] !== '' && $footer['site'] !== '' ? ' &nbsp;|&nbsp; ' : '') . ($footer['site'] !== '' ? '<a href="' . $e($footer['site']) . '" style="color:' . self::ACCENT . ';text-decoration:none;">' . $e(preg_replace('#^https?://#', '', rtrim($footer['site'], '/'))) . '</a>' : '');
        $h .= '</td></tr></table></td></tr></table>';
        $h .= '</td></tr></table></body></html>';

        // ---- plain text
        $t = strtoupper($company) . "\n" . str_repeat('=', min(60, mb_strlen($company))) . "\n\n";
        if ($category !== '') {
            $t .= '[' . strtoupper($category) . "]\n";
        }
        $t .= $heading . "\n\n" . $greeting . "\n\n";
        foreach ($paras as $p) {
            $t .= $p . "\n\n";
        }
        if ($details !== []) {
            foreach ($details as $label => $value) {
                $t .= $label . ': ' . $value . "\n";
            }
            $t .= "\n";
        }
        if ($button !== null) {
            $t .= $button['label'] . ":\n" . $button['url'] . "\n\n";
        }
        foreach ($notes as $n) {
            $t .= $n . "\n\n";
        }
        $t .= "--\n" . $company . "\n" . $footer['automated'] . "\n" . $footer['why'] . "\n" . ($footer['support'] !== '' ? 'Support: ' . $footer['support'] . "\n" : '') . ($footer['site'] !== '' ? $footer['site'] . "\n" : '');

        return ['subject' => $subject, 'html' => $h, 'text' => $t];
    }

    /**
     * Wraps an old-style plain message in the standard look (the safety net: nothing is ever sent unbranded). The first line "Hello Name," becomes the
     * greeting, blank-line separated blocks become paragraphs, and a line that is only a link becomes the action button.
     *
     * @return array<string,mixed>
     */
    public static function fromPlain(string $subject, string $body): array
    {
        $subject = trim(preg_replace('/[\r\n]+/', ' ', $subject) ?? '');
        $blocks = preg_split('/\R{2,}/', trim($body)) ?: [];
        if ($blocks !== [] && trim((string) end($blocks)) === self::company()) {
            array_pop($blocks); // the footer already carries the sign-off
        }
        $name = '';
        if ($blocks !== [] && preg_match('/^Hello(?: (.+?))?,\s*$/u', trim($blocks[0]), $mm)) {
            $name = (string) ($mm[1] ?? '');
            array_shift($blocks);
        }
        $paras = [];
        $button = null;
        foreach ($blocks as $b) {
            $b = trim($b);
            if ($button === null && preg_match('#^https?://\S+$#', $b)) {
                $button = ['label' => 'Open', 'url' => $b];
            } elseif ($b !== '') {
                $paras[] = $b;
            }
        }

        return ['subject' => $subject, 'heading' => $subject, 'name' => $name, 'paragraphs' => $paras, 'button' => $button, 'category' => 'Notification', 'tone' => 'info'];
    }

    /** A short, readable "Chrome on Windows" from a User-Agent header; "Unknown device" when it cannot be told. */
    public static function browser(string $ua): string
    {
        $b = 'Unknown browser';
        foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Firefox' => 'Firefox', 'Chrome' => 'Chrome', 'Safari' => 'Safari'] as $needle => $label) {
            if (stripos($ua, $needle) !== false) {
                $b = $label;
                break;
            }
        }
        $os = 'an unknown system';
        foreach (['Windows' => 'Windows', 'Android' => 'Android', 'iPhone' => 'iOS', 'iPad' => 'iPadOS', 'Mac OS' => 'macOS', 'Linux' => 'Linux'] as $needle => $label) {
            if (stripos($ua, $needle) !== false) {
                $os = $label;
                break;
            }
        }

        return $b === 'Unknown browser' && $os === 'an unknown system' ? 'Unknown device' : "{$b} on {$os}";
    }

    /** Integer minor units to a display amount: "₹12,500.00" (INR) or "USD 1,200.00". Empty when there is no amount. */
    public static function money(?int $minor, string $currency = 'INR'): string
    {
        if ($minor === null) {
            return '';
        }

        return ($currency === 'INR' ? '₹' : $currency . ' ') . FieldRules::formatMinor($minor);
    }

    /** "24 Oct 2026" from a stored date or UTC date-time; with $time, "24 Oct 2026, 13:05 UTC". Empty when it cannot be read. */
    public static function date(?string $value, bool $time = false): string
    {
        $t = $value === null || $value === '' ? false : strtotime($value . (strlen($value) <= 10 ? '' : ' UTC'));

        return $t === false ? '' : gmdate($time ? 'd M Y, H:i' : 'd M Y', $t) . ($time ? ' UTC' : '');
    }

    /** The absolute address of the logo picture, or '' when it cannot be built. */
    public static function logoUrl(): string
    {
        $base = rtrim((string) Config::get('app.url', ''), '/');
        if ($base === '' || !function_exists('asset')) {
            return '';
        }

        return $base . asset('img/krazy-mark.png');
    }

    /** @return array{automated:string,why:string,support:string,site:string} */
    private static function footer(): array
    {
        $support = '';
        try {
            $support = trim((string) (BusinessProfile::get()['email'] ?? ''));
        } catch (\Throwable) {
            // no database: no support line
        }
        $site = rtrim((string) Config::get('app.url', ''), '/');
        $company = self::company();

        return [
            'automated' => 'This is an automated notification from ' . $company . '.',
            'why' => 'You are receiving it because of activity on your account or a request made with this email address. It is not a marketing message.',
            'support' => filter_var($support, FILTER_VALIDATE_EMAIL) ? $support : '',
            'site' => preg_match('#^https?://#', $site) ? $site : '',
        ];
    }

    private static function company(): string
    {
        return (string) Config::get('app.name', 'Krazy Integrated Solutions') ?: 'Krazy Integrated Solutions';
    }

    private static function clip(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)) . '…' : $s;
    }

    /** Turns plain http(s) addresses in ALREADY ESCAPED text into links. */
    private static function linkify(string $escaped): string
    {
        return (string) preg_replace('#(https?://[^\s<]+)#', '<a href="$1" style="color:' . self::ACCENT . ';word-break:break-all;">$1</a>', $escaped);
    }
}
