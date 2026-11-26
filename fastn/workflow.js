const TEMPLATE_ID = "REPLACE_WITH_YOUR_CONFIG_ID";
const SOURCE = { connector: "hiEvents", entity: "attendee" };
const TARGET = { connector: "hubspot", entity: "contact" };
const HANDLED_EVENT = "attendee.created";

const isEmpty = (v) => v === undefined || v === null || (typeof v === "string" && v.trim() === "");

function getPath(obj, path) {
  return String(path).split(".").reduce((o, k) => (o === undefined || o === null ? undefined : o[k]), obj);
}

function setPath(obj, path, value) {
  const keys = String(path).split(".");
  let o = obj;
  for (let i = 0; i < keys.length - 1; i++) {
    if (typeof o[keys[i]] !== "object" || o[keys[i]] === null) o[keys[i]] = {};
    o = o[keys[i]];
  }
  o[keys[keys.length - 1]] = value;
}

// A connector failure carries its HTTP status as statusCode, or only inside the message.
function statusOf(e) {
  if (typeof e?.statusCode === "number") return e.statusCode;
  const m = /"statusCode":\s*(\d{3})|HTTP (\d{3})/.exec(String(e?.message ?? ""));
  return m ? Number(m[1] || m[2]) : null;
}

const messageOf = (e) => String(e?.message ?? e).slice(0, 500);

function conditionPasses(record, c) {
  const actual = getPath(record, c.field);
  const expected = c.value;
  const list = String(expected ?? "").split(",").map((s) => s.trim()).filter((s) => s !== "");
  switch (c.operator ?? c.op) {
    case "equals": return String(actual ?? "") === String(expected ?? "");
    case "not_equals": return String(actual ?? "") !== String(expected ?? "");
    case "in": return list.includes(String(actual ?? ""));
    case "not_in": return !list.includes(String(actual ?? ""));
    case "contains": return String(actual ?? "").includes(String(expected ?? ""));
    case "not_contains": return !String(actual ?? "").includes(String(expected ?? ""));
    case "greater_than": return Number(actual) > Number(expected);
    case "less_than": return Number(actual) < Number(expected);
    case "is_empty": return isEmpty(actual);
    case "is_not_empty": return !isEmpty(actual);
    case "matches":
      try { return new RegExp(String(expected)).test(String(actual ?? "")); } catch (_) { return false; }
    default: return false;
  }
}

function mappedValue(record, m) {
  const c = m.condition;
  const source = m.sourceField;
  if (typeof source === "string" && source.startsWith("__fixed:")) return source.slice("__fixed:".length);
  if (c?.type === "combine") {
    return (c.fields || []).map((f) => getPath(record, f.field)).filter((v) => !isEmpty(v)).join(c.separator ?? " ");
  }
  if (c?.type === "conditional") {
    return conditionPasses(record, c.when || {}) ? getPath(record, c.thenField) : getPath(record, c.elseField);
  }
  if (c?.type === "fallback") {
    const v = getPath(record, source);
    return isEmpty(v) ? getPath(record, c.fallbackField) : v;
  }
  if (c?.type === "split") {
    const v = getPath(record, source);
    return isEmpty(v) ? v : String(v).split(c.separator ?? " ")[c.index ?? 0];
  }
  if (m.valueMappings?.length && isEmpty(source)) return m.valueMappings[0].targetValue;
  const v = getPath(record, source);
  if (m.valueMappings?.length) {
    const hit = m.valueMappings.find((vm) => String(vm.sourceValue) === String(v));
    return hit ? hit.targetValue : v;
  }
  return v;
}

// Empty values are left out so a blank source field never overwrites data already in the target.
function buildTarget(record, mappings) {
  const target = {};
  for (const m of mappings || []) {
    const v = mappedValue(record, m);
    if (!isEmpty(v)) setPath(target, m.targetField, typeof v === "string" ? v.trim() : v);
  }
  return target;
}

// The customer's own copy of the configuration; the template's defaults when they have not saved one.
async function loadConfig() {
  let config = null;
  let source = "customer";
  try {
    config = await fastn.config.getByTemplate(TEMPLATE_ID);
  } catch (e) {
    if (!/no config for template/i.test(messageOf(e))) throw e;
  }
  if (!config || !(config.entities ?? config.directions ?? []).length) {
    config = await fastn.config.get(TEMPLATE_ID);
    source = "template";
  }
  return { config, source };
}

