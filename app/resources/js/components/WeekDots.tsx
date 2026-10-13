// This week's practice days. Deliberately NOT a streak: a missed day is just an empty circle, nothing breaks, nothing
// resets, and no count of missed days appears (PRD §6, docs/08 §7.2). Any practice counts, even three minutes.
const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

function addDays(day: string, n: number): string {
    const d = new Date(`${day}T12:00:00Z`);
    d.setUTCDate(d.getUTCDate() + n);

    return d.toISOString().slice(0, 10);
}

export function WeekDots({ weekStart, dates, today, target }: { weekStart: string; dates: string[]; today: string; target: number }) {
    const done = new Set(dates);

    return (
        <div className="weekcard">
            <ul aria-label="Practice days this week" style={{ display: 'flex', gap: 10, padding: 0, margin: 0 }}>
                {DAYS.map((label, i) => {
                    const day = addDays(weekStart, i);
                    const practised = done.has(day);

                    return (
                        <li key={day} style={{ listStyle: 'none', display: 'grid', justifyItems: 'center', gap: 4, fontSize: '0.75rem', fontWeight: 800, color: 'var(--text-2)' }}>
                            <span className={`dot${practised ? ' done' : ''}${day === today ? ' today' : ''}`} aria-hidden="true">{practised ? '✓' : ''}</span>
                            <span aria-hidden="true">{label[0]}</span>
                            <span className="sr-only">{label}: {practised ? 'practised' : 'no practice yet'}{day === today ? ', today' : ''}</span>
                        </li>
                    );
                })}
            </ul>
            <p className="caption">{done.size} of {target} practice days this week. Any practice counts, even 3 minutes.</p>
        </div>
    );
}
