import type { ReactNode } from 'react';

// A small icon set (docs/08 §9.3). Decorative: a text label always sits beside each one.
const base = { width: 26, height: 26, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2.4, strokeLinecap: 'round', strokeLinejoin: 'round', 'aria-hidden': true, focusable: false } as const;
const wrap = (children: ReactNode) => <svg {...base}>{children}</svg>;

export const TodayIcon = () => wrap(<><circle cx="12" cy="12" r="4.2" /><path d="M12 2.8v2.4M12 18.8v2.4M2.8 12h2.4M18.8 12h2.4M5.5 5.5l1.7 1.7M16.8 16.8l1.7 1.7M5.5 18.5l1.7-1.7M16.8 7.2l1.7-1.7" /></>);
export const RoadmapIcon = () => wrap(<><path d="M5 21V4" /><path d="M5 5c3-2 5 2 8 0s4-1 6 0v8c-2-1-3-2-6 0s-5-2-8 0" /></>);
export const EvidenceIcon = () => wrap(<><circle cx="12" cy="9" r="5.2" /><path d="M8.6 13.6 7 21l5-2.6 5 2.6-1.6-7.4" /><path d="m10 9 1.4 1.4L14.2 7.6" /></>);
export const SoundOnIcon = () => wrap(<><path d="M4 9.5v5h3.6L12.5 19V5L7.6 9.5z" /><path d="M16 9a4.2 4.2 0 0 1 0 6M18.6 6.4a8 8 0 0 1 0 11.2" /></>);
export const SoundOffIcon = () => wrap(<><path d="M4 9.5v5h3.6L12.5 19V5L7.6 9.5z" /><path d="m16.4 9.6 4.4 4.8M20.8 9.6l-4.4 4.8" /></>);
