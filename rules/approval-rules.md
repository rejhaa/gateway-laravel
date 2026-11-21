# Approval rules

Who decides a change the agent proposes. The gateway decides this in code from facts it reads itself. The agent's reading of a document decides nothing here, apart from the one point named under "What code cannot check".

A proposal is one write of one or more fields on one record, citing one message and one quote from it. The agent sends it with `propose`. The gateway acts as Riku Lindgren, the supply chain lead, and every decision that is not made by a rule or by the policy is his.

## The three tiers

| Tier | Proposals in it | Reason shown | What happens |
|---|---|---|---|
| `rule`, applied by a rule | A write to a line of a confirmed purchase order that touches only `date_planned`, `x_confirmed_qty` and `x_confirmed_date`, passes the shared conditions and fits one of the two rules | The passing rule's reason | The gateway writes it to Odoo at once. |
| `human`, waits for Riku | Every dispute on a vendor bill, and any write of the three line fields that fails a condition | "You are asked because" followed by every failed condition, or the reason under "Vendor bill dispute" | It waits until Riku approves or rejects it on the approval page or on the card in the Claude chat. |
| `policy`, denied | A write to a never-written field, `x_dispute` to anything but `disputed`, and any field or model on neither list | The policy section and its sentence, or that the write is outside what the agent may write | The gateway stores it as denied and writes nothing. |

The lists of fields are in [agent-access.md](agent-access.md). The agent has no tool that approves, rejects or writes.

## The order of the checks

The gateway checks a proposal in this order, and the first check that ends it decides. An input error is outside the tiers. The gateway stores no proposal for it, and the agent gets a sentence that says what to correct.

1. The arguments are complete and well formed. A missing or malformed argument is an input error.
2. The cited message exists in the mailbox. A message that does not exist is an input error.
3. The proposal is not already there. An earlier proposal for the same change is returned instead of a new one, as "Proposing again" describes.
4. The model, every field and every value are allowed. A field on the never-written list is denied under its policy section. A field or model on neither list is denied as outside what the agent may write. `x_dispute` with any value other than `disputed` is denied under POL-4. A value of the wrong type, such as a date that is not `YYYY-MM-DD` or a quantity that is not a number, is an input error.
5. The record exists in Odoo. A record that does not exist is an input error.
6. The fields that belong together are written together. `x_confirmed_qty` without `x_confirmed_date`, or the reverse, is an input error. `x_dispute` without `x_dispute_note` is one too. A `date_planned` equal to the line's current planned date is dropped from the write, so the proposal is judged as one that leaves the planned date alone.
7. The quote is in the message. The gateway looks for it in the message body and in each attachment's text. A quote that is not found in a message whose documents all have text is an input error. A quote that is not found when an attachment has no text layer lets the proposal go on, marked as not checked.
8. The rules decide the tier. The gateway reads the facts and tries each rule that applies. The first rule with no failures applies the write. With no passing rule, the proposal waits, and its reasons are the failures of the rule that applies, or the reason for its kind of write when no rule applies.
9. A rule-tier proposal is written to Odoo now and ends applied or failed. A human-tier proposal waits for Riku.

A denied proposal is stored before its record is read, so the history shows what the agent tried.

## Proposing again

- A proposal with the same record, the same values and the same message returns the earlier proposal as it is, in any status except failed and stale.
- A pending proposal on the same record from the same message for the same set of fields is returned the same way, with a sentence that says its values differ and that Riku decides it first. The same holds while that proposal is being written to Odoo.
- A failed or stale proposal does not block a new one. The new proposal records the one it replaces.

## Conditions both rules share

Both rules apply POL-1 to a write on one `purchase.order.line`. Each failed condition produces its phrase. The phrases are joined with "; " after "You are asked because".

| Condition | Phrase when it fails |
|---|---|
| The rule tier is on (`GATEWAY_RULE_TIER`) | "the rule tier is switched off" (shown alone) |
| The order's `state` is `purchase` | "the order is not a confirmed purchase order" |
| Only the three fields are written | "the change is more than the planned date and the confirmed quantity and date" |
| The quote was found, names the line's product, and states each written value | "no quoted passage that names {product} states the {field} {value} unambiguously" |
| The document has a text layer | "the document has no text layer, so the quote was not checked" |
| The cited message names the order | "the message does not name the order {name} or the supplier's reference {partner_ref}" |
| No newer uncited message names the order | "a later message ({subjects}) also names the order" |
| No applied rule-tier proposal cites this message for this line | "the same message was already applied to the line by rule in proposal {id}" |
| A written `x_confirmed_qty` equals `product_qty` | "the confirmed quantity {q} is not the {n} ordered" |

