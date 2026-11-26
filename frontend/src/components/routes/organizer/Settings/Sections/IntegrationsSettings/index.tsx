import {useParams} from "react-router";
import {t} from "@lingui/macro";
import {Alert, Button, Center, Loader} from "@mantine/core";
import {IconAlertCircle, IconInfoCircle} from "@tabler/icons-react";
import {Card} from "../../../../../common/Card";
import {HeadingWithDescription} from "../../../../../common/Card/CardHeading";
import {useGetIntegrationsEmbedToken} from "../../../../../../queries/useGetIntegrationsEmbedToken.ts";

const WIDGET_HEIGHT = 600;

export const IntegrationsSettings = () => {
    const {organizerId} = useParams();
    const embedTokenQuery = useGetIntegrationsEmbedToken(organizerId);
    const errorStatus = (embedTokenQuery.error as { response?: { status?: number } } | null)?.response?.status;

    return (
        <Card>
            <HeadingWithDescription
                heading={t`Integrations`}
                description={t`Connect the apps you already use, such as your CRM, email marketing or team chat, to this organizer.`}
            />

            {embedTokenQuery.isLoading && (
                <Center h={WIDGET_HEIGHT}>
                    <Loader/>
                </Center>
            )}

            {embedTokenQuery.isError && errorStatus === 503 && (
                <Alert icon={<IconInfoCircle size={16}/>} color="gray">
                    {t`Integrations are not set up on this server yet.`}
                </Alert>
            )}

            {embedTokenQuery.isError && errorStatus !== 503 && (
                <Alert icon={<IconAlertCircle size={16}/>} color="red" title={t`Integrations could not be loaded`}>
                    <Button
                        mt="xs"
                        size="xs"
                        variant="light"
                        color="red"
                        loading={embedTokenQuery.isFetching}
                        onClick={() => embedTokenQuery.refetch()}
                    >
                        {t`Try again`}
                    </Button>
                </Alert>
            )}

            {embedTokenQuery.data?.product_connected === false && (
                <Alert icon={<IconAlertCircle size={16}/>} color="yellow" mb="md">
                    {t`Integrations could not be linked to this organizer, so they will not receive its orders and attendees. Ask someone who can edit this account and has confirmed their email address to open this page.`}
                </Alert>
            )}

            {embedTokenQuery.data && (
                <iframe
                    title={t`Integrations`}
                    src={embedTokenQuery.data.iframe_url}
                    style={{width: '100%', height: WIDGET_HEIGHT, border: 'none', display: 'block'}}
                    allow="clipboard-write"
                />
            )}
        </Card>
    );
}
