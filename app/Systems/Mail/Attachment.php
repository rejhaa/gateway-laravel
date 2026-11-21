<?php

declare(strict_types=1);

namespace App\Systems\Mail;

/**
 * An attachment as text. Lines are keyed by their location, such as "p1.l17",
 * with the page and the line counted from 1. A document without a text layer
 * has no lines.
 */
final readonly class Attachment
{
    /**
     * @param  array<string, string>  $lines  location to text, in document order
     */
    public function __construct(
        public string $name,
        public string $contentType,
        public bool $hasTextLayer,
        public array $lines = [],
    ) {}
}