How the gateway reads these conditions:

- When the rule tier is switched off, its phrase is the only reason shown.
- The gateway searches for the quote with case, spacing and line breaks ignored, and with every dash and quotation mark read as the plain one. A quote may cross a line break.
- The product's names are `default_code` and `name` on `product.product`, and `product_code` and `product_name` on the `product.supplierinfo` rows of the order's supplier. The quote names the product when it contains one of them. A name shorter than 3 characters is ignored.
- A quantity is stated when it equals one of the numbers in the quote. A comma or a point in a number is read both as a decimal mark and as a thousands mark, so `1,200` and `1.200,00` both state 1200.
- A date is stated unambiguously when it reads the same in every date format. ISO dates and dates with a month name and a year count as written. A date without a year does not count. An all-numeric date with a separator (`a/b/yyyy`, `a.b.yyyy`, `a-b-yyyy`) counts only when one of its first two parts is over 12 or both are equal. So `03/04/2026` does not count and `29.11.2026` does.
- A message names the order when the order's `name` or `partner_ref` appears in its subject, body or attachment text as a whole token, with no letter or digit directly before or after it. A name shorter than 4 characters is ignored.
- A newer uncited message is any message in the mailbox that is newer than the cited one and names the order.

## rule:confirmation-as-ordered

Applies when the write leaves `date_planned` alone.

| Condition | Phrase when it fails |
|---|---|
| Both `x_confirmed_qty` and `x_confirmed_date` are written | "a confirmation as ordered records both the confirmed quantity and the confirmed date" |
| The confirmed date equals the line's planned date | "the confirmed date {d} is not the planned date {p}" |

Waiting manufacturing orders do not stop this rule, because recording the confirmation moves no date.

## rule:planned-date-under-cap

Applies when the write changes `date_planned`.

| Condition | Phrase when it fails |
|---|---|
| The line has a requested date (`date_promised`) | "the line has no requested date to count the slip from" |
| The slip from the requested date to the new planned date is under 14 days | "the slip of {n} days from the requested date is at or over the 14-day cap" |
| No manufacturing order waits on the line | "{n} manufacturing orders wait on the line", or "1 manufacturing order waits on the line" |
| A written `x_confirmed_date` equals the new planned date | "the confirmed date is not the new planned date" |

A slip of exactly 14 days fails. The slip is the number of days from the line's requested date to the new planned date. A waiting manufacturing order is an `mrp.production` reached through the line's `move_dest_ids` and their `raw_material_production_id`.

## Vendor bill dispute

No rule applies. The reason is "a dispute on a vendor bill is decided by a person (POL-4)".

## Facts the gateway reads

The gateway reads these as Riku and never takes them from the agent:

- The line's `order_id`, `product_id`, `product_qty`, `date_planned`, `date_promised`, `x_confirmed_qty`, `x_confirmed_date` and `move_dest_ids`.
- The order's `name`, `partner_ref`, `partner_id` and `state`.
- The product's names, as listed under the shared conditions.
- The waiting manufacturing orders, through the `stock.move` records in `move_dest_ids` and their `raw_material_production_id`, with each `mrp.production`'s `name` and `date_start` and the move's `product_uom_qty` as its need.
- The cited message's date, and every message newer than it that names the order.

## When Riku decides

Riku approves or rejects a waiting proposal on the approval page or on the card in the Claude chat. A rejection carries his reason.

When Riku approves, the gateway reads the record and its facts from Odoo again before it writes. The proposal is stale and nothing is written when a proposed field no longer holds the value it had when proposed, when the order's name or state or the line's product changed, or when the waiting manufacturing orders or their start dates changed. When Odoo refuses the write, the proposal is failed with Odoo's answer. The agent can propose a stale or failed proposal again.

## What code cannot check

Code cannot tell whether a date in a document is a delivery to our warehouse or a departure from a port. That stays the agent's reading, and the screens show the quote it rests on with the change.

A value from a document without a text layer cannot be checked against a quote. It fails the shared conditions, so it goes to Riku marked for checking.

## How values are written

The gateway reads every record a proposal touches from Odoo as Riku. It composes the change Riku sees from Odoo's current values and the proposed ones. The agent's own words reach Riku only as the proposal's title and summary and the text of a dispute note. The quote comes from the mail, and the reasons are the gateway's phrases.

A date in a proposal is written `YYYY-MM-DD`. Odoo stores a line's planned date as a datetime in UTC. The gateway writes the proposed day at `10:00:00` UTC, which is the same calendar day in Helsinki. A datetime that Odoo returns is in UTC, and the gateway converts it from UTC to Europe/Helsinki before it takes the date.
