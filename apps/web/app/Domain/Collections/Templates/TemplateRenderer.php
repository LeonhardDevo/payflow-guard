<?php

declare(strict_types=1);

namespace App\Domain\Collections\Templates;

use League\CommonMark\CommonMarkConverter;

final class TemplateRenderer
{
    public const array VARIABLES = [
        'greeting_name', 'client_name', 'invoice_number', 'po_number', 'amount_due', 'total', 'due_date', 'due_phrase',
        'days_overdue', 'days_overdue_phrase', 'invoice_link', 'payment_link', 'company_name', 'sender_name', 'late_fee',
        'late_fee_notice', 'payment_amount', 'balance_phrase',
    ];

    /** @return list<string> */
    public static function unknownVariables(string ...$texts): array
    {
        $unknown = [];

        foreach ($texts as $text) {
            preg_match_all('/\{\{\s*([A-Za-z0-9_.-]+)\s*\}\}/', $text, $matches);

            foreach ($matches[1] as $name) {
                if (! in_array($name, self::VARIABLES, true)) {
                    $unknown[] = $name;
                }
            }
        }

        return array_values(array_unique($unknown));
    }

    /** @param  array<string, string>  $variables */
    public static function substitute(string $text, array $variables): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([A-Za-z0-9_.-]+)\s*\}\}/',
            fn (array $match): string => array_key_exists($match[1], $variables) ? $variables[$match[1]] : $match[0],
            $text,
        );
    }

    /**
     * @param  array<string, string>  $variables
     * @return array{subject: string, markdown: string, html: string, text: string}
     */
    public static function render(string $subject, string $bodyMarkdown, array $variables): array
    {
        $renderedSubject = trim((string) preg_replace('/\s+/', ' ', self::substitute($subject, $variables)));
        $markdown = self::substitute($bodyMarkdown, self::escapeForMarkdown($variables));
        $markdown = trim((string) preg_replace("/\n{3,}/", "\n\n", str_replace("\r\n", "\n", $markdown)));

        return [
            'subject' => $renderedSubject,
            'markdown' => $markdown,
            'html' => self::markdownToHtml($markdown),
            'text' => self::markdownToText($markdown),
        ];
    }

    public static function markdownToHtml(string $markdown): string
    {
        $converter = new CommonMarkConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]);

        return trim($converter->convert($markdown)->getContent());
    }

    public static function markdownToText(string $markdown): string
    {
        $escapes = ['\\\\' => "\u{E000}", '\\*' => "\u{E001}", '\\_' => "\u{E002}", '\\[' => "\u{E003}", '\\]' => "\u{E004}", '\\`' => "\u{E005}"];
        $text = strtr($markdown, $escapes);
        $text = (string) preg_replace('/\[([^\]]+)\]\(([^)\s]+)\)/u', '$1: $2', $text);
        $text = str_replace(['**', '__'], '', $text);
        $text = (string) preg_replace('/(?<![\p{L}\p{N}])[*_](\S[^*_]*?)[*_](?![\p{L}\p{N}])/u', '$1', $text);
        $text = strtr($text, ["\u{E000}" => '\\', "\u{E001}" => '*', "\u{E002}" => '_', "\u{E003}" => '[', "\u{E004}" => ']', "\u{E005}" => '`']);
        $text = str_replace(['&lt;', '&gt;'], ['<', '>'], $text);

        return trim($text);
    }

    /**
     * @param  array<string, string>  $variables
     * @return array<string, string>
     */
    private static function escapeForMarkdown(array $variables): array
    {
        $escaped = [];

        foreach ($variables as $key => $value) {
            $escaped[$key] = in_array($key, ['invoice_link', 'payment_link', 'late_fee_notice', 'balance_phrase'], true)
                ? $value
                : str_replace(['\\', '*', '_', '[', ']', '<', '>', '`'], ['\\\\', '\\*', '\\_', '\\[', '\\]', '&lt;', '&gt;', '\\`'], $value);
        }

        return $escaped;
    }
}
