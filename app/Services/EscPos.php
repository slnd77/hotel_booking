<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * A small ESC/POS command builder for thermal receipt printers (58mm = 32 and 80mm = 48
 * characters a line in font A). Text is sent as plain ASCII, so it prints on any code page.
 */
class EscPos
{
    private const ESC = "\x1B";

    private const GS = "\x1D";

    private string $buffer;

    public function __construct(public readonly int $columns)
    {
        // Initialise, then code page 0 (PC437).
        $this->buffer = self::ESC.'@'.self::ESC.'t'.chr(0);
    }

    public function align(string $alignment): static
    {
        $this->buffer .= self::ESC.'a'.chr(match ($alignment) {
            'center' => 1,
            'right' => 2,
            default => 0,
        });

        return $this;
    }

    public function bold(bool $on = true): static
    {
        $this->buffer .= self::ESC.'E'.chr($on ? 1 : 0);

        return $this;
    }

    /**
     * Double width and height (half as many characters fit on a line).
     */
    public function large(bool $on = true): static
    {
        $this->buffer .= self::GS.'!'.chr($on ? 0x11 : 0x00);

        return $this;
    }

    /**
     * One or more lines of text, wrapped to the paper width.
     */
    public function text(string $text, ?int $width = null): static
    {
        $width ??= $this->columns;

        foreach (explode("\n", $this->ascii($text)) as $line) {
            $this->buffer .= ($line === '' ? '' : wordwrap($line, $width, "\n", true))."\n";
        }

        return $this;
    }

    /**
     * Text on the left, text on the right, on one line (the left side is cut to fit).
     */
    public function pair(string $left, string $right): static
    {
        $right = $this->ascii($right);
        $left = Str::limit($this->ascii($left), max(1, $this->columns - strlen($right) - 1), '');

        $this->buffer .= $left.str_repeat(' ', max(1, $this->columns - strlen($left) - strlen($right))).$right."\n";

        return $this;
    }

    /**
     * A table row: the first cell takes what the fixed-width cells leave and wraps; the others
     * are right-aligned.
     *
     * @param  list<string>  $cells
     * @param  list<int>  $widths  widths of the cells after the first
     */
    public function row(array $cells, array $widths): static
    {
        $first = $this->ascii((string) array_shift($cells));
        $firstWidth = max(1, $this->columns - array_sum($widths));
        $lines = explode("\n", wordwrap($first, $firstWidth, "\n", true));
        $rest = '';

        foreach ($cells as $index => $cell) {
            $rest .= str_pad(Str::limit($this->ascii((string) $cell), $widths[$index], ''), $widths[$index], ' ', STR_PAD_LEFT);
        }

        foreach ($lines as $index => $line) {
            $this->buffer .= str_pad($line, $firstWidth).($index === 0 ? $rest : '')."\n";
        }

        return $this;
    }

    public function rule(string $character = '-'): static
    {
        $this->buffer .= str_repeat($character, $this->columns)."\n";

        return $this;
    }

    public function feed(int $lines = 1): static
    {
        $this->buffer .= self::ESC.'d'.chr(max(0, min(255, $lines)));

        return $this;
    }

    /**
     * Feeds the paper past the cutter and cuts it (partly, where the printer can).
     */
    public function cut(): static
    {
        $this->buffer .= self::GS.'V'.chr(66).chr(3);

        return $this;
    }

    /**
     * Kicks open the cash drawer wired to the printer (pin 2, a 50ms pulse).
     */
    public function openDrawer(): static
    {
        $this->buffer .= self::ESC.'p'.chr(0).chr(25).chr(250);

        return $this;
    }

    public function bytes(): string
    {
        return $this->buffer;
    }

    private function ascii(string $text): string
    {
        return Str::ascii($text);
    }
}
