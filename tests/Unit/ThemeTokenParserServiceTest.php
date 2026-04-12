<?php

use App\Services\Shopify\ThemeTokenParserService;

function parser(): ThemeTokenParserService
{
    return new ThemeTokenParserService();
}

it('returns an empty array for empty html', function () {
    expect(parser()->extractTokens(''))->toBe([]);
});

it('extracts Dawn theme CSS custom properties', function () {
    $html = <<<'HTML'
    <html>
    <head>
    <style>
      :root {
        --color-base-text: 17, 17, 17;
        --color-base-background-1: 255, 255, 255;
        --color-base-accent-1: 44, 44, 44;
        --color-base-accent-2: 119, 119, 119;
        --color-button: 26, 26, 26;
        --color-button-text: 255, 255, 255;
        --buttons-radius: 8px;
        --buttons-border-width: 1px;
      }
    </style>
    </head>
    <body></body>
    </html>
    HTML;

    $tokens = parser()->extractTokens($html);

    expect($tokens)->toMatchArray([
        'text_color' => '#111111',
        'background_color' => '#ffffff',
        'primary_color' => '#2c2c2c',
        'secondary_color' => '#777777',
        'button_background' => '#1a1a1a',
        'button_text_color' => '#ffffff',
        'border_radius' => '8px',
        'border_width' => '1px',
    ]);
});

it('extracts Prestige theme hex colors', function () {
    $html = <<<'HTML'
    <style>
    :root {
      --heading-color: #222222;
      --text-color: #444444;
      --background: #fafafa;
      --button-background: #000000;
      --primary-button-background: #ff0066;
    }
    </style>
    HTML;

    $tokens = parser()->extractTokens($html);

    expect($tokens['text_color'])->toBe('#444444');
    expect($tokens['background_color'])->toBe('#fafafa');
    expect($tokens['button_background'])->toBe('#000000');
});

it('normalizes 3-char hex to 6-char', function () {
    $html = '<style>:root { --color-text: #abc; }</style>';
    expect(parser()->extractTokens($html))->toMatchArray(['text_color' => '#aabbcc']);
});

it('normalizes rgb() color values', function () {
    $html = '<style>:root { --color-background: rgb(250, 251, 252); }</style>';
    expect(parser()->extractTokens($html))->toMatchArray(['background_color' => '#fafbfc']);
});

it('appends px to bare numeric border values', function () {
    $html = '<style>:root { --buttons-radius: 12; --buttons-border-width: 2; }</style>';
    $tokens = parser()->extractTokens($html);
    expect($tokens['border_radius'])->toBe('12px');
    expect($tokens['border_width'])->toBe('2px');
});

it('preserves rem and em units', function () {
    $html = '<style>:root { --buttons-radius: 0.5rem; --buttons-border-width: 0.125em; }</style>';
    $tokens = parser()->extractTokens($html);
    expect($tokens['border_radius'])->toBe('0.5rem');
    expect($tokens['border_width'])->toBe('0.125em');
});

it('skips var() references as unresolved', function () {
    $html = '<style>:root { --color-text: var(--other-var); }</style>';
    $tokens = parser()->extractTokens($html);
    expect($tokens)->not()->toHaveKey('text_color');
});

it('extracts Google Fonts from link tags', function () {
    $html = <<<'HTML'
    <html>
    <head>
      <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap">
      <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700">
      <style>
        :root {
          --font-heading-family: "Playfair Display", serif;
          --font-body-family: "Inter", sans-serif;
        }
      </style>
    </head>
    </html>
    HTML;

    $tokens = parser()->extractTokens($html);

    expect($tokens['heading_font']['family'])->toBe('Playfair Display');
    expect($tokens['heading_font']['source_type'])->toBe('google');
    expect($tokens['heading_font']['source_url'])->toContain('fonts.googleapis.com');

    expect($tokens['body_font']['family'])->toBe('Inter');
    expect($tokens['body_font']['source_type'])->toBe('google');
});

it('extracts self-hosted fonts from @font-face', function () {
    $html = <<<'HTML'
    <style>
    @font-face {
      font-family: "Custom Brand Font";
      src: url("https://cdn.shopify.com/s/files/1/000/brand-font.woff2") format("woff2");
    }
    :root {
      --font-heading-family: "Custom Brand Font", sans-serif;
    }
    </style>
    HTML;

    $tokens = parser()->extractTokens($html);

    expect($tokens['heading_font']['family'])->toBe('Custom Brand Font');
    expect($tokens['heading_font']['source_type'])->toBe('self-hosted');
    expect($tokens['heading_font']['source_url'])->toBe('https://cdn.shopify.com/s/files/1/000/brand-font.woff2');
});

it('does not return heading font when only body variable is present', function () {
    $html = '<style>:root { --font-body-family: "Arial"; }</style>';
    $tokens = parser()->extractTokens($html);
    expect($tokens)->not()->toHaveKey('heading_font');
    expect($tokens['body_font']['family'])->toBe('Arial');
});

it('rejects invalid color formats', function () {
    $html = '<style>:root { --color-text: notacolor; }</style>';
    $tokens = parser()->extractTokens($html);
    expect($tokens)->not()->toHaveKey('text_color');
});

it('rejects font family with suspicious characters', function () {
    $html = '<style>:root { --font-body-family: "<script>alert(1)</script>"; }</style>';
    $tokens = parser()->extractTokens($html);
    expect($tokens)->not()->toHaveKey('body_font');
});

it('exposes a stable list of token keys', function () {
    expect(ThemeTokenParserService::TOKEN_KEYS)->toContain('primary_color');
    expect(ThemeTokenParserService::TOKEN_KEYS)->toContain('heading_font');
    expect(ThemeTokenParserService::TOKEN_KEYS)->toHaveCount(10);
});
