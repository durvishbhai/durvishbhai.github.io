<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;

/**
 * Automated accessibility checks (WCAG 2.2 AA): colour contrast of the real design tokens in both themes, alternative text, form labels, page landmarks and the CSS
 * safeguards (visible focus, reduced motion). These checks do NOT replace a manual review with a keyboard and a screen reader; they stop regressions.
 */
final class AccessibilityTest extends HttpTestCase
{
    private static function css(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/app.css');
    }

    /** @return array<string,string> */
    private static function tokens(string $selector): array
    {
        preg_match('/' . preg_quote($selector, '/') . ' \{(.*?)\n\}/s', self::css(), $m);
        preg_match_all('/--([a-z0-9-]+):\s*(#[0-9a-fA-F]{6})\b/', $m[1] ?? '', $mm, PREG_SET_ORDER);
        $o = [];
        foreach ($mm as $x) {
            $o[$x[1]] = $x[2];
        }

        return $o;
    }

    private static function lum(string $hex): float
    {
        $h = ltrim($hex, '#');
        $c = array_map(static fn (string $p): float => (($v = hexdec($p) / 255) <= 0.03928) ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, str_split($h, 2));

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    private static function ratio(string $a, string $b): float
    {
        [$x, $y] = [self::lum($a), self::lum($b)];

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    /** @return array<string,array<string,string>> */
    private static function themes(): array
    {
        $dark = self::tokens(':root');

        return ['dark' => $dark, 'light' => self::tokens(':root[data-theme="light"]') + $dark];
    }

    public function testTextColoursMeetFourPointFiveToOneInBothThemes(): void
    {
        $pairs = [['text', 'bg'], ['text', 'bg-card'], ['text-dim', 'bg'], ['text-dim', 'bg-card'], ['text-dim', 'surface-3'], ['blue-bright', 'bg'], ['blue-bright', 'bg-card'], ['accent', 'bg-card'], ['danger', 'bg'],
            ['c-warn', 'bg-card'], ['c-bad', 'bg-card'], ['c-ok', 'bg-card'], ['c-info', 'bg-card'], ['silver-2', 'bg'], ['silver-2', 'bg-card']];
        foreach (self::themes() as $theme => $t) {
            foreach ($pairs as [$fg, $bg]) {
                $this->assertArrayHasKey($fg, $t);
                $this->assertGreaterThanOrEqual(4.5, self::ratio($t[$fg], $t[$bg]), "{$theme}: --{$fg} on --{$bg}");
            }
        }
    }

    public function testTextOnTheBrandGradientAndHeadingGradientsKeepContrast(): void
    {
        $css = self::css();
        preg_match('/--brand-grad: linear-gradient\(135deg, (#[0-9a-f]{6}) 0%, (#[0-9a-f]{6}) 38%, (#[0-9a-f]{6}) 68%, (#[0-9a-f]{6}) 100%\)/i', $css, $m);
        $this->assertCount(5, $m, 'the brand gradient stops can be read');
        $on = self::tokens(':root')['on-grad'];
        foreach (array_slice($m, 1) as $stop) {
            $this->assertGreaterThanOrEqual(4.5, self::ratio($on, $stop), "button text on gradient stop {$stop}");
        }
        // the gradient used for heading text on the page background
        $this->assertGreaterThanOrEqual(1, preg_match('/:root\[data-theme="light"\] \{.*?--grad-text: linear-gradient\(135deg, (#[0-9a-f]{6}) 0%, (#[0-9a-f]{6}) 38%, (#[0-9a-f]{6}) 68%, (#[0-9a-f]{6}) 100%\)/is', $css, $l));
        $th = self::themes();
        foreach (array_slice($l, 1) as $stop) {
            $this->assertGreaterThanOrEqual(4.5, self::ratio($stop, $th['light']['bg']), "light gradient heading stop {$stop}");
        }
        foreach (array_slice($m, 1) as $stop) {
            $this->assertGreaterThanOrEqual(4.5, self::ratio($stop, $th['dark']['bg']), "dark gradient heading stop {$stop}");
        }
        $this->assertGreaterThanOrEqual(4.5, self::ratio('#ffffff', '#3a49d8'), 'skip link');
    }

    public function testInterfaceBordersAndFocusRingMeetThreeToOne(): void
    {
        foreach (self::themes() as $theme => $t) {
            $this->assertGreaterThanOrEqual(3.0, self::ratio($t['blue-bright'], $t['bg']), "{$theme}: focus ring on page");
            $this->assertGreaterThanOrEqual(3.0, self::ratio($t['blue-bright'], $t['bg-card']), "{$theme}: focus ring on card");
        }
    }

    public function testFocusIsVisibleAndMotionCanBeReduced(): void
    {
        $css = self::css();
        $this->assertStringContainsString(':focus-visible { outline: 2px solid', $css);
        $this->assertMatchesRegularExpression('/@media \(prefers-reduced-motion: reduce\)/', $css);
        $this->assertStringContainsString('animation: none !important', $css);
        // every rule that removes the outline puts a visible box-shadow back
        foreach (preg_split('/\n/', $css) as $line) {
            if (preg_match('/outline:\s*none/', $line) && str_contains($line, ':focus')) {
                $this->assertStringContainsString('box-shadow', $line, 'outline removed without a replacement: ' . substr($line, 0, 80));
            }
        }
    }

    public function testEveryImageInTheTemplatesHasAnAltAttribute(): void
    {
        $n = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/app/Views'));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'php') {
                continue;
            }
            $src = (string) preg_replace('/<\?(?:php|=).*?\?>/s', 'X', (string) file_get_contents($f->getPathname())); // template code is not markup
            preg_match_all('/<img\b[^>]*>/is', $src, $imgs);
            foreach ($imgs[0] as $tag) {
                $n++;
                $this->assertMatchesRegularExpression('/\salt=/', $tag, $f->getFilename() . ': ' . substr($tag, 0, 90));
            }
        }
        $this->assertGreaterThan(5, $n);
    }

