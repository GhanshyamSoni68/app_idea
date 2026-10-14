<?php

namespace App\Modules\Generation;

use Illuminate\Support\Facades\DB;

/** Removing generated content from the shared catalogue: a deleted learner's personal paths, and retiring a mission a reviewer rejects. */
final class GeneratedCatalogue
{
    /**
     * Delete personal paths and everything made only for them. Call after the owner (and so their attempts and evidence) is gone.
     *
     * @param  list<string>  $roadmapIds
     */
    public function purgePaths(array $roadmapIds): void
    {
        if ($roadmapIds === []) {
            return;
        }
        DB::transaction(function () use ($roadmapIds) {
            $in = implode(',', array_fill(0, count($roadmapIds), '?'));
            $versions = DB::table('roadmap_versions')->whereIn('roadmap_id', $roadmapIds)->pluck('id')->all();
            $releases = DB::table('roadmap_versions')->whereIn('roadmap_id', $roadmapIds)->pluck('release_id')->all();
            $topics = DB::table('roadmap_topics')->whereIn('roadmap_version_id', $versions)->pluck('topic_key')->all();
            $skillKeys = $topics; // a generated topic key is also its mission key and skill key
            if ($versions !== []) {
                $vin = implode(',', array_fill(0, count($versions), '?'));
                foreach (['topic_completion_rules', 'topic_activities', 'topic_dependencies'] as $t) {
                    DB::delete("DELETE FROM {$t} WHERE roadmap_version_id IN ({$vin})", $versions);
                }
                DB::delete("DELETE FROM roadmap_topics WHERE roadmap_version_id IN ({$vin})", $versions);
                DB::delete("DELETE FROM roadmap_modules WHERE roadmap_version_id IN ({$vin})", $versions);
                DB::delete("DELETE FROM roadmap_versions WHERE id IN ({$vin})", $versions);
            }
            DB::delete("DELETE FROM roadmaps WHERE id IN ({$in})", $roadmapIds);

            // The missions and items published for those topics, in every release that carried them.
            foreach ($skillKeys as $key) {
                $cvIds = DB::table('content_versions')->where(fn ($q) => $q->where('content_key', $key)->orWhere('content_key', 'like', $key.'.%'))->pluck('id')->all();
                if ($cvIds === []) {
                    continue;
                }
                $cin = implode(',', array_fill(0, count($cvIds), '?'));
                DB::delete("DELETE FROM missions WHERE content_version_id IN ({$cin})", $cvIds);
                DB::delete("DELETE FROM assessment_items WHERE content_version_id IN ({$cin})", $cvIds);
                DB::delete("DELETE FROM content_versions WHERE id IN ({$cin})", $cvIds);
            }
            if ($skillKeys !== []) {
                $sin = implode(',', array_fill(0, count($skillKeys), '?'));
                $skillIds = DB::table('skills')->whereIn('skill_key', $skillKeys)->pluck('id')->all();
                if ($skillIds !== []) {
                    $kin = implode(',', array_fill(0, count($skillIds), '?'));
                    DB::delete("DELETE FROM skill_prerequisites WHERE skill_id IN ({$kin}) OR prerequisite_skill_id IN ({$kin})", [...$skillIds, ...$skillIds]);
                    DB::delete("DELETE FROM skills WHERE id IN ({$kin})", $skillIds);
                }
            }
            foreach (array_unique($releases) as $releaseId) {
                if (! DB::table('content_versions')->where('release_id', $releaseId)->exists() && ! DB::table('roadmap_versions')->where('release_id', $releaseId)->exists()) {
                    DB::table('generated_artifacts')->where('published_release_id', $releaseId)->update(['published_release_id' => null]);
                    DB::table('content_releases')->where('id', $releaseId)->where('origin', 'generated')->delete();
                }
            }
        });
    }

    /** Retire a mission so no new session uses it. Sessions already planned keep their exact versions. */
    public function retireMission(string $missionKey, string $reason): int
    {
        return DB::update(
            "UPDATE content_versions SET status = 'retired', retired_at = now(), retire_reason = ? WHERE status = 'published' AND (content_key = ? OR content_key LIKE ?)",
            [mb_substr($reason, 0, 300), $missionKey, $missionKey.'.%'],
        );
    }
}
