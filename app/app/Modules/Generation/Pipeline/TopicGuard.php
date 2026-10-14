<?php

namespace App\Modules\Generation\Pipeline;

use App\Modules\Generation\GenerationException;

/**
 * The first gate: what a learner typed, before any model sees it. It is deliberately simple and strict; the planner is
 * also told the scope and can refuse. A refusal here costs nothing and says why in plain words.
 */
final class TopicGuard
{
    public static function normalise(string $raw): string
    {
        $topic = trim(preg_replace('/\s+/u', ' ', preg_replace('/[\x00-\x1F\x7F]/u', ' ', $raw) ?? '') ?? '');
        $min = (int) config('generation.topic.min_length');
        $max = (int) config('generation.topic.max_length');

        if (mb_strlen($topic) < $min) {
            throw GenerationException::rejectedTopic('Tell us a little more: a few words about what you want to practise.');
        }
        if (mb_strlen($topic) > $max) {
            throw GenerationException::rejectedTopic("Please keep it to {$max} characters: a short phrase is enough.");
        }
        if (preg_match('/[<>]/', $topic) || preg_match('#(https?://|www\.)#i', $topic)) {
            throw GenerationException::rejectedTopic('Please describe the topic in plain words, without links or markup.');
        }
        if (preg_match('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $topic) || preg_match('/[A-Za-z0-9_\-]{32,}/', $topic)) {
            throw GenerationException::rejectedTopic('That looks like it contains an email address or a key. Please describe the topic only; never paste secrets here.');
        }
        $letters = preg_match_all('/\p{L}/u', $topic);
        if ($letters < mb_strlen($topic) * 0.5) {
            throw GenerationException::rejectedTopic('Please describe the topic in words.');
        }
        foreach ((array) config('generation.topic_denylist') as $re) {
            if (preg_match($re, $topic)) {
                throw GenerationException::rejectedTopic('We can not build a practice path on that topic. Try a different one.');
            }
        }

        return $topic;
    }
}
