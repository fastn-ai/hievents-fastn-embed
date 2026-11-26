import {GenericDataResponse, IdParam} from "../types";
import {api} from "./client";

export interface IntegrationsEmbedToken {
    token: string;
    iframe_url: string;
    expires_at: string | null;
    product_connected: boolean | null;
}

export const organizerIntegrationsClient = {
    createEmbedToken: async (organizerId: IdParam) => {
        const response = await api.post<GenericDataResponse<IntegrationsEmbedToken>>(
            `organizers/${organizerId}/integrations/embed-token`,
        );
        return response.data.data;
    },
}