    /** @return list<string> */
    private static function pages(): array
    {
        return ['/', '/about', '/services', '/contact', '/register', '/login', '/request-quote', '/careers', '/faq', '/our-clients', '/portfolio', '/cookie-settings', '/data-deletion', '/third-party-services', '/contact-and-grievance', '/legal-business-information', '/unsubscribe/x'];
    }

    public function testPublicPagesHaveLandmarksOneHeadingAndLabelledControls(): void
    {
        foreach (self::pages() as $path) {
            $res = $this->client()->get($path);
            $this->assertContains($res['status'], [200], $path);
            $html = $res['body'];
            $this->assertMatchesRegularExpression('/<html lang="[a-z-]+"/i', $html, $path . ' language');
            $this->assertSame(1, preg_match_all('/<h1[\s>]/i', $html), $path . ' has exactly one h1');
            $this->assertStringContainsString('<main id="main"', $html, $path);
            $this->assertMatchesRegularExpression('/<a[^>]*class="skip-link"[^>]*href="#main"/', $html, $path . ' skip link');
            $this->assertLessThan(strpos($html, 'class="skip-link"') + 400, strpos($html, 'kis-cookie-banner') ?: PHP_INT_MAX, $path . ': the cookie banner comes right after the skip link');
            foreach (['<img' => '/<img\b(?![^>]*\salt=)[^>]*>/i'] as $what => $re) {
                $this->assertSame(0, preg_match($re, $html), $path . ' ' . $what . ' without alt');
            }
            // every visible form control has a label, aria-label or sits inside a label
            preg_match_all('/<(input|select|textarea)\b([^>]*)>/i', $html, $ctl, PREG_SET_ORDER);
            foreach ($ctl as $c) {
                if (preg_match('/type="(hidden|submit|button|image)"/i', $c[2]) || preg_match('/\sclass="[^"]*\bhp\b/', $c[2])) {
                    continue;
                }
                $named = preg_match('/aria-label(ledby)?=/i', $c[2]);
                if (!$named && preg_match('/\sid="([^"]+)"/', $c[2], $id)) {
                    $named = str_contains($html, 'for="' . $id[1] . '"');
                }
                if (!$named) {
                    $pos = strpos($html, $c[0]);
                    $before = substr($html, max(0, $pos - 400), 400);
                    $named = strrpos($before, '<label') !== false && strrpos($before, '<label') > (int) strrpos($before, '</label>');
                }
                $this->assertTrue((bool) $named, $path . ' control without a label: ' . substr($c[0], 0, 100));
            }
        }
    }

    public function testErrorsAndStatusMessagesAreAnnounced(): void
    {
        $html = $this->client()->get('/login')['body'];
        $flash = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/partials/flash.php');
        $this->assertStringContainsString('role="alert"', $flash, 'errors are announced');
        $this->assertStringContainsString('role="status"', $flash, 'confirmations are announced');
        $this->assertSame(1, preg_match('/<dialog id="kis-cookie-dialog"[^>]*aria-labelledby=/', $html), 'the settings dialog is a native modal dialog with a name');
    }
}
