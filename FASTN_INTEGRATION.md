# Embedded integrations in Hi.Events with fastn

This fork adds an **Integrations** section to an organizer's settings. It is powered by
[fastn](https://fastn.ai), an embedded integration platform. The first integration is:

> A ticket is sold in Hi.Events, and the ticket holder becomes a contact in the organizer's HubSpot.

The organizer connects HubSpot and nothing else. Hi.Events connects itself to fastn on their behalf.

Upstream project: [HiEventsDev/Hi.Events](https://github.com/HiEventsDev/Hi.Events). Everything here is on top of it and
keeps its AGPL-3.0 licence.

## Contents

- [How it works](#how-it-works)
- [What a new organizer does](#what-a-new-organizer-does)
- [What you need](#what-you-need)
- [Set up fastn](#set-up-fastn)
- [Set up Hi.Events](#set-up-hievents)
- [Run it locally](#run-it-locally)
- [Test it](#test-it)
- [What changed in the code](#what-changed-in-the-code)
- [Security](#security)
- [Known limits](#known-limits)

## How it works

```
Organizer opens Settings > Integrations
        |
        v
Hi.Events backend
   1. registers the organizer as a fastn customer      POST /v1/orgs          (first visit)
   2. mints a short-lived embed token                  POST /v1/embed/token   (every visit)
   3. creates a Hi.Events API key for the organizer
      and stores it in fastn as their connection       POST /v1/connections   (first visit)
        |
        v
Browser renders fastn's iframe with the embed token
   - the organizer clicks Connect on HubSpot and signs in
   - the organizer switches the "Hi.Events ticket sold" trigger on
        |
        v
fastn registers a webhook on that organizer in Hi.Events, using the stored API key


Ticket sold
   Hi.Events webhook (attendee.created)
        -> fastn trigger
        -> fastn workflow
             reads the attendee and its order from Hi.Events
             finds the HubSpot contact by email
             creates it, or updates it if it exists
```

Three ideas carry the design:

- **One organizer is one fastn customer.** Its id in fastn is `hievents-organizer-<organizer id>`. Each customer has
  its own connections, its own webhook and its own copy of the field mapping.
- **The fastn API key never reaches the browser.** The backend holds the key and the identity secret. The browser only
  gets an embed token that expires.
- **Hi.Events is a connector in fastn, like HubSpot is.** The workflow reads Hi.Events through that connector with the
  organizer's own API key, so one organizer can never read another's data.

Every backend call to fastn names the customer and the user it is for, and signs the pair:

```
x-org-id:        hievents-organizer-7
x-user-id:       42
x-identity-hmac: HMAC-SHA256("hievents-organizer-7\n42", FASTN_IDV_SECRET)   (hex)
```

When registering a customer there is no customer yet, so `x-org-id` is left out and the signed string is `"\n42"`.

## What a new organizer does

1. Opens **Settings > Integrations**. The backend does the fastn setup. They get the usual "API key created" email.
2. Clicks **Connect** on HubSpot and signs in.
3. Opens **Triggers** on the HubSpot setup and switches **Hi.Events ticket sold** on. fastn creates a customer's
   trigger switched off, so that nothing starts sending data before someone chooses to.
4. From then on, every ticket sold creates or updates a HubSpot contact within a few seconds.

## What you need

- A fastn workspace, with a **live API key** and its **identity verification secret**.
- In that workspace: a connector for Hi.Events, a workflow, a field mapping and a widget (see below).
- A Hi.Events instance that fastn can reach over the internet. fastn calls the Hi.Events API, so `localhost` is not
  enough. Locally, use a tunnel.
- A HubSpot account to test with.

## Set up fastn

The pieces live in the [`fastn/`](fastn/) folder so they can be recreated in any workspace. The quickest way to build
them is fastn's own agent skills (connector builder and integration builder), which is how these were made.

1. **Connector for Hi.Events.** Build it from the Hi.Events API docs at <https://hi.events/docs>.
   - One auth method of type INPUT with two fields: `token` (a Hi.Events API key, sent as a bearer token) and
     `baseUrl` (`https://api.hi.events` for the cloud, `<your app>/api` when self-hosted).
   - The 22 actions are listed in [`fastn/connector-actions.md`](fastn/connector-actions.md). The integration itself
     uses `getAttendee`, `getOrder`, `listOrganizers`, `createOrganizerWebhook` and `deleteOrganizerWebhook`.
   - Register the connector as **your product** in fastn. That makes it per-customer.
2. **Webhook event.** Add an event webhook config on the connector for `attendee.created`, keyed on `event_type`.
   - Subscribe code: [`fastn/webhook-subscribe.js`](fastn/webhook-subscribe.js). It reads the organizer id from the
     customer reference and creates an organizer webhook in Hi.Events.
   - Unsubscribe code: [`fastn/webhook-unsubscribe.js`](fastn/webhook-unsubscribe.js).
   - A real payload: [`fastn/attendee-created.sample.json`](fastn/attendee-created.sample.json).
3. **Field mapping.** [`fastn/mapping.json`](fastn/mapping.json): attendee `email`, `first_name` and `last_name` go to
   the contact's `email`, `firstname` and `lastname`. Only `ACTIVE` attendees on `COMPLETED` orders are sent. Contacts
   are matched by email.
4. **Workflow.** [`fastn/workflow.js`](fastn/workflow.js). Replace `REPLACE_WITH_YOUR_CONFIG_ID` with the id of the
   mapping you saved. Set both connectors (`hiEvents` and `hubspot`) to per-customer scope, then publish.
   The 24 test cases it was checked against are in [`fastn/test-cases.json`](fastn/test-cases.json).
5. **Trigger.** An app event trigger on the Hi.Events connector for `attendee.created`, routed to the workflow.
6. **Widget.** A widget with the HubSpot and Hi.Events connectors, the workflow, the trigger and the mapping attached.

Note down the connector id and its auth method id. Hi.Events needs both.

## Set up Hi.Events

Add these to the backend environment (`backend/.env`, or `docker/all-in-one/.env` for the all-in-one image):

| Variable | Needed | What it is |
|---|---|---|
| `FASTN_API_KEY` | yes | The live API key from the fastn dashboard. |
| `FASTN_IDV_SECRET` | yes | The identity verification secret of that key. |
| `FASTN_API_URL` | no | fastn's API host. Defaults to `https://api.fastn.dev`. |
| `FASTN_CONNECTOR_ID` | for auto-connect | The id of the Hi.Events connector in fastn. |
| `FASTN_CONNECTOR_AUTH_METHOD_ID` | for auto-connect | The id of that connector's auth method. |
| `FASTN_PRODUCT_API_URL` | for auto-connect | The public URL of this Hi.Events API, as fastn reaches it, for example `https://events.example.com/api`. |

- Without the first two, the Integrations section says integrations are not set up on this server.
- Without the last three, the widget still loads but Hi.Events does not connect itself to fastn, so the ticket-sold
  flow cannot run. Set all three.

## Run it locally

```bash
cd docker/all-in-one
cp .env.example .env          # set APP_KEY, JWT_SECRET and the FASTN_* values
docker compose up -d          # http://localhost:8123
```

Two things to know:

- **Source changes need a rebuilt image.** `docker build -f Dockerfile.all-in-one -t hievents-local:fastn .` from the
  repo root, then point compose at it with a `docker-compose.override.yml` (`image: hievents-local:fastn`,
  `pull_policy: never`). If the frontend build runs out of memory (exit 134), add
  `ENV NODE_OPTIONS=--max-old-space-size=6144` to the frontend stage of the Dockerfile.
- **fastn must reach your machine.** Start a tunnel, for example `cloudflared tunnel --url http://localhost:8123`, and
  set `FASTN_PRODUCT_API_URL=<tunnel url>/api`. A quick tunnel gets a new URL on every restart. Connections already
  stored in fastn keep the old URL. To store one again, run
  `php artisan cache:forget fastn:product-connection:<organizer id>` and open that organizer's Integrations section.

## Test it

1. Sign up at `http://localhost:8123/auth/register`. A self-hosted instance does not ask to confirm the email.
2. Create an organizer and an event, and add a free ticket to the event.
3. Open the organizer's **Settings > Integrations**, click **Connect** on HubSpot and sign in.
4. Open **Triggers** on the HubSpot setup and switch **Hi.Events ticket sold** on.
5. Open the event, go to **Attendees** and add an attendee with a new email.
6. Look for that email in HubSpot contacts after a few seconds.

If the contact does not appear, open **Webhooks** in the organizer sidebar and read the delivery log:

| Response | Meaning |
|---|---|
| `202` | fastn accepted the event. Look at the workflow's runs in fastn. |
| `404` "Trigger not found or disabled" | The trigger is switched off (step 4). |
| no delivery | fastn has not registered the webhook. Check that the three auto-connect variables are set and that fastn can reach `FASTN_PRODUCT_API_URL`. |

Backend tests:

```bash
cd backend
vendor/bin/phpunit --filter "ProductConnectionServiceTest|CreateIntegrationsEmbedTokenHandlerTest"
```

## What changed in the code

Backend (Laravel):

| File | What it does |
|---|---|
| `app/Services/Infrastructure/Fastn/FastnClient.php` | The calls to fastn, each signed with the identity secret. Storing a connection that already exists replaces its credentials. |
| `app/Services/Application/Handlers/Organizer/Integrations/CreateIntegrationsEmbedTokenHandler.php` | Registers the organizer once, mints the token, then makes sure the organizer is connected. |
| `app/Services/Domain/Integrations/ProductConnectionService.php` | Creates the organizer's API key and stores it in fastn as its Hi.Events connection. |
| `app/Http/Actions/Organizers/Integrations/CreateIntegrationsEmbedTokenAction.php` | `POST /organizers/{id}/integrations/embed-token`. Session only, rate limited, needs the view-financials permission. |
| `config/services.php`, `routes/api.php` | Config keys and the route. |

Frontend (React):

| File | What it does |
|---|---|
| `frontend/src/components/routes/organizer/Settings/Sections/IntegrationsSettings/index.tsx` | The Integrations section: fastn's iframe, loading, error and not-set-up states. |
| `frontend/src/queries/useGetIntegrationsEmbedToken.ts`, `frontend/src/api/organizer-integrations.client.ts` | Fetch the embed token. |
| `frontend/src/components/routes/organizer/Settings/index.tsx` | Adds the section to the settings menu. |

## Security

- **Keys stay on the server.** `FASTN_API_KEY` and `FASTN_IDV_SECRET` are read only by the backend.
- **The endpoint is session only.** A Hi.Events API key cannot call it, so a key cannot mint a token for the widget.
- **The automatic API key.** The first time someone opens Integrations for an organizer, the backend creates a
  Hi.Events API key named `Integrations (organizer <id>)` and sends it to fastn. This skips the password confirmation
  that creating a key by hand asks for. The other conditions are kept: the user must be able to edit the account, must
  have a confirmed email address, must not be impersonating, and must be under the key limit. Hi.Events emails them
  that a key was created.
- **What that key can do.** It has read and write access to the whole account, not one organizer, and it does not
  expire. Revoking it on the API keys page disconnects the integration. The next visit to Integrations creates a new
  one.
- **The key belongs to the user who opened Integrations first.** If that user leaves the account, the key stops
  working and the next visit by someone else replaces it.

## Known limits

- **Webhook signatures are not checked.** Hi.Events signs each webhook with that webhook's own secret, and a fastn
  webhook config holds one secret per connector. The trigger URL is unguessable, but anyone who has it can post events.
- **No retry and no catch-up.** Hi.Events sends each webhook once. A sale made while the trigger is off, or while
  fastn is unreachable, is not synced later.
- **A new organizer must switch the trigger on.** Sales before that are not synced.
- **One event type.** Only `attendee.created` is handled. A cancelled or refunded ticket does not change the contact.
- **The contact is the ticket holder**, not the buyer, when the two differ.
