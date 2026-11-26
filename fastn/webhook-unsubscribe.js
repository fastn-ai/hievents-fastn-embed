export default async function (ctx) {
  const input = ctx.input || {};
  const parts = String(input.subscriptionId || "").split(":");
  const organizerId = Number(parts[0]);
  const webhookId = Number(parts[1]);
  if (!organizerId || !webhookId) {
    return { success: false, unregistered: false, error: "No Hi.Events webhook id was stored for this subscription." };
  }
  try {
    await fastn.connector.hiEvents.deleteOrganizerWebhook({ organizerId, webhookId });
  } catch (e) {
    const gone = /404/.test(String(e?.statusCode ?? e?.message ?? ""));
    if (!gone) return { success: false, unregistered: false, error: String(e?.message ?? e).slice(0, 300) };
  }
  return { success: true, unregistered: true };
}
