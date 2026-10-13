import { createContext, type ReactNode, useCallback, useContext, useEffect, useState } from 'react';
import { api, ApiError } from '../api';
import type { Me } from '../types';

interface AuthState {
    me: Me | null;
    loading: boolean;
    offline: boolean;
    refresh: () => Promise<Me | null>;
    signOut: () => Promise<void>;
    setMe: (m: Me | null) => void;
}

const Ctx = createContext<AuthState>({ me: null, loading: true, offline: false, refresh: async () => null, signOut: async () => undefined, setMe: () => undefined });
export const useAuth = () => useContext(Ctx);

export function AuthProvider({ children }: { children: ReactNode }) {
    const [me, setMe] = useState<Me | null>(null);
    const [loading, setLoading] = useState(true);
    const [offline, setOffline] = useState(false);

    const refresh = useCallback(async () => {
        try {
            const r = await api.get<{ user: Me }>('/me');
            setMe(r.user);
            setOffline(false);

            return r.user;
        } catch (e) {
            if (e instanceof ApiError && e.code === 'offline') setOffline(true); // not signed out: just unreachable
            else if (!(e instanceof ApiError) || e.status === 401) setMe(null);

            return null;
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        void refresh();
    }, [refresh]);

    const signOut = useCallback(async () => {
        await api.post('/auth/sign-out').catch(() => undefined);
        setMe(null);
    }, []);

    return <Ctx.Provider value={{ me, loading, offline, refresh, signOut, setMe }}>{children}</Ctx.Provider>;
}
