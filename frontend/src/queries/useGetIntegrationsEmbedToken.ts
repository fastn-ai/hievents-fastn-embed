import {useQuery} from "@tanstack/react-query";
import {organizerIntegrationsClient} from "../api/organizer-integrations.client.ts";
import {IdParam} from "../types.ts";

export const GET_INTEGRATIONS_EMBED_TOKEN_QUERY_KEY = 'getIntegrationsEmbedToken';

// The token is short-lived and for one visit, so it is never reused from the cache: each time the integrations
// section mounts, the server mints a new one.
export const useGetIntegrationsEmbedToken = (organizerId: IdParam | undefined, enabled = true) => {
    return useQuery({
        queryKey: [GET_INTEGRATIONS_EMBED_TOKEN_QUERY_KEY, organizerId],
        queryFn: async () => await organizerIntegrationsClient.createEmbedToken(organizerId as IdParam),
        enabled: enabled && !!organizerId,
        staleTime: Infinity,
        gcTime: 0,
        retry: false,
        refetchOnWindowFocus: false,
        refetchOnReconnect: false,
    });
}
