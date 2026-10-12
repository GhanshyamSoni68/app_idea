import { createContext, type ReactNode, useCallback, useContext, useEffect, useState } from 'react';
import { api, ApiError } from '../api';
import type { Me } from '../types';

interface AuthState {
    me: Me | null;
    loading: boolean;
    refresh: () => Promise<Me | null>;
    signOut: () => Promise<void>;
    setMe: (m: Me | null) => void;
}

const Ctx = createContext<AuthState>({ me: null, loading: true, refresh: async () => null, signOut: async () => undefined, setMe: () => undefined });
export const useAuth = () => useContext(Ctx);

export function AuthProvider({ children }: { children: ReactNode }) {
    const [me, setMe] = useState<Me | null>(null);
    const [loading, setLoading] = useState(true);

    const refresh = useCallback(async () => {
        try {
            const r = await api.get<{ user: Me }>('/me');
            setMe(r.user);

            return r.user;
        } catch (e) {
            if (!(e instanceof ApiError) || e.status === 401) setMe(null);

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

    return <Ctx.Provider value={{ me, loading, refresh, signOut, setMe }}>{children}</Ctx.Provider>;
}
