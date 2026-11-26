# Hi.Events connector actions

The 22 actions of the `hiEvents` connector in fastn. Each one was run against a live Hi.Events and its response checked.
Auth is one method of type INPUT with two fields: `token` (a Hi.Events API key, sent as a bearer token) and `baseUrl`
(`https://api.hi.events` for the cloud, `<your app>/api` when self-hosted).

| Action | What it does |
|---|---|
| `createAttendee` | Manually adds an attendee to an event for a ticket product, creating a completed order for them. |
| `createEvent` | Creates a new event under an organizer. |
| `createOrganizer` | Creates a new organizer in the account. |
| `createOrganizerWebhook` | Registers a webhook on an organizer that receives the chosen event types for all of the organizer's events. |
| `createProduct` | Creates a product (a ticket or a general item) on an event. |
| `deleteOrganizerWebhook` | Deletes a webhook from an organizer; deliveries stop immediately. |
| `getAttendee` | Retrieves one attendee of an event by its ID, including the product they hold, their occurrence, check-ins and question answers. |
| `getEvent` | Retrieves one event by its ID, including its product categories with their products and prices, images, organizer and occurrences. |
| `getOrder` | Retrieves one order of an event by its ID, including its line items, attendees and checkout question answers. |
| `getOrganizer` | Retrieves one organizer by its ID, including its fee configuration. |
| `getProduct` | Retrieves one product (ticket or general item) of an event by its ID, including its prices, taxes and fees and add-ons. |
| `listAttendees` | Lists the attendees (ticket holders) of an event with their order and check-ins, one page at a time, newest first by default. |
| `listEvents` | Lists the events in the account across all organizers, one page at a time, newest first by default. |
| `listOrders` | Lists the orders placed for an event, with their line items and attendees, one page at a time, newest first by default. |
| `listOrganizerWebhooks` | Lists the webhooks registered on an organizer, with their subscribed event types, status and the result of the last delivery. |
| `listOrganizers` | Lists the organizers in the account the API key belongs to. |
| `listProducts` | Lists the products (tickets and general items) of an event with their prices, one page at a time, in homepage order by default. |
| `updateAttendee` | Updates an attendee's details and the ticket they hold. |
| `updateEvent` | Updates an event's details. |
| `updateOrder` | Updates the buyer details and internal notes of an order. |
| `updateOrganizer` | Updates an organizer's details. |
| `updateProduct` | Updates a product of an event. |
