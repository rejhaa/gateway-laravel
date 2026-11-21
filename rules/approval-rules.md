# Approval rules

Who approves an operation the agent proposes. The gateway decides this in code from facts it reads itself. The agent's reading of a document decides nothing here, apart from the one point named under "What code cannot check".

The agent submits a plan, which is a list of operations (`skills/inbound-exceptions/SKILL.md`, "Proposing"). Each operation gets a tier.

| Operation | Tier | Reason shown to the lead |
|---|---|---|
| Write of `date_planned`, `x_confirmed_qty` or `x_confirmed_date` on a purchase order line | `rule` when one of the two rules below passes, otherwise `human` | The rule's reason, or "A person decides because" followed by every condition that failed (POL-1) |
| Write of `x_dispute` and `x_dispute_note` on a vendor bill | `human` | A dispute on a vendor bill is decided by a person (POL-4) |
| `button_validate` on a receipt | `human` | A receipt validation creates stock and a valuation entry, so the supply chain lead approves it (POL-3) |
| `message_post`, an internal note | `human` | A note on a record goes with the decision a person makes on the plan |
| `activity_schedule`, a to-do for an owner | none | A to-do for its owner changes no record, so nobody approves it |

A plan whose writing operations all pass by rule is approved by the rule, and the agent commits it. Its approver is recorded as the rule's name, or as both names joined with `+` when its lines passed by different rules, such as `rule:confirmation-as-ordered+rule:planned-date-under-cap`. Every other plan with a writing operation waits for the supply chain lead. A plan that holds only to-dos is finished when it is stored, and its to-dos go into Odoo at once.

## Conditions both rules share

Both rules apply purchasing policy POL-1 to a write on one `purchase.order.line`. A write passes only when all of these hold:

- The rule tier is switched on for the current rollout phase.
- The order's `state` is `purchase`, a confirmed purchase order.
- The write touches no field other than `date_planned`, `x_confirmed_qty` and `x_confirmed_date`.
- Each written value is stated in a quoted passage that the gateway found in the document's text layer, and that same passage names the line's product. The product's names are its own reference and name and the supplier's `product_code` and `product_name` for it on `product.supplierinfo`. A quantity has to appear as that number. A date has to appear as a date that reads the same in every date format, so `03/04/2026` does not count.
- Every cited message names the order, by its `name` or by its `partner_ref`. A name shorter than 4 characters is ignored.
- No message in the mailbox that is newer than the newest cited message, and is not itself cited, names the order.
- No committed plan has already applied one of the cited messages to this line by rule.
- A written `x_confirmed_qty` equals the ordered `product_qty`.

## rule:confirmation-as-ordered

The write leaves `date_planned` alone. It passes when it writes both `x_confirmed_qty` and `x_confirmed_date`, and the confirmed date equals the line's current planned date.

Manufacturing orders waiting on the line do not stop this rule, because recording the confirmation moves no date.

## rule:planned-date-under-cap

The write changes `date_planned`. It passes when:

- The slip is under 14 days. The slip is the number of days from the line's requested date (`date_promised`) to the new planned date. A slip of exactly 14 days goes to a person, and so does a line without a requested date.
- No manufacturing order waits on the line. A waiting order is an `mrp.production` reached through the line's `move_dest_ids` and their `raw_material_production_id`.
- A written `x_confirmed_date` equals the new planned date.

## What code cannot check

Code cannot tell what a date in a document means, a delivery to our warehouse or a departure from a port. That stays the agent's reading, and the quote it rests on is shown with the change.

A value from a document without a text layer cannot be checked against a quote. It fails the shared conditions, so it goes to the lead marked for checking.

## How values are written

The gateway reads every record an operation touches from Odoo as the signed-in user and composes the effect the lead approves from Odoo's current values and the proposed ones. The agent's words reach the lead only as the plan's title and question, the reasons, and the text of a note or a to-do.

Odoo stores a line's planned date as a datetime. The gateway writes the proposed day at 10:00 UTC, which is the same calendar day in Helsinki.

## Owners of to-dos

A to-do is an Odoo activity, scheduled with `activity_schedule` and assigned to the user who has the owner's role.

| Owner | Odoo login |
|---|---|
| `supply_chain_lead` | `riku` |
| `buyer` | `mikko` |
| `purchasing_assistant` | `elina` |
| `finance` | `johanna` |
| `production_planner` | `tuomas` |
| `warehouse` | `pekka` |
