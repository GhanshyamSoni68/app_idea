<?php

namespace App\Modules\Catalogue;

use App\Support\Clock;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Publishes validated content into the immutable catalogue tables (docs/04 §4, docs/06 §8).
 * Re-publishing identical content is a no-op; changed content under an existing version is refused:
 * a version, once published, never changes.
 */
final class ContentPublisher
{
    /** @return array{release_id:string, missions:int, items:int, roadmap_versions:int, skipped:int} */
    public function publish(array $content, string $publishedBy, ?string $sourceRef = null): array
    {
        return DB::transaction(function () use ($content, $publishedBy, $sourceRef) {
            $releaseId = Ids::new();
            DB::insert(
                'INSERT INTO content_releases (id, release_key, source_ref, manifest_hash, published_by, published_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$releaseId, Clock::now()->format('Y-m-d\THis').'-'.substr($content['hash'], 0, 8).'-'.substr($releaseId, -4), $sourceRef, $content['hash'], $publishedBy, Clock::now()],
            );

            $skillIds = $this->skills($content);
            $stats = ['release_id' => $releaseId, 'missions' => 0, 'items' => 0, 'roadmap_versions' => 0, 'skipped' => 0];

            foreach ($content['missions'] as $id => $mission) {
                if (($mission['front']['status'] ?? '') !== 'published') {
                    $stats['skipped']++;

                    continue;
                }
                $r = $this->mission($releaseId, $id, $mission, $skillIds);
                $stats['missions'] += $r['missions'];
                $stats['items'] += $r['items'];
            }
            foreach ($content['roadmaps'] as $slug => $roadmap) {
                foreach ($roadmap['versions'] as $n => $version) {
                    $stats['roadmap_versions'] += $this->roadmapVersion($releaseId, $slug, $roadmap['meta'], $n, $version, $content, $skillIds);
                }
            }

            return $stats;
        });
    }

    /** @return array<string, string> skill_key => id */
    private function skills(array $content): array
    {
        $ids = [];
        foreach ($content['skills'] as $key => $skill) {
            $existing = DB::selectOne('SELECT id FROM skills WHERE skill_key = ?', [$key]);
            if ($existing) {
                $ids[$key] = $existing->id;
                DB::update('UPDATE skills SET title = ?, is_secondary = ? WHERE id = ?', [$skill['title'], (bool) ($skill['secondary'] ?? false), $existing->id], );

                continue;
            }
            $ids[$key] = Ids::new();
            DB::insert('INSERT INTO skills (id, skill_key, title, is_secondary) VALUES (?, ?, ?, ?)', [$ids[$key], $key, $skill['title'], (bool) ($skill['secondary'] ?? false)]);
        }
        foreach ($content['skill_prerequisites'] as [$from, $to]) {
            DB::insert('INSERT INTO skill_prerequisites (skill_id, prerequisite_skill_id) VALUES (?, ?) ON CONFLICT DO NOTHING', [$ids[$to], $ids[$from]]);
        }

        return $ids;
    }

