export default async function (ctx) {
  const input = ctx.input || {};
  // The customer is one Hi.Events organizer; its id is the trailing number of the customer reference.
  let organizerId = input.organizerId ? Number(input.organizerId) : null;
  const ref = String(ctx.externalRef || input.externalRef || "");
  const fromRef = /organizer-(\d+)$/.exec(ref);
  if (!organizerId && fromRef) organizerId = Number(fromRef[1]);
  if (!organizerId) {
    const list = await fastn.connector.hiEvents.listOrganizers({ per_page: 2 });
    const organizers = list?.output?.data || [];
    if (organizers.length === 1) organizerId = organizers[0].id;
  }
  if (!organizerId) {
    return { success: false, registered: false, error: "Could not tell which Hi.Events organizer to subscribe: the account has several organizers and no organizer reference was given." };
  }
  const res = await fastn.connector.hiEvents.createOrganizerWebhook({
    organizerId,
    url: input.webhookUrl,
    event_types: [input.event],
    status: "ENABLED",
  });
  const out = res?.output?.data ?? res?.output ?? res;
  if (!res || res.success === false || !out?.id) {
    return { success: false, registered: false, error: res?.error || "createOrganizerWebhook failed" };
  }
  return { success: true, registered: true, subscriptionId: `${organizerId}:${out.id}`, secret: out.secret };
}
