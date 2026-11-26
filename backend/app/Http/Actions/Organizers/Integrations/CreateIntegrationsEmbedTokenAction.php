<?php

namespace HiEvents\Http\Actions\Organizers\Integrations;

use HiEvents\DomainObjects\Enums\Permission;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Attributes\RequiresPermission;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\Organizer\Integrations\CreateIntegrationsEmbedTokenHandler;
use HiEvents\Services\Infrastructure\Fastn\Exception\FastnNotConfiguredException;
use HiEvents\Services\Infrastructure\Fastn\Exception\FastnRequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Psr\Log\LoggerInterface;

/**
 * Integrations send order and attendee data to other apps, so they need the same permission as webhooks.
 */
#[RequiresPermission(Permission::VIEW_FINANCIALS)]
class CreateIntegrationsEmbedTokenAction extends BaseAction
{
    public function __construct(
        private readonly CreateIntegrationsEmbedTokenHandler $handler,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(int $organizerId): JsonResponse|Response
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $embed = $this->handler->handle(
                organizerId: $organizerId,
                accountId: $this->getAuthenticatedAccountId(),
                userId: $this->getAuthenticatedUser()->getId(),
                // The same conditions as creating a read and write API key by hand.
                canCreateApiKey: ! $this->isImpersonating() && $this->getAuthenticatedAccess()->can(Permission::EDIT),
            );
        } catch (FastnNotConfiguredException) {
            return $this->errorResponse(
                message: __('Integrations are not set up on this server yet.'),
                statusCode: ResponseCodes::HTTP_SERVICE_UNAVAILABLE,
            );
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        } catch (FastnRequestException $exception) {
            $this->logger->error('Could not open fastn integrations', [
                'organizer_id' => $organizerId,
                'status' => $exception->status,
                'code' => $exception->errorCode,
                'error' => $exception->getMessage(),
            ]);

            return $this->errorResponse(
                message: __('Integrations could not be loaded. Please try again.'),
                statusCode: ResponseCodes::HTTP_BAD_GATEWAY,
            );
        }

        return $this->jsonResponse([
            'token' => $embed->embedToken->token,
            'iframe_url' => $embed->embedToken->iframeUrl,
            'expires_at' => $embed->embedToken->expiresAt,
            'product_connected' => $embed->productConnected,
        ], wrapInData: true);
    }
}
