<?php

namespace App\Modules\Generation\Support;

/** Pulls one JSON object out of model text: bare JSON, a fenced block, or JSON with chatter around it. */
final class JsonText
{
    /** @return array<string, mixed>|null */
    public static function object(string $text): ?array
    {
        $text = trim($text);
        $candidates = [$text];
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $text, $m)) {
            $candidates[] = $m[1];
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $candidates[] = substr($text, $start, $end - $start + 1);
        }
        foreach ($candidates as $c) {
            $decoded = json_decode($c, true);
            if (is_array($decoded) && ! array_is_list($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
