<?php

declare(strict_types=1);

use App\Services\Legal\LegalPageRenderer;

beforeEach(function (): void {
    $this->renderer = new LegalPageRenderer;
});

test('renders markdown structure to html', function (): void {
    $html = $this->renderer->toHtml("# Heading\n\n- one\n- two\n\n[link](https://example.com)");

    expect($html)
        ->toContain('<h1>Heading</h1>')
        ->toContain('<li>one</li>')
        ->toContain('<li>two</li>')
        ->toContain('<a href="https://example.com"');
});

test('escapes raw html so embedded scripts cannot execute', function (): void {
    $html = $this->renderer->toHtml('<script>alert(1)</script>');

    expect($html)
        ->not->toContain('<script>')
        ->toContain('&lt;script&gt;');
});

test('strips javascript link schemes', function (): void {
    $html = $this->renderer->toHtml('[click me](javascript:alert(1))');

    expect($html)
        ->not->toContain('href="javascript:')
        ->not->toContain('javascript:');
});

test('returns an empty string for null or blank input', function (?string $input): void {
    expect($this->renderer->toHtml($input))->toBe('');
})->with([
    'null' => [null],
    'empty string' => [''],
    'whitespace only' => ["   \n\t  "],
]);