export default async function (ctx) {
  const result = { created: 0, updated: 0, skipped: 0, errors: 0, errorDetails: [] };
  const input = ctx.input || {};
  const payload = input.payload || {};
  const attendeeId = payload.id ?? null;
  result.attendeeId = attendeeId;

  const skip = (reason) => {
    result.skipped = 1;
    result.reason = reason;
    fastn.loki.warn("attendee skipped", { attendeeId, reason });
    return result;
  };
  const fail = (step, e) => {
    result.errors = 1;
    result.errorDetails.push({ attendeeId, step, status: statusOf(e), errorMessage: typeof e === "string" ? e : messageOf(e) });
    fastn.loki.error("attendee failed", { attendeeId, step, status: statusOf(e) });
    return result;
  };

  if (input.event_type !== HANDLED_EVENT) return skip("unsupported event");
  if (isEmpty(payload.id)) return fail("validate", "invalid payload: missing payload.id");
  if (isEmpty(payload.event_id)) return fail("validate", "invalid payload: missing payload.event_id");

  let flow;
  let matching;
  try {
    const loaded = await loadConfig();
    const flows = loaded.config.entities ?? loaded.config.directions ?? [];
    flow = flows.find((d) =>
      (d.source?.entity ?? d.sourceEntity) === SOURCE.entity && (d.target?.entity ?? d.targetEntity) === TARGET.entity);
    matching = loaded.config.matching ?? loaded.config.matchingStrategy ?? {};
    result.configSource = loaded.source;
    if (!flow) return fail("config", "configuration has no attendee to contact mapping");
  } catch (e) {
    return fail("config", e);
  }

  let attendee;
  try {
    const res = await fastn.connector.hiEvents.getAttendee({ eventId: payload.event_id, attendeeId: payload.id });
    attendee = res.output?.data;
  } catch (e) {
    if (statusOf(e) === 404) return skip("attendee not found");
    return fail("hiEvents.getAttendee", e);
  }
  if (!attendee) return skip("attendee not found");

  let order = null;
  if (!isEmpty(attendee.order_id)) {
    try {
      const res = await fastn.connector.hiEvents.getOrder({ eventId: payload.event_id, orderId: attendee.order_id });
      order = res.output?.data ?? null;
    } catch (e) {
      if (statusOf(e) !== 404) return fail("hiEvents.getOrder", e);
    }
  }
  const record = { ...attendee, order };

  for (const c of flow.conditions || []) {
    if (!conditionPasses(record, c)) {
      return skip(`condition not met: ${c.field} ${c.operator ?? c.op} ${c.value ?? ""}`.trim());
    }
  }

  const target = buildTarget(record, flow.mappings);
  const targetKey = matching.targetKey || "properties.email";
  const keyProperty = targetKey.replace(/^properties\./, "");
  let keyValue = getPath(target, targetKey);
  if (isEmpty(keyValue)) return skip(`missing ${keyProperty}`);
  if (keyProperty === "email") {
    keyValue = String(keyValue).trim().toLowerCase();
    setPath(target, targetKey, keyValue);
  }
  const properties = target.properties || {};

  let contactId = null;
  try {
    const found = await fastn.connector.hubspot.searchContacts({
      filterGroups: [{ filters: [{ propertyName: keyProperty, operator: "EQ", value: keyValue }] }],
      properties: [keyProperty],
      limit: 2,
    });
    const first = (found.output?.results || [])[0];
    if (first?.id) contactId = String(first.id);
  } catch (e) {
    return fail("hubspot.searchContacts", e);
  }

  if (!contactId) {
    try {
      const made = await fastn.connector.hubspot.createContact({ properties });
      result.created = 1;
      result.contactId = String(made.output?.id ?? "");
      fastn.loki.info("contact created", { attendeeId, contactId: result.contactId });
      return result;
    } catch (e) {
      if (statusOf(e) !== 409) return fail("hubspot.createContact", e);
      // Created a moment ago by another run: look it up by the key and update it instead.
      try {
        const existing = await fastn.connector.hubspot.getContact({ contactId: keyValue, idProperty: keyProperty });
        if (existing.output?.id) contactId = String(existing.output.id);
      } catch (lookupError) {
        return fail("hubspot.getContact", lookupError);
      }
      if (!contactId) return fail("hubspot.createContact", e);
    }
  }

  try {
    await fastn.connector.hubspot.updateContact({ contactId, properties });
    result.updated = 1;
    result.contactId = contactId;
    fastn.loki.info("contact updated", { attendeeId, contactId });
    return result;
  } catch (e) {
    return fail("hubspot.updateContact", e);
  }
}