    /** @return array{missions:int, items:int} */
    private function mission(string $releaseId, string $id, array $mission, array $skillIds): array
    {
        $front = $mission['front'];
        [$maj, $min, $pat] = array_map('intval', explode('.', (string) $front['version']));
        $skillId = $skillIds[$front['primary_skill']];
        $body = [
            'front' => $front,
            'sections' => array_map(fn ($s) => ['heading' => $s['heading'], 'body' => $s['body']], $mission['sections']),
        ];
        $hash = hash('sha256', json_encode([$body, $mission['items']]));

        $existing = DB::selectOne(
            "SELECT id, content_hash FROM content_versions WHERE content_kind = 'mission' AND content_key = ? AND version_major = ? AND version_minor = ? AND version_patch = ?",
            [$id, $maj, $min, $pat],
        );
        if ($existing) {
            if ($existing->content_hash === $hash) {
                return ['missions' => 0, 'items' => 0];
            }
            throw new RuntimeException("Mission {$id} {$front['version']} is already published with different content. Bump the version: published content is immutable.");
        }

        $cvId = $this->insertVersion($releaseId, 'mission', $id, [$maj, $min, $pat], $body, $hash, $front);
        DB::insert(
            'INSERT INTO missions (content_version_id, mode, small_equivalent_key, primary_skill_id, objective_key, objective_major, estimated_minutes) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$cvId, 'practise', $id.'#small', $skillId, $front['objective_key'], $front['objective_major'], $front['time_estimate_minutes']['practise'] ?? 8],
        );

        $items = 0;
        foreach ($mission['items'] as $itemId => $item) {
            unset($item['_file']);
            $itemHash = hash('sha256', json_encode($item));
            $iv = $this->insertVersion($releaseId, 'assessment_item', $itemId, [$maj, $min, $pat], $item, $itemHash, $front);
            DB::insert(
                'INSERT INTO assessment_items (content_version_id, mission_key, skill_id, objective_key, objective_major, alternate_group_key, role,
                    check_eligible, retention_reserved, scenario_key, response_kind, scoring) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$iv, $id, $skillId, $front['objective_key'], $front['objective_major'], $id, $item['role'],
                    (bool) ($item['check_eligible'] ?? false), (bool) ($item['retention_reserved'] ?? false), $item['scenario_key'], $item['type'],
                    $item['type'] === 'self_check' ? 'self_check' : 'auto'],
            );
            $items++;
        }

        return ['missions' => 1, 'items' => $items];
    }

    private function insertVersion(string $releaseId, string $kind, string $key, array $v, array $body, string $hash, array $front): string
    {
        $id = Ids::new();
        DB::insert(
            'INSERT INTO content_versions (id, content_kind, content_key, version_major, version_minor, version_patch, status, body, content_hash,
                source_urls, stack_scope, reviewed_by, last_reviewed_on, release_id, published_at) VALUES (?, ?, ?, ?, ?, ?, \'published\', ?::jsonb, ?, ?::jsonb, ?::jsonb, ?, ?, ?, ?)',
            [$id, $kind, $key, $v[0], $v[1], $v[2], json_encode($body), $hash,
                json_encode(array_column($front['sources'] ?? [], 'url')), json_encode($front['stack_scope'] ?? new \stdClass),
                ($front['review']['reviewer'] ?? null) === 'pending' ? null : ($front['review']['reviewer'] ?? null),
                $front['review']['last_reviewed'] ?? null, $releaseId, Clock::now()],
        );

        return $id;
    }

    private function roadmapVersion(string $releaseId, string $slug, array $meta, int $n, array $version, array $content, array $skillIds): int
    {
        $hash = hash('sha256', json_encode($version));
        $roadmap = DB::selectOne('SELECT id FROM roadmaps WHERE slug = ?', [$slug]);
        if (! $roadmap) {
            $roadmapId = Ids::new();
            DB::insert('INSERT INTO roadmaps (id, slug) VALUES (?, ?)', [$roadmapId, $slug]);
        } else {
            $roadmapId = $roadmap->id;
        }

        $existing = DB::selectOne('SELECT id, changelog FROM roadmap_versions WHERE roadmap_id = ? AND version = ?', [$roadmapId, $n]);
        if ($existing) {
            if ((json_decode($existing->changelog, true)['_hash'] ?? null) === $hash) {
                return 0;
            }
            throw new RuntimeException("Roadmap {$slug} v{$n} is already published with a different structure. Publish v".($n + 1).' instead.');
        }

        $previous = DB::selectOne('SELECT id FROM roadmap_versions WHERE roadmap_id = ? AND version < ? ORDER BY version DESC LIMIT 1', [$roadmapId, $n]);
        $required = 0;
        foreach ($version['modules'] as $m) {
            foreach ($m['topics'] as $t) {
                $required += ($t['kind'] === 'required') ? 1 : 0;
            }
        }
        $versionId = Ids::new();
        DB::insert(
            'INSERT INTO roadmap_versions (id, roadmap_id, version, release_id, previous_version_id, title, outcome, estimated_effort_hours,
                estimated_effort_text, coverage_note, required_topic_count, changelog) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb)',
            [$versionId, $roadmapId, $n, $releaseId, $previous->id ?? null, $version['title'] ?? $meta['title'], trim($version['outcome'] ?? $meta['outcome']),
                $version['estimated_effort_hours'] ?? 0, $version['estimated_effort'] ?? '', trim($version['coverage_note'] ?? ''), $required,
                json_encode(['_hash' => $hash])],
        );

        $topicIds = [];
        $topicPos = 0;
        foreach ($version['modules'] as $mPos => $module) {
            $moduleId = Ids::new();
            DB::insert('INSERT INTO roadmap_modules (id, roadmap_version_id, module_key, position, title) VALUES (?, ?, ?, ?, ?)', [$moduleId, $versionId, $module['id'], $mPos + 1, $module['title']]);
            foreach ($module['topics'] as $t) {
                $topicId = Ids::new();
                $topicIds[$t['id']] = $topicId;
                $mission = isset($t['mission']) ? ($content['missions'][$t['mission']]['front'] ?? null) : null;
                $objectiveKey = $mission['objective_key'] ?? $t['id'];
                $objectiveMajor = $mission['objective_major'] ?? 1;
                DB::insert(
                    'INSERT INTO roadmap_topics (id, roadmap_version_id, module_id, topic_key, title, position, kind, objective_key, objective_major, estimated_minutes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$topicId, $versionId, $moduleId, $t['id'], $t['title'], ++$topicPos, $t['kind'], $objectiveKey, $objectiveMajor, $t['minutes'] ?? 8],
                );
                if (isset($t['mission'])) {
                    $major = (int) explode('.', (string) ($mission['version'] ?? '1.0.0'))[0];
                    DB::insert(
                        'INSERT INTO topic_activities (id, roadmap_version_id, topic_id, position, ref_kind, ref_key, ref_major, role, is_required) VALUES (?, ?, ?, 1, \'mission\', ?, ?, \'core\', true)',
                        [Ids::new(), $versionId, $topicId, $t['mission'], $major],
                    );
                    DB::insert(
                        'INSERT INTO topic_activities (id, roadmap_version_id, topic_id, position, ref_kind, ref_key, ref_major, role, is_required) VALUES (?, ?, ?, 2, \'item_group\', ?, ?, \'topic_check\', true)',
                        [Ids::new(), $versionId, $topicId, $t['mission'], $major],
                    );
                }
                DB::insert(
                    'INSERT INTO topic_completion_rules (id, roadmap_version_id, topic_id, path, objective_key, objective_major, requirements) VALUES (?, ?, ?, \'standard\', ?, ?, ?::jsonb)',
                    [Ids::new(), $versionId, $topicId, $objectiveKey, $objectiveMajor, json_encode([
                        'rule' => 'pilot_core_topic',
                        'all_of' => ['C1_mission_attempt', 'C2_feedback_reviewed', 'C3_topic_check_met'],
                        'topic_check' => ['items' => 'check_eligible', 'max_hints_total' => config('devstep.topic_check_max_hints'), 'reveal' => 'not_allowed', 'earliest_days_after_C1' => config('devstep.topic_check_delay_days')],
                    ])],
                );
                DB::insert(
                    'INSERT INTO topic_completion_rules (id, roadmap_version_id, topic_id, path, objective_key, objective_major, requirements) VALUES (?, ?, ?, \'challenge_out\', ?, ?, ?::jsonb)',
                    [Ids::new(), $versionId, $topicId, $objectiveKey, $objectiveMajor, json_encode(['rule' => 'challenge_out_passed', 'items' => 'check_eligible', 'max_attempts' => 1])],
                );
            }
        }
        foreach ($version['modules'] as $module) {
            foreach ($module['topics'] as $t) {
                foreach ($t['prerequisites'] ?? [] as $p) {
                    DB::insert(
                        'INSERT INTO topic_dependencies (roadmap_version_id, topic_id, depends_on_topic_id, strict) VALUES (?, ?, ?, ?)',
                        [$versionId, $topicIds[$t['id']], $topicIds[$p['topic']], (bool) ($p['strict'] ?? false)],
                    );
                }
            }
        }

        return 1;
    }
}
