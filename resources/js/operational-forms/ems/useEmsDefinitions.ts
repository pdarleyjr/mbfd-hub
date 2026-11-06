import { useCallback, useEffect, useRef, useState } from 'react';
import { EmsApiError, type EmsApi } from './emsApi';
import { DEFAULT_FAMILY, familyOfDefinition } from './emsLogic.js';
import type { EmsFormDefinition } from './emsTypes';

/**
 * The lists of the form families the member has opened, one definition per family, loaded once per page.
 * A definition is never shared between families: each one is asked for by its own key, and an answer for a different
 * family (a server from before the four forms ignores the key) is refused instead of shown under the wrong name.
 */
export function useEmsDefinitions(api: EmsApi) {
    const [definitions, setDefinitions] = useState<Record<string, EmsFormDefinition>>({});
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [loading, setLoading] = useState<Record<string, boolean>>({});
    const loaded = useRef<Record<string, EmsFormDefinition>>({});
    const running = useRef(new Map<string, Promise<EmsFormDefinition>>());

    const load = useCallback((family: string): Promise<EmsFormDefinition> => {
        const known = loaded.current[family];
        if (known) return Promise.resolve(known);
        const inFlight = running.current.get(family);
        if (inFlight) return inFlight;

        setLoading((state) => ({ ...state, [family]: true }));
        setErrors((state) => ({ ...state, [family]: '' }));
        const request = api.form(family).then((definition) => {
            if (familyOfDefinition(definition) !== family) {
                throw new EmsApiError('This form is not available yet. Contact the EMS office if this keeps happening.', 404, 'unknown_family');
            }
            loaded.current[family] = definition;
            setDefinitions((state) => ({ ...state, [family]: definition }));
            return definition;
        }).catch((caught: unknown) => {
            const message = caught instanceof EmsApiError ? caught.message : 'The request form could not be loaded.';
            setErrors((state) => ({ ...state, [family]: message }));
            throw caught;
        }).finally(() => {
            running.current.delete(family);
            setLoading((state) => ({ ...state, [family]: false }));
        });
        running.current.set(family, request);
        return request;
    }, [api]);

    return { definitions, errors, loading, load };
}

/** Starts loading a family's definition when a view needs it and nothing is loaded, loading or failed yet. */
export function useWantFamily(
    wanted: string | null,
    state: ReturnType<typeof useEmsDefinitions>,
) {
    const { definitions, errors, loading, load } = state;
    const family = wanted ?? DEFAULT_FAMILY;
    const shouldLoad = wanted !== null && !definitions[family] && !loading[family] && !errors[family];
    useEffect(() => { if (shouldLoad) load(family).catch(() => undefined); }, [shouldLoad, family, load]);
}
