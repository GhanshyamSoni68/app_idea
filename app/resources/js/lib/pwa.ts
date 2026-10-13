import { useEffect, useState } from 'react';

// App phase A (docs/14): DevStep is an installable web app. This module registers the service worker and
// exposes the install prompt. Nothing here nags: the install control lives in Settings only (docs/08 IR-13).

export function registerServiceWorker(): void {
    if (!('serviceWorker' in navigator) || !import.meta.env.PROD) return;
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => undefined);
    });
}

interface InstallEvent extends Event {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

export function isStandalone(): boolean {
    return window.matchMedia('(display-mode: standalone)').matches || (navigator as unknown as { standalone?: boolean }).standalone === true;
}

export function isIosSafari(): boolean {
    return /iPhone|iPad|iPod/.test(navigator.userAgent) && !isStandalone();
}

/** Browser-driven install prompt, where supported (Chromium). iOS has no prompt API, so Settings explains the steps. */
export function useInstallPrompt() {
    const [event, setEvent] = useState<InstallEvent | null>(null);
    const [installed, setInstalled] = useState(isStandalone());

    useEffect(() => {
        const onPrompt = (e: Event) => {
            e.preventDefault();
            setEvent(e as InstallEvent);
        };
        const onInstalled = () => { setInstalled(true); setEvent(null); };
        window.addEventListener('beforeinstallprompt', onPrompt);
        window.addEventListener('appinstalled', onInstalled);

        return () => {
            window.removeEventListener('beforeinstallprompt', onPrompt);
            window.removeEventListener('appinstalled', onInstalled);
        };
    }, []);

    return {
        installed,
        canPrompt: event !== null,
        async install() {
            if (!event) return;
            await event.prompt();
            await event.userChoice;
            setEvent(null);
        },
    };
}

export function useOnline(): boolean {
    const [online, setOnline] = useState(navigator.onLine);
    useEffect(() => {
        const on = () => setOnline(true);
        const off = () => setOnline(false);
        window.addEventListener('online', on);
        window.addEventListener('offline', off);

        return () => {
            window.removeEventListener('online', on);
            window.removeEventListener('offline', off);
        };
    }, []);

    return online;
}
