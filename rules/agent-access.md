# What the agent may read and propose in Odoo

Odoo's own access rules decide what the signed-in user, Riku Lindgren, may do. These limits are narrower and apply to the agent, which reads and proposes as him. The gateway checks every read and every proposal against the tables below in code. A denial under the purchasing policy names the section, whatever words the agent used for the change.

Every table of models and fields here has the same shape. The first column is one model, and the second is a comma-separated list of its fields. A third column, where there is one, holds what applies to every field in that row. A model may have more than one row.

## Reads

The agent reads Odoo with `odoo_search_read`, and only the models and fields below, so a read returns the same shape every time and nothing else leaves Odoo. `id` and `display_name` are readable on every model. A read returns at most 200 records, and 80 when the agent sets no limit.

A domain is a list of terms. A term is `[field, operator, value]` or one of the prefix operators `&`, `|` and `!`. Each part of a dotted field path has to be a readable field, and each part before the last has to lead to a readable model, as in `[["order_id.name", "=", "P00101"]]`.

A term may use these operators:

- `=`, `!=`
- `>`, `>=`, `<`, `<=`
- `in`, `not in`
- `like`, `ilike`, `=like`, `=ilike`

Every other operator is refused. That includes `any`, because its nested domain would read through fields outside the list.

A refused read is an input error that names the field or the operator, and the gateway makes no call to Odoo for it. Records of a model the agent may write come back with `proposals`, the pending and applied proposals on that record.

| Model | Fields |
|---|---|
| `purchase.order` | `name`, `partner_id`, `partner_ref`, `state`, `date_order`, `date_planned`, `picking_type_id`, `order_line`, `user_id`, `origin`, `currency_id`, `amount_untaxed`, `invoice_ids`, `picking_ids`, `receipt_status` |
| `purchase.order.line` | `order_id`, `partner_id`, `sequence`, `name`, `product_id`, `product_qty`, `uom_id`, `price_unit`, `currency_id`, `price_subtotal`, `state`, `date_planned`, `date_promised`, `x_confirmed_qty`, `x_confirmed_date`, `qty_received`, `qty_invoiced`, `move_ids`, `move_dest_ids`, `invoice_lines`, `display_type` |
| `stock.picking` | `name`, `state`, `picking_type_id`, `picking_type_code`, `partner_id`, `origin`, `purchase_id`, `scheduled_date`, `date_done`, `backorder_id`, `backorder_ids`, `move_ids` |
| `stock.move` | `reference`, `product_id`, `product_uom_qty`, `quantity`, `picked`, `state`, `picking_id`, `purchase_line_id`, `created_purchase_line_ids`, `raw_material_production_id`, `move_orig_ids`, `move_dest_ids`, `procure_method`, `date`, `date_deadline`, `location_id`, `location_dest_id` |
| `mrp.production` | `name`, `state`, `product_id`, `product_qty`, `date_start`, `date_finished`, `move_raw_ids`, `components_availability`, `components_availability_state`, `origin`, `user_id`, `picking_type_id` |
| `account.move` | `name`, `ref`, `state`, `move_type`, `partner_id`, `invoice_date`, `invoice_date_due`, `invoice_origin`, `amount_untaxed`, `amount_total`, `amount_residual`, `currency_id`, `payment_state`, `invoice_line_ids`, `x_dispute`, `x_dispute_note` |
| `account.move.line` | `move_id`, `product_id`, `name`, `quantity`, `price_unit`, `price_subtotal`, `purchase_line_id`, `purchase_order_id`, `display_type` |
| `product.product` | `name`, `default_code`, `seller_ids`, `standard_price`, `is_storable`, `bom_ids` |
| `product.supplierinfo` | `partner_id`, `product_id`, `product_tmpl_id`, `product_code`, `product_name`, `delay`, `min_qty`, `price` |
| `res.partner` | `name`, `is_company`, `parent_id`, `child_ids`, `email`, `phone`, `city`, `country_id`, `ref` |
| `res.users` | `name`, `login`, `partner_id` |
| `stock.picking.type` | `name`, `code`, `warehouse_id` |
| `stock.warehouse` | `name`, `code`, `partner_id` |
| `mail.activity` | `res_model`, `res_id`, `res_name`, `user_id`, `summary`, `note`, `date_deadline`, `date_done`, `active`, `state` |
| `mail.message` | `model`, `res_id`, `body`, `date`, `author_id`, `message_type`, `subtype_id` |

## Writes a proposal may contain

A proposal writes one or more of these fields on one record. The Value column says what the fields in the row take. A date is written `YYYY-MM-DD`, a quantity is a number, and text is free text. A value in backticks is the only value the field takes.

| Model | Fields | Value |
|---|---|---|
| `purchase.order.line` | `date_planned`, `x_confirmed_date` | date |
| `purchase.order.line` | `x_confirmed_qty` | quantity |
| `account.move` | `x_dispute` | `disputed` |
| `account.move` | `x_dispute_note` | text |

`x_dispute` takes only the value `disputed`. A proposal that writes any other value is denied under POL-4, because only finance lifts a dispute.

Some fields are written together, as [approval-rules.md](approval-rules.md) describes. `x_confirmed_qty` and `x_confirmed_date` go in the same proposal, and so do `x_dispute` and `x_dispute_note`.

A field or model that is on neither this table nor the table of never-written fields is denied as outside what the agent may write.

## Fields an agent never writes

A proposal that writes one of these fields is denied under its policy section, with the section's sentence from the table after this one.

| Model | Fields | Policy |
|---|---|---|
| `purchase.order.line` | `product_qty`, `price_unit`, `product_id` | POL-2 |
| `purchase.order.line` | `date_promised`, `x_requested_date` | POL-1 |
| `purchase.order.line` | `state` | POL-6 |
| `purchase.order` | `state` | POL-6 |
| `purchase.order` | `order_line` | POL-2 |
| `stock.move` | `quantity`, `picked`, `product_uom_qty` | POL-3 |
| `stock.move.line` | `quantity`, `picked` | POL-3 |
| `mrp.production` | `date_start`, `date_finished` | POL-5 |

## The sentence a denial quotes

| Policy | Sentence |
|---|---|
| POL-1 | The requested date on a purchase order line is the date we asked for and never changes after the order is confirmed. |
| POL-2 | The ordered quantity and the price on a confirmed purchase order change only by a buyer, through an amendment the supplier has signed. |
| POL-3 | A receipt is validated for the quantity the warehouse counted and recorded, never for a quantity from a supplier document. |
| POL-4 | Only finance lifts a dispute. Nothing is sent to a supplier or a customer by an agent. |
| POL-5 | The production planner owns the start dates of manufacturing orders. |
| POL-6 | Cancelling a purchase order or a line, changing an ordered quantity or a price on a confirmed order, validating a receipt for a quantity nobody counted, lifting a dispute, and sending anything to a supplier or a customer are never done by an agent, whoever asks. |
