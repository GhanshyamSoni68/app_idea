# DevStep sound pack v1

Ten original synthesized cues. Open DevStep-Sound-Preview.html in a browser to audition them offline. This is a first sound direction, not a replica of Duolingo audio.

## Signature
D–F#–A ascending motif, soft digital marimba / droplet timbre; milestones resolve upward to D. Small UI actions use fragments. No external recordings or samples.

## Integration
- Use WAV files directly via HTMLAudioElement / your app audio player. Load after user interaction; respect browser autoplay restrictions.
- Store a Sound effects preference; make mute available immediately.
- Use event IDs from manifest.json. Play one cue at a time; stop the old cue before a new one.
- Trigger correct OR try-again only after authoritative feedback, not on every attempt submission.
- If an answer also ends a lesson, play lesson-complete only. Milestone takes priority over lesson-complete.
- Keep typing, autosave, scrolling, errors during typing, and passive navigation silent. No rapid repeated tap sounds.
- Welcome only once on intentional session start. Rest never sounds like failure.
- Pair every cue with visible feedback. No sound for missed practice or inactivity.
- These files have been checked for valid PCM, lengths and peak headroom. Final listening / loudness balancing on phone speakers and headphones is still required.

## Format
44.1 kHz, 16-bit mono PCM WAV. Short cues, no voice. Generated with the included build.py for reproducible tuning. No repository changes made.
