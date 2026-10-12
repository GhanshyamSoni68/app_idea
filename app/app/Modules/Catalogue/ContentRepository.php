<?php

namespace App\Modules\Catalogue;

use Illuminate\Support\Facades\DB;

/** Read side of the catalogue. The runtime never writes these tables (docs/04 §4.1). */
final class ContentRepository
{
    public function version(string $id): ?object
    {
        $row = DB::selectOne('SELECT * FROM content_versions WHERE id = ?', [$id]);

        return $row ? $this->decode($row) : null;
    }

    /** Latest published minor/patch of (kind, key, major) — topic_activities resolve this way at run time. */
    public function latest(string $kind, string $key, ?int $major = null): ?object
    {
        $row = DB::selectOne(
            "SELECT * FROM content_versions WHERE content_kind = ? AND content_key = ? AND status = 'published'
             AND (?::int IS NULL OR version_major = ?::int)
             ORDER BY version_major DESC, version_minor DESC, version_patch DESC LIMIT 1",
            [$kind, $key, $major, $major],
        );

        return $row ? $this->decode($row) : null;
    }

    /**
     * Items of the mission version, with the assessment_items flags.
     *
     * @return list<object>
     */
    public function missionItems(object $mission): array
    {
        $rows = DB::select(
            "SELECT cv.*, ai.mission_key, ai.role, ai.check_eligible, ai.retention_reserved, ai.scenario_key, ai.response_kind, ai.scoring, ai.skill_id, ai.objective_key, ai.objective_major
             FROM content_versions cv JOIN assessment_items ai ON ai.content_version_id = cv.id
             WHERE ai.mission_key = ? AND cv.version_major = ? AND cv.version_minor = ? AND cv.version_patch = ? AND cv.status = 'published'
             ORDER BY cv.content_key",
            [$mission->content_key, $mission->version_major, $mission->version_minor, $mission->version_patch],
        );

        return array_map(fn ($r) => $this->decode($r), $rows);
    }

    public function itemMeta(string $contentVersionId): ?object
    {
        $row = DB::selectOne(
            'SELECT cv.*, ai.role, ai.check_eligible, ai.retention_reserved, ai.scenario_key, ai.response_kind, ai.scoring, ai.skill_id, ai.objective_key, ai.objective_major, ai.mission_key
             FROM content_versions cv JOIN assessment_items ai ON ai.content_version_id = cv.id WHERE cv.id = ?',
            [$contentVersionId],
        );

        return $row ? $this->decode($row) : null;
    }

    /** The mission version an item belongs to (same X.Y.Z as the item). */
    public function missionForItem(object $item): ?object
    {
        $row = DB::selectOne(
            "SELECT * FROM content_versions WHERE content_kind = 'mission' AND content_key = ? AND version_major = ? AND version_minor = ? AND version_patch = ?",
            [$item->mission_key, $item->version_major, $item->version_minor, $item->version_patch],
        );

        return $row ? $this->decode($row) : null;
    }

    public function skillKey(string $skillId): string
    {
        return (string) DB::scalar('SELECT skill_key FROM skills WHERE id = ?', [$skillId]);
    }

    private function decode(object $row): object
    {
        $row->body = json_decode($row->body, true);

        return $row;
    }
}
