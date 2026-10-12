<?php

namespace App\Modules\Catalogue;

use Symfony\Component\Yaml\Yaml;

/**
 * Reads the content directory (docs/06 §4). The loader only parses; ContentValidator judges.
 * Parse problems are collected, not thrown, so one broken file does not hide the others.
 */
final class ContentLoader
{
    /** @var list<array{code:string,path:string,message:string}> */
    public array $problems = [];

    /** @return array{skills: array, skill_prerequisites: array, roadmaps: array, missions: array, hash: string} */
    public function load(string $root): array
    {
        $root = rtrim($root, '/');
        $skills = [];
        $prereqs = [];
        $file = $root.'/skills/skills.yaml';
        if (is_file($file)) {
            $data = $this->yaml($file);
            foreach ($data['skills'] ?? [] as $skill) {
                $skills[$skill['id'] ?? '?'] = $skill;
            }
            $prereqs = $data['prerequisites'] ?? [];
        }

        $roadmaps = [];
        foreach (glob($root.'/roadmaps/*/roadmap.yaml') ?: [] as $rf) {
            $dir = dirname($rf);
            $meta = $this->yaml($rf);
            $versions = [];
            foreach (glob($dir.'/versions/v*.yaml') ?: [] as $vf) {
                $v = $this->yaml($vf);
                $v['_file'] = $vf;
                $versions[(int) ($v['version'] ?? 0)] = $v;
            }
            ksort($versions);
            $roadmaps[basename($dir)] = ['meta' => $meta, 'versions' => $versions, '_dir' => $dir];
        }

        $missions = [];
        foreach (glob($root.'/missions/*/mission.md') ?: [] as $mf) {
            $dir = dirname($mf);
            $missions[basename($dir)] = $this->mission($dir);
        }

        return [
            'skills' => $skills,
            'skill_prerequisites' => $prereqs,
            'roadmaps' => $roadmaps,
            'missions' => $missions,
            'hash' => hash('sha256', json_encode([$skills, $prereqs, $roadmaps, $missions])),
        ];
    }

    private function mission(string $dir): array
    {
        $raw = (string) file_get_contents($dir.'/mission.md');
        [$front, $body] = $this->frontMatter($raw, $dir.'/mission.md');

        $sections = [];
        $headings = [];
        $current = null;
        $buffer = [];
        $inFence = false;
        foreach (preg_split('/\R/', $body) as $line) {
            if (preg_match('/^```/', $line)) {
                $inFence = ! $inFence;
            }
            if (! $inFence && preg_match('/^## (.+)$/', $line, $m)) {
                if ($current !== null) {
                    $sections[$current] = ['heading' => $headings[$current] ?? $current, 'body' => trim(implode("\n", $buffer))];
                }
                $heading = trim($m[1]);
                $current = self::slug($heading);
                $headings[$current] = $heading;
                $buffer = [];

                continue;
            }
            $buffer[] = $line;
        }
        if ($current !== null) {
            $sections[$current] = ['heading' => $headings[$current] ?? $current, 'body' => trim(implode("\n", $buffer))];
        }

        $items = [];
        foreach (glob($dir.'/items/*.yaml') ?: [] as $f) {
            $item = $this->yaml($f);
            $item['_file'] = basename($f);
            $items[$item['id'] ?? basename($f, '.yaml')] = $item;
        }

        return ['front' => $front, 'sections' => $sections, 'items' => $items, '_dir' => $dir];
    }

    public static function slug(string $heading): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($heading)), '-');
    }

    /** @return array{0: array, 1: string} */
    private function frontMatter(string $raw, string $path): array
    {
        if (! preg_match('/\A---\R(.*?)\R---\R?(.*)\z/s', $raw, $m)) {
            $this->problems[] = ['code' => 'V01', 'path' => $path, 'message' => 'Missing YAML front matter.'];

            return [[], $raw];
        }
        try {
            $front = Yaml::parse($m[1]) ?? [];
        } catch (\Throwable $e) {
            $this->problems[] = ['code' => 'V01', 'path' => $path, 'message' => 'Front matter is not valid YAML: '.$e->getMessage()];
            $front = [];
        }

        return [$front, $m[2]];
    }

    private function yaml(string $file): array
    {
        try {
            $data = Yaml::parseFile($file);

            return is_array($data) ? $data : [];
        } catch (\Throwable $e) {
            $this->problems[] = ['code' => 'V01', 'path' => $file, 'message' => 'Not valid YAML: '.$e->getMessage()];

            return [];
        }
    }
}
