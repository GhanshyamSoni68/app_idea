// Shapes returned by the /v1 API (docs/02 §5.3). The client ignores unknown fields.

export type Mode = 'small' | 'practise' | 'build';
export type Level = 'introduced' | 'practised' | 'demonstrated' | 'retained';
export type Basis = 'auto_scored' | 'self_assessed' | 'learner_submitted' | 'human_reviewed';
export type Outcome = 'correct' | 'partially_correct' | 'incorrect' | 'self_met' | 'self_not_met';

export interface Me {
    id: string;
    email: string;
    email_verified: boolean;
    time_zone: string | null;
    onboarding_completed: boolean;
    subject_id: string;
    deletion: { pending: boolean; effective_at?: string };
}

export interface ItemOption { key: string; text: string }
export interface RubricCriterion { id: string; weight: 'essential' | 'supporting'; text: string }
export interface Item {
    content_version_id: string;
    key: string;
    type: 'single_choice' | 'multi_select' | 'ordering' | 'self_check';
    scenario: string | null;
    prompt: string;
    time_estimate_minutes: number | null;
    evidence_basis: Basis;
    hint_count: number;
    options?: ItemOption[];
    rubric?: RubricCriterion[];
    exemplar?: string;
    min_chars?: number;
}

export interface Feedback {
    text: string;
    correct_key?: string;
    correct_keys?: string[];
    correct_order?: ItemOption[] | null;
    model_answer?: string | null;
    exemplar?: string;
    misconception?: { id: string; note: string } | null;
}

export interface AttemptResult {
    attempt_id?: string;
    outcome: Outcome;
    assistance: 'none' | 'hint' | 'worked_example' | 'solution_revealed';
    hint_count: number;
    evidence_basis: Basis;
    feedback: Feedback;
    feedback_viewed?: boolean;
    revisit_hint?: string;
    notice?: string;
}

export interface Step {
    index: number;
    key: string;
    kind: 'scenario' | 'explanation' | 'response';
    purpose: string;
    stop_after: boolean;
    title: string | null;
    body: string;
    item: Item | null;
    attempt: (AttemptResult & { id: string; attempts: number }) | null;
    content_version_id: string | null;
}

export interface SessionPayload {
    session: { id: string; mode: Mode; purpose: string; status: string; estimated_minutes: number; context: string | null; current_step: number; content_version_id: string | null };
    steps: Step[];
    draft: { revision: number; step_key: string; data: Record<string, unknown>; device_label: string | null; saved_at: string } | null;
    resumed: boolean;
}

export interface Action {
    kind: string;
    reason_code: string;
    mode: Mode | null;
    estimated_minutes: number | null;
    title: string;
    rationale: string;
    context: string | null;
    session_id: string | null;
    recommendation_id: string | null;
    reviews_included: number;
}

export interface Today {
    local_date: string;
    time_zone: string;
    situation: 'first_run' | 'ready' | 'resume' | 'returning' | 'done_for_today' | 'paused' | 'nothing_ready' | 'roadmap_complete';
    welcome_back: { days_away: number; last_work: { title: string; date: string } } | null;
    primary_action: Action;
    secondary_actions: Action[];
    smaller_option: Action | null;
    reviews_included: number;
    weekly_progress: { practice_days_target: number; practice_days_done: number; week_start: string };
    enrolment: { id: string; roadmap_slug: string; status: string; completed_required: number; total_required: number } | null;
}

export interface EvidenceItem {
    skill_id: string;
    skill_key: string;
    title: string;
    level: Level;
    first_achieved_at: string;
    last_confirmed_at: string;
    basis: Basis;
    also_basis: Basis[];
    assistance: string;
    limitations: string[];
    refresh_suggested: boolean;
    next_practice_on: string | null;
}

export interface RoadmapTopic {
    id: string;
    topic_key: string;
    title: string;
    kind: 'required' | 'optional';
    estimated_minutes: number;
    state: 'not_started' | 'in_progress' | 'completed' | 'deferred';
    completion_path: string | null;
    mission_activity_done: boolean;
    has_content: boolean;
    prerequisites: { topic_key: string; title: string; strict: boolean }[];
    unmet_prerequisites: { topic_key: string; title: string; strict: boolean }[];
    ready: boolean;
    challenge_available?: boolean;
}
export interface RoadmapView {
    enrolment: { id: string; status: string; completed_at: string | null };
    roadmap: { title: string; outcome: string; estimated_effort: string; coverage_note: string; version: number };
    progress: { completed_required: number; total_required: number; percent: number };
    modules: { module_key: string; title: string; topics: RoadmapTopic[] }[];
}

export interface CompleteResult {
    session_id: string;
    completed_at: string;
    already_completed?: boolean;
    summary: {
        steps_done: number;
        attempts: number;
        evidence_changes: { skill_id: string; skill_key: string; title: string; level: Level; basis: Basis; assistance: string }[];
        effort_highlights: string[];
    };
    topic_changes: { topic_key: string; title: string; from_state: string; to_state: string }[];
    challenge: { passed: boolean } | null;
    roadmap_progress: { completed_required: number; total_required: number; percent: number } | null;
    milestone: { completed_at: string; note: string } | null;
    next_suggestion: { local_date: string; weekday: string } | null;
}

export interface SampleMission {
    content_version_id: string;
    id: string;
    title: string;
    purpose: string;
    objective: string;
    estimated_minutes: { practise: number; small: number };
    steps: { key: string; kind: 'scenario' | 'explanation' | 'response'; title: string; body: string; stop_after: boolean; item: Item | null }[];
}
