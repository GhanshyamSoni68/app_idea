// What Step says. Calm, specific and kind: never guilt, never comparison, never a count of missed days, never a
// mastery claim after one exercise (PRD §1, §6, §9; docs/08 §7; docs/15). A unit test scans every line for the
// words the docs forbid, so a careless edit cannot ship them.

export const MASCOT_NAME = 'Step';

export type Context =
    | 'landing' | 'onboarding' | 'sample_intro' | 'today_ready' | 'today_resume' | 'today_done' | 'today_returning' | 'today_paused'
    | 'today_nothing' | 'today_first' | 'feedback_ok' | 'feedback_notyet' | 'feedback_self' | 'hint' | 'reveal' | 'complete_practised'
    | 'complete_demonstrated' | 'complete_topic' | 'complete_roadmap' | 'challenge_start' | 'challenge_pass' | 'challenge_try'
    | 'explore' | 'explore_working' | 'explore_ready' | 'explore_trouble' | 'roadmap' | 'evidence_empty' | 'evidence' | 'offline' | 'paused_roadmap' | 'settings' | 'signin' | 'signup' | 'sample_done' | 'saved';

export const LINES: Record<Context, string[]> = {
    landing: ['Hi, I am Step. One small task at a time, and I will keep track of what you can really do.', 'Hello. I am Step. Let us find one useful thing to practise.'],
    onboarding: ['A few quick questions so I can pick a good first step. You can change any answer later.', 'Nothing here is a test. I just want to start in the right place.'],
    sample_intro: ['Here is one real scenario. Take your time; there is no timer.'],
    today_first: ['Ready when you are. Let us look at the roadmap first.'],
    today_ready: ['Here is your one next step. It is small on purpose.', 'One thing for today, and it fits the time you have.', 'Start with this one. I will keep the rest out of your way.'],
    today_resume: ['Your draft is saved. Pick up right where you stopped.', 'Nothing is lost. Let us carry on.'],
    today_done: ['Done for today. That counts, and you can stop here.', 'Good work. The rest can wait until next time.'],
    today_returning: ['Welcome back. Your plan moved on, so there is nothing to catch up.', 'Good to see you. A short refresher, then we continue.'],
    today_paused: ['Your roadmap is resting, and your progress is kept. Resume whenever you like.'],
    today_nothing: ['You are up to date. A rest day is part of the plan.'],
    feedback_ok: ['You read that carefully.', 'That is the right move, and here is why.', 'You checked before guessing. That is the skill.'],
    feedback_notyet: ['Not quite, and that is useful to know. Here is what to look at.', 'This is where the learning happens. Read the note below.', 'Close. One thing to look at again.'],
    feedback_self: ['This one is your own judgement. Be honest with yourself; it helps most.'],
    hint: ['A nudge, not the answer. You are doing the thinking.'],
    reveal: ['Seeing the answer is fine. We will bring a fresh question back later.'],
    complete_practised: ['You worked through it, and that is practice. You can stop here.', 'Good session. A check like this comes back in a few days.'],
    complete_demonstrated: ['On a new task, with no help, you showed it. That is real evidence.'],
    complete_topic: ['Topic complete. You covered it and your understanding was checked.'],
    complete_roadmap: ['You have covered the whole roadmap. Reviews will keep your skills fresh, and that is optional.'],
    challenge_start: ['Two short questions, no hints, no timer. Whatever happens, you will know where to start.'],
    challenge_pass: ['You already knew this one, so it is completed.'],
    challenge_try: ['Good to know where to start. The mission is next.'],
    explore: ['Tell me what you want to practise. I will make a short path for it.', 'Anything about software is fair game. Start with a few words.'],
    explore_working: ['I am planning your path. It takes a minute; you can stay or come back later.'],
    explore_ready: ['Your path is ready. Start it now, or look it over first.'],
    explore_trouble: ['That did not work out this time, and nothing was changed. You can try again.'],
    roadmap: ['This is the whole path, one topic at a time. You never need to look further than the next one.'],
    evidence_empty: ['Nothing here yet. Your first attempt will show up as practised.'],
    evidence: ['This is what you can show, with dates and limits. It only goes up.'],
    offline: ['You are offline. You can keep writing; I will keep it safe on this device.'],
    paused_roadmap: ['Resting. Your progress and drafts are kept.'],
    settings: ['Make it comfortable. You can even turn me down or off.'],
    signin: ['Welcome back.'],
    signup: ['Nearly there. Your sample answers come with you.'],
    sample_done: ['That was real practice. Keep it by making an account, or stop here.'],
    saved: ['Saved on this device.'],
};

/** Deterministic per day and context, so the line does not flicker on every render. */
export function say(context: Context, seed = ''): string {
    const lines = LINES[context];
    let h = 0;
    for (const ch of `${context}|${seed}`) h = (h * 31 + ch.charCodeAt(0)) >>> 0;

    return lines[h % lines.length];
}
