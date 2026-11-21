# What the agent may read and propose in Odoo

Odoo's own access rules decide what the signed-in user may do. These limits are narrower and apply to an agent acting as that user. A refusal under the purchasing policy names the section, whatever words the agent used for the change.

## Reads

The agent reads only the models and fields below, so a read returns the same shape every time and nothing else leaves Odoo. `id` and `display_name` are readable on every model. A read returns at most 200 records.

A domain is a list of `[field, operator, value]` terms joined by `&`, `|` or `!`. Each field path in it has to start with a readable field and follow relations to readable models, as in `[["order_id.name", "=", "P00101"]]`.

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

| Model | Field | Value |
|---|---|---|
| `purchase.order.line` | `date_planned` | a date, `YYYY-MM-DD` |
| `purchase.order.line` | `x_confirmed_qty` | a quantity |
| `purchase.order.line` | `x_confirmed_date` | a date, `YYYY-MM-DD` |
| `account.move` | `x_dispute` | `disputed` |
| `account.move` | `x_dispute_note` | text |

## Methods a proposal may call

| Model | Methods |
|---|---|
| `stock.picking` | `button_validate`, `message_post`, `activity_schedule` |
| `purchase.order` | `message_post`, `activity_schedule` |
| `mrp.production` | `message_post`, `activity_schedule` |
| `account.move` | `message_post`, `activity_schedule` |

## Fields an agent never writes

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

## Methods an agent never calls

| Methods | Policy |
|---|---|
| `button_cancel`, `action_cancel`, `unlink` | POL-6 |
| `action_rfq_send`, `action_send_and_print`, `message_post_with_source`, `action_post`, `button_draft`, `action_register_payment` | POL-4 |
| `button_confirm` | POL-2 |

Two allowed operations are refused on the state of the record:

- `button_validate` on a receipt where any move has `picked` false, because the warehouse has recorded no count for it (POL-3).
- A write of `x_dispute` on a bill that is already disputed, to any value other than `disputed`, because that lifts or settles the dispute (POL-4).

A field or method that is in neither the allowed list nor the denied list is refused as outside what the agent may write or call.

## The sentence a refusal quotes

| Policy | Sentence |
|---|---|
| POL-1 | The requested date on a purchase order line is the date we asked for and never changes after the order is confirmed. |
| POL-2 | The ordered quantity and the price on a confirmed purchase order change only by a buyer, through an amendment the supplier has signed. |
| POL-3 | A receipt is validated for the quantity the warehouse counted and recorded, never for a quantity from a supplier document. |
| POL-4 | Only finance lifts a dispute. Nothing is sent to a supplier or a customer by an agent. |
| POL-5 | The production planner owns the start dates of manufacturing orders. |
| POL-6 | Cancelling a purchase order or a line, changing an ordered quantity or a price on a confirmed order, validating a receipt for a quantity nobody counted, lifting a dispute, and sending anything to a supplier or a customer are never done by an agent, whoever asks. |
