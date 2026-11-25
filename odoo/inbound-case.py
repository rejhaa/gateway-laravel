# Loads one inbound test case (CASE-FORMAT.md) on top of the base seed.
#
# Run in the same odoo shell as inbound-seed.py, piped in right after it, so Odoo starts once:
#   cat inbound-seed.py inbound-case.py | INBOUND_CASE_FILE=<case.json> \
#     odoo shell -d inbound --no-http     (seed.sh does this for bin/odoo seed <caseDir>)
#
# The shell execs both files as one script. The base seed has reset the database to the base
# records and committed, and this part reuses its helpers (out, check, at, day, everything,
# ensure_xmlid) and records (suppliers, warehouse, riku, bom, order_of, mos, b1_bill). It applies
# the case's setup, verifies every planted fact, commits and prints "SEED case-map <json>" with
# the Odoo ids of the case's records by the case's own references. A failed check raises before
# the commit, so the database keeps the base seed alone.
#
# The added suppliers and products carry an external id in the module inbound_case. Every other
# record a case creates hangs off a supplier or a product, so the reset in inbound-seed.py finds
# the whole case from those two sets.

import re
from datetime import timedelta

CASE_FILE = os.environ.get('INBOUND_CASE_FILE')
check(CASE_FILE, 'INBOUND_CASE_FILE names the case file')
with open(CASE_FILE, encoding='utf-8') as fh:
    case = json.load(fh)
case_id = case.get('id')
setup = case.get('setup') or {}
today = date.fromisoformat(case['today'])


def invalid(condition, message):
    """A case that does not follow CASE-FORMAT.md, as opposed to a load that went wrong."""
    if not condition:
        raise ValueError(f'case {case_id}: {message}')


def iso(value):
    return date.fromisoformat(value) if value else None


def numbered(name, prefix):
    """True for prefix + 00101 to 00999, the range CASE-FORMAT.md gives case records."""
    found = re.fullmatch(re.escape(prefix) + r'00(\d{3})', name)
    return bool(found) and 101 <= int(found.group(1)) <= 999


# ---------------------------------------------------------------------------
# 1. Read and validate the case
# ---------------------------------------------------------------------------

case_suppliers = setup.get('suppliers', [])
case_products = setup.get('products', [])
case_vendor_codes = setup.get('vendor_codes', [])
case_mo_rows = setup.get('manufacturing_orders', [])
case_po_rows = setup.get('purchase_orders', [])

partner_by_name = dict(suppliers)
lead_days = dict(supplier_delay)
for row in case_suppliers:
    invalid(row['name'] not in partner_by_name, f'supplier {row["name"]} exists twice')
    partner_by_name[row['name']] = None
    lead_days[row['name']] = int(row['lead_days'])
base_refs = {p.default_code: p for p in products.values()}
product_refs = set(base_refs)
for row in case_products:
    invalid(row['ref'] not in product_refs, f'product {row["ref"]} exists twice')
    invalid(row['supplier'] in partner_by_name, f'product {row["ref"]}: unknown supplier {row["supplier"]}')
    product_refs.add(row['ref'])
for row in case_vendor_codes:
    invalid(row['product'] in product_refs and row['product'] != SET_CODE, f'vendor code {row["code"]}: unknown product {row["product"]}')
    invalid(row['supplier'] in partner_by_name, f'vendor code {row["code"]}: unknown supplier {row["supplier"]}')
COMPONENT_REFS = {p.default_code for p in bom.bom_line_ids.product_id}

mo_names = [row['name'] for row in case_mo_rows]
for row in case_mo_rows:
    invalid(numbered(row['name'], 'LAH/MO/'), f'manufacturing order name {row["name"]} outside LAH/MO/00101 to LAH/MO/00999')
    invalid(int(row['sets']) > 0, f'{row["name"]} needs at least one set')
    iso(row['planned_start'])
invalid(len(set(mo_names)) == len(mo_names), 'manufacturing order names repeat')

# (MO name, component ref) -> the line key that feeds it, filled while reading the orders.
fed_by = {}
po_names = [row['name'] for row in case_po_rows]
invalid(len(set(po_names)) == len(po_names), 'purchase order names repeat')
bill_refs = []
for po in case_po_rows:
    name = po['name']
    invalid(numbered(name, 'P'), f'purchase order name {name} outside P00101 to P00999')
    invalid(po['supplier'] in partner_by_name, f'{name}: unknown supplier {po["supplier"]}')
    invalid(po['warehouse'] in warehouse, f'{name}: warehouse {po["warehouse"]} is not LAH or RNO')
    invalid(po['state'] in ('confirmed', 'rfq'), f'{name}: state {po["state"]} is not confirmed or rfq')
    iso(po['order_date'])
    keys = [line['key'] for line in po['lines']]
    invalid(keys and len(set(keys)) == len(keys), f'{name}: line keys missing or repeated')
    by_key = {line['key']: line for line in po['lines']}
    for line in po['lines']:
        ref = f'{name}/{line["key"]}'
        invalid(line['product'] in product_refs and line['product'] != SET_CODE, f'{ref}: unknown product {line["product"]}')
        invalid(line['qty'] > 0 and line['price_unit'] >= 0, f'{ref}: quantity and price')
        iso(line['requested_date'])
        iso(line['planned_date'])
        # The snapshot reports confirmed_qty as null exactly when x_confirmed_date is unset, so a
        # quantity without a date would not survive the round trip.
        invalid((line.get('confirmed_qty') is None) == (line.get('confirmed_date') is None),
                f'{ref}: confirmed_qty and confirmed_date are both set or both null')
        iso(line.get('confirmed_date'))
        received = line.get('received_qty', 0)
        invalid(0 <= received <= line['qty'], f'{ref}: received_qty {received} outside 0 to {line["qty"]}')
        invalid(po['state'] == 'confirmed' or not received, f'{ref}: an rfq line has received nothing')
        feeds = line.get('feeds') or []
        if feeds:
            invalid(line['product'] in COMPONENT_REFS, f'{ref}: only a component of the iron set feeds a manufacturing order')
            # Odoo refuses a receipt at Reno whose goods a Lahti manufacturing order waits on.
            invalid(po['warehouse'] == 'LAH', f'{ref}: a line that feeds a manufacturing order is received at LAH')
        need = 0
        for mo_name in feeds:
            invalid(mo_name in mo_names, f'{ref}: feeds unknown manufacturing order {mo_name}')
            invalid((mo_name, line['product']) not in fed_by,
                    f'{mo_name}: {line["product"]} fed by both {fed_by.get((mo_name, line["product"]))} and {ref}')
            fed_by[(mo_name, line['product'])] = ref
            need += PER_SET * next(int(m['sets']) for m in case_mo_rows if m['name'] == mo_name)
        invalid(line['qty'] >= need, f'{ref}: quantity {line["qty"]} below the {need} its manufacturing orders need')
    for entry in po.get('receipts') or []:
        line = by_key.get(entry['line'])
        invalid(line, f'{name}: receipt for unknown line {entry["line"]}')
        invalid(po['state'] == 'confirmed' and line.get('received_qty', 0) < line['qty'],
                f'{name}/{entry["line"]}: a counted receipt needs a confirmed line with something still to receive')
        invalid(entry['counted_qty'] >= 0, f'{name}/{entry["line"]}: counted quantity')
    counted_keys = [entry['line'] for entry in po.get('receipts') or []]
    invalid(len(set(counted_keys)) == len(counted_keys), f'{name}: a line counted twice')
    for bill in po.get('bills') or []:
        invalid(bill['state'] in ('draft', 'posted'), f'bill {bill["vendor_reference"]}: state {bill["state"]}')
        invalid(bill['lines'] and all(entry['line'] in by_key for entry in bill['lines']),
                f'bill {bill["vendor_reference"]}: lines missing or for unknown order lines')
        iso(bill['date'])
        bill_refs.append(bill['vendor_reference'])
invalid(len(set(bill_refs)) == len(bill_refs), 'bill vendor references repeat')
for mo_name in mo_names:
    for ref in sorted(COMPONENT_REFS):
        invalid((mo_name, ref) in fed_by, f'{mo_name}: no purchase order line feeds {ref}')

# ---------------------------------------------------------------------------
# 2. What must not change: the base records, the order numbering, the activities
# ---------------------------------------------------------------------------

base_orders = env['purchase.order'].browse([o.id for o in order_of.values()])
base_mos = env['mrp.production'].browse([m.id for m in mos.values()])


def base_fingerprint():
    """Every value of the base seed's orders, receipts, manufacturing orders and bill that a case
    load could disturb, with the activities and the chatter on them."""
    env.flush_all()
    env.invalidate_all()
    rows = []
    for order in base_orders.sorted('name'):
        rows.append((order.name, order.state, order.partner_ref, order.date_order, order.amount_total))
        for line in order.order_line:
            rows.append((line.id, line.product_qty, line.price_unit, line.date_planned, line.date_promised,
                         line.x_requested_date, line.x_confirmed_qty, line.x_confirmed_date, line.qty_received,
                         line.qty_invoiced, tuple(line.move_dest_ids.ids), tuple(line.move_ids.ids)))
        for picking in order.picking_ids.sorted('id'):
            rows.append((picking.name, picking.state, picking.scheduled_date, tuple(
                (m.id, m.state, m.product_uom_qty, m.quantity, m.picked, m.date, m.date_deadline, tuple(m.move_dest_ids.ids))
                for m in picking.move_ids)))
    for mo in base_mos.sorted('name'):
        rows.append((mo.name, mo.state, mo.date_start, mo.product_qty, tuple(
            (m.id, m.state, m.procure_method, m.product_uom_qty, m.date, m.date_deadline, tuple(m.move_orig_ids.ids))
            for m in mo.move_raw_ids)))
    rows.append((b1_bill.state, b1_bill.ref, b1_bill.invoice_date, b1_bill.amount_total, b1_bill.x_dispute))
    base_threads = [('purchase.order', base_orders.ids), ('stock.picking', base_orders.picking_ids.ids),
                    ('mrp.production', base_mos.ids), ('account.move', b1_bill.ids)]
    rows.append(tuple(env['mail.message'].search_count([('model', '=', model), ('res_id', 'in', ids)])
                      for model, ids in base_threads))
    rows.append(everything('mail.activity').search_count([]))
    return rows


fingerprint_before = base_fingerprint()
po_sequence = env['ir.sequence'].search([('code', '=', 'purchase.order'), ('company_id', 'in', (env.company.id, False))])
check(len(po_sequence) == 1, 'one purchase order sequence')
po_sequence_next = po_sequence.number_next_actual

# ---------------------------------------------------------------------------
# 3. Suppliers, products and vendor codes
# ---------------------------------------------------------------------------


def country_of(value):
    """A country by ISO code or by name, since the case gives only "country"."""
    countries = env['res.country'].search([('code', '=ilike', value)] if len(value) == 2 else [('name', '=ilike', value)])
    invalid(len(countries) == 1, f'country {value} not found')
    return countries


# Added suppliers are set up like the base ones: RFQ grouping "Always" and Riku as the buyer.
# They carry no tax ID, which the case does not give, so Odoo does not count them as companies.
for i, row in enumerate(case_suppliers, 1):
    partner = env['res.partner'].create({
        'name': row['name'],
        'city': row['city'],
        'country_id': country_of(row['country']).id,
        'email': row.get('email') or False,
        'group_rfq': 'all',
        'buyer_id': riku.id,
    })
    ensure_xmlid(partner, CASE_MODULE, f'supplier_{i}')
    partner_by_name[row['name']] = partner

product_by_ref = dict(base_refs)
for i, row in enumerate(case_products, 1):
    # Bought for stock: the Buy route alone, so nothing is made to order for them.
    tmpl = env['product.template'].create({
        'name': row['name'],
        'default_code': row['ref'],
        'type': 'consu',
        'is_storable': True,
        'uom_id': uom_unit.id,
        'purchase_method': 'purchase',
        'standard_price': row['cost'],
        'list_price': 0.0,
        'sale_ok': False,
        'purchase_ok': True,
        'taxes_id': [(5, 0, 0)],
        'supplier_taxes_id': [(5, 0, 0)],
        'route_ids': [(6, 0, [buy_route.id])],
        'seller_ids': [(0, 0, {
            'partner_id': partner_by_name[row['supplier']].id,
            'price': row['cost'],
            'currency_id': eur.id,
            'min_qty': 0,
            'delay': lead_days[row['supplier']],
        })],
    })
    ensure_xmlid(tmpl, CASE_MODULE, f'product_{i}')
    product_by_ref[row['ref']] = tmpl.product_variant_id

# A supplier's code fills the supplier's row on the product's vendor list when that row has no
# code yet, and otherwise becomes a row of its own, so a product keeps one row per code.
for row in case_vendor_codes:
    product = product_by_ref[row['product']]
    partner = partner_by_name[row['supplier']]
    seller = product.product_tmpl_id.seller_ids.filtered(lambda s: s.partner_id == partner and not s.product_code)[:1]
    if seller:
        seller.write({'product_code': row['code'], 'product_name': row['name']})
    else:
        env['product.supplierinfo'].create({
            'partner_id': partner.id,
            'product_tmpl_id': product.product_tmpl_id.id,
            'product_code': row['code'],
            'product_name': row['name'],
            'price': product.standard_price,
            'currency_id': eur.id,
            'min_qty': 0,
            'delay': lead_days[row['supplier']],
        })

# ---------------------------------------------------------------------------
# 4. Manufacturing orders
# ---------------------------------------------------------------------------

case_mos = {}
for row in case_mo_rows:
    case_mos[row['name']] = env['mrp.production'].create({
        'name': row['name'],
        'product_id': products[SET].id,
        'product_qty': row['sets'],
        'bom_id': bom.id,
        'picking_type_id': lahti.manu_type_id.id,
        'date_start': at(iso(row['planned_start'])),
        'user_id': riku.id,
    })
all_case_mos = env['mrp.production'].browse([m.id for m in case_mos.values()])

# Confirming an MO whose components are on Buy plus make-to-order runs a procurement per
# component, and Odoo answers with a request for quotation per supplier, linked to the raw moves
# through move_dest_ids. The case names its own feeding lines, so those requests go: first the
# links, because deleting a linked line cancels the raw move or turns it make-to-stock, then the
# orders, then the order number they took. No case order exists yet, so none can absorb them.
if all_case_mos:
    last_po_id = everything('purchase.order').search([], order='id desc', limit=1).id
    all_case_mos.action_confirm()
    stray = everything('purchase.order').search([('id', '>', last_po_id)])
    check(all(o.state == 'draft' for o in stray), 'Odoo made only requests for quotation on MO confirmation')
    stray.order_line.write({'move_dest_ids': [(5, 0, 0)]})
    stray.button_cancel()
    stray.unlink()
    po_sequence.number_next_actual = po_sequence_next
    for mo in all_case_mos:
        raw = mo.move_raw_ids
        check(mo.state == 'confirmed', f'{mo.name} confirmed')
        check(all(m.procure_method == 'make_to_order' and m.state == 'waiting' and not m.created_purchase_line_ids for m in raw),
              f'{mo.name} components make to order, waiting and unlinked after the requests were removed')

# ---------------------------------------------------------------------------
# 5. Purchase orders
# ---------------------------------------------------------------------------

case_orders = {}
case_lines = {}  # 'P00101/L1' -> purchase.order.line
case_rows = {}   # 'P00101/L1' -> (order row, line row)
for po in case_po_rows:
    order = env['purchase.order'].create({
        'name': po['name'],
        'partner_id': partner_by_name[po['supplier']].id,
        'partner_ref': po.get('vendor_reference') or False,
        'user_id': riku.id,
        'picking_type_id': warehouse[po['warehouse']].in_type_id.id,
        'currency_id': eur.id,
        'date_order': at(iso(po['order_date'])),
        'order_line': [(0, 0, {
            'sequence': 10 * i,
            'product_id': product_by_ref[line['product']].id,
            'product_qty': line['qty'],
            'price_unit': line['price_unit'],
            'tax_ids': [(5, 0, 0)],
            'date_planned': at(iso(line['requested_date'])),
            'x_requested_date': iso(line['requested_date']),
        }) for i, line in enumerate(po['lines'], 1)],
    })
    case_orders[po['name']] = order
    for line_row, line in zip(po['lines'], order.order_line.sorted('sequence')):
        ref = f'{po["name"]}/{line_row["key"]}'
        check(line.product_id == product_by_ref[line_row['product']], f'{ref} is the line for {line_row["product"]}')
        case_lines[ref] = line
        case_rows[ref] = (po, line_row)
        feeds = line_row.get('feeds') or []
        if feeds:
            # The link make-to-order procurement makes: the line's move_dest_ids are the raw moves
            # of its product, and propagate_cancel is off as on the base seed's procured lines.
            # The planned date is written again once the link exists, because Odoo then moves the
            # waiting raw moves' deadline to it, as it did for the base seed's requests.
            raw = all_case_mos.filtered(lambda m: m.name in feeds).move_raw_ids.filtered(lambda m: m.product_id == line.product_id)
            line.write({'move_dest_ids': [(6, 0, raw.ids)], 'propagate_cancel': False})
            line.write({'date_planned': at(iso(line_row['requested_date']))})

# Confirmation sets Odoo's date_promised to the planned date, which is the requested date at this
# point; a draft line has no promised date yet, so it is written. The planned date moves to the
# case's value afterwards, which also moves the receipt's scheduled date, as on the base seed.
to_confirm = env['purchase.order'].browse([case_orders[po['name']].id for po in case_po_rows if po['state'] == 'confirmed'])
to_confirm.button_confirm()
for po in case_po_rows:
    if po['state'] == 'confirmed':
        case_orders[po['name']].write({'date_approve': at(iso(po['order_date']))})
for ref, line in case_lines.items():
    po, row = case_rows[ref]
    vals = {}
    if po['state'] == 'rfq':
        vals['date_promised'] = at(iso(row['requested_date']))
    if row['planned_date'] != row['requested_date']:
        vals['date_planned'] = at(iso(row['planned_date']))
    if row.get('confirmed_qty') is not None:
        vals['x_confirmed_qty'] = row.get('confirmed_qty')
        vals['x_confirmed_date'] = iso(row.get('confirmed_date'))
    if vals:
        line.write(vals)


def open_moves(line):
    return line.move_ids.filtered(lambda m: m.state not in ('done', 'cancel') and m.picking_code == 'incoming')


def spread(moves, quantity):
    """Quantity over a line's open receipt moves, the ones feeding a manufacturing order first,
    with anything above the demand on the last move. Returns (move, quantity) pairs."""
    moves = moves.sorted(lambda m: (not m.move_dest_ids, m.id))
    pairs = []
    for i, move in enumerate(moves):
        take = quantity if i == len(moves) - 1 else min(quantity, move.product_uom_qty)
        pairs.append((move, take))
        quantity -= take
    return pairs


# ---------------------------------------------------------------------------
# 6. Earlier receipts, counted receipts and bills
# ---------------------------------------------------------------------------

# An earlier receipt validated for received_qty: one validation per order, with each line's
# received quantity picked and everything else unpicked at quantity 0. Odoo validates only picked
# moves and puts the rest of each line, and the untouched lines, on one backorder, which is then
# the open receipt. The done receipt is dated the day before the case's today, so it never lies
# in the story's future whatever the real clock says.
received_at = at(today - timedelta(days=1))
validated = {}  # order name -> the done receipt
for po in case_po_rows:
    if not any(row.get('received_qty', 0) for row in po['lines']):
        continue
    order = case_orders[po['name']]
    picking = order.picking_ids.filtered(lambda p: p.state not in ('done', 'cancel'))
    check(len(picking) == 1, f'{po["name"]} has one open receipt before the earlier receipt')
    picking.move_ids.write({'quantity': 0, 'picked': False})
    for row in po['lines']:
        if row.get('received_qty', 0):
            for move, take in spread(open_moves(case_lines[f'{po["name"]}/{row["key"]}']), row['received_qty']):
                move.write({'quantity': take, 'picked': bool(take)})
    picking.with_context(skip_backorder=True).button_validate()
    check(picking.state == 'done', f'{po["name"]} earlier receipt {picking.name} validated')
    picking.write({'date_done': received_at})
    picking.move_ids.filtered(lambda m: m.state == 'done').write({'date': received_at})
    picking.move_ids.move_line_ids.write({'date': received_at})
    validated[po['name']] = picking

# A count by the warehouse, not validated. Only a picked move is a count, so the line's open
# moves get the counted quantity and picked, and every other move stays as Odoo left it.
for po in case_po_rows:
    for entry in po.get('receipts') or []:
        line = case_lines[f'{po["name"]}/{entry["line"]}']
        for move, take in spread(open_moves(line), entry['counted_qty']):
            move.write({'quantity': take, 'picked': True})

# Vendor bills built as Odoo's "create bill" builds them, from _prepare_invoice and the order
# lines' _prepare_account_move_line, so each bill line carries purchase_line_id, with the case's
# quantities and prices in place of what is left to invoice.
case_bills = {}
for po in case_po_rows:
    order = case_orders[po['name']]
    for bill_row in po.get('bills') or []:
        vals = order._prepare_invoice()
        vals.update({
            'ref': bill_row['vendor_reference'],
            'invoice_date': iso(bill_row['date']),
            'x_dispute': 'none',
            'invoice_line_ids': [(0, 0, {
                **case_lines[f'{po["name"]}/{entry["line"]}']._prepare_account_move_line(),
                'quantity': entry['qty'],
                'price_unit': entry['price_unit'],
                'sequence': 10 * i,
            }) for i, entry in enumerate(bill_row['lines'], 1)],
        })
        bill = env['account.move'].with_context(default_move_type='in_invoice').create(vals)
        if bill_row['state'] == 'posted':
            bill.action_post()
        case_bills[bill_row['vendor_reference']] = bill

env.flush_all()
env.invalidate_all()

# ---------------------------------------------------------------------------
# 7. Verify and summarise
# ---------------------------------------------------------------------------

check(base_fingerprint() == fingerprint_before, 'the base seed records, their chatter and the activities are unchanged')
all_orders = everything('purchase.order').search([])
check(sorted(all_orders.mapped('name')) == sorted(base_orders.mapped('name') + po_names),
      f'no stray purchase orders, found {sorted(all_orders.mapped("name"))}')
check(po_sequence.number_next_actual == po_sequence_next, 'the purchase order numbering continues after the base orders')
check(sorted(env['mrp.production'].search([]).mapped('name')) == sorted(base_mos.mapped('name') + mo_names),
      'the manufacturing orders are the base ones and the case ones')

receipt_of = {}
out('case', case_id, 'today', today)
out('line          supplier    product         qty     price  requested   planned     promised    conf      conf_date   rcvd  open  counted  receipt         feeds')
for ref, line in case_lines.items():
    po, row = case_rows[ref]
    order = line.order_id
    received = row.get('received_qty', 0)
    check(order.name == po['name'] and order.state == ('purchase' if po['state'] == 'confirmed' else 'draft'),
          f'{ref} order {po["name"]} {po["state"]}')
    check(order.partner_id == partner_by_name[po['supplier']] and order.user_id == riku, f'{ref} supplier and buyer')
    check(order.partner_ref == (po.get('vendor_reference') or False), f'{ref} vendor reference')
    check(order.picking_type_id == warehouse[po['warehouse']].in_type_id, f'{ref} received at {po["warehouse"]}')
    check(order.date_order == at(iso(po['order_date'])), f'{ref} order date')
    check(line.product_qty == row['qty'] and line.price_unit == row['price_unit'] and not line.tax_ids,
          f'{ref} quantity {row["qty"]} at {row["price_unit"]}, no tax')
    check(line.date_planned == at(iso(row['planned_date'])), f'{ref} planned date {row["planned_date"]}, found {line.date_planned}')
    check(line.x_requested_date == iso(row['requested_date']) and line.date_promised == at(iso(row['requested_date'])),
          f'{ref} requested and promised date {row["requested_date"]}')
    if row.get('confirmed_qty') is None:
        check(not line.x_confirmed_qty and not line.x_confirmed_date, f'{ref} not confirmed by the supplier')
    else:
        check(line.x_confirmed_qty == row.get('confirmed_qty') and line.x_confirmed_date == iso(row.get('confirmed_date')),
              f'{ref} confirmed {row["confirmed_qty"]} on {row["confirmed_date"]}')
    check(line.qty_received == received, f'{ref} received {received}, found {line.qty_received}')

    feeds = row.get('feeds') or []
    expected_dest = all_case_mos.filtered(lambda m: m.name in feeds).move_raw_ids.filtered(lambda m: m.product_id == line.product_id)
    check(line.move_dest_ids == expected_dest, f'{ref} feeds {feeds}')

    moves = open_moves(line)
    counted = next((e['counted_qty'] for e in po.get('receipts') or [] if e['line'] == row['key']), None)
    if po['state'] == 'rfq':
        check(not line.move_ids, f'{ref} has no receipt')
        receipt = env['stock.picking']
    else:
        check(line.move_ids.move_dest_ids == line.move_dest_ids, f'{ref} receipt moves feed the same raw moves as the line')
        check(sum(moves.mapped('product_uom_qty')) == row['qty'] - received, f'{ref} {row["qty"] - received} still expected')
        receipt = moves.picking_id
        if received == row['qty']:
            check(not receipt, f'{ref} fully received, no open receipt')
        else:
            check(len(receipt) == 1 and receipt.picking_type_id == warehouse[po['warehouse']].in_type_id
                  and receipt.state == 'assigned', f'{ref} one ready open receipt at {po["warehouse"]}')
        if received:
            done = validated[po['name']].move_ids.filtered(lambda m: m.purchase_line_id == line)
            check(all(m.state == 'done' for m in done) and sum(done.mapped('quantity')) == received,
                  f'{ref} earlier receipt {validated[po["name"]].name} validated for {received}')
            check(receipt.backorder_id == validated[po['name']] or not receipt, f'{ref} open receipt is the backorder')
        if counted is None:
            check(not any(moves.mapped('picked')), f'{ref} receipt not counted')
        else:
            check(all(moves.mapped('picked')) and sum(moves.mapped('quantity')) == counted, f'{ref} counted {counted}')
    receipt_of[ref] = receipt
    out(f'{ref:<13} {po["supplier"].split()[0]:<11} {line.product_id.default_code:<15} {row["qty"]:>4g}  {row["price_unit"]:>8.2f}  '
        f'{line.x_requested_date}  {day(line.date_planned)}  {day(line.date_promised)}  '
        f'{(f"{line.x_confirmed_qty:g}" if line.x_confirmed_date else "-"):>8}  {str(line.x_confirmed_date or "-"):<10}  '
        f'{line.qty_received:>4g}  {sum(moves.mapped("product_uom_qty")):>4g}  {("-" if counted is None else f"{counted:g}"):>7}  '
        f'{(receipt.name or "-"):<14}  {", ".join(feeds) or "-"}')

out('mo             sets  start       state      components fed by')
for mo_row in case_mo_rows:
    mo = case_mos[mo_row['name']]
    sets = int(mo_row['sets'])
    check(mo.state == 'confirmed' and mo.product_id == products[SET] and mo.bom_id == bom, f'{mo.name} confirmed for the iron set')
    check(mo.product_qty == sets and mo.date_start == at(iso(mo_row['planned_start'])), f'{mo.name} {sets} sets starting {mo_row["planned_start"]}')
    check(mo.picking_type_id == lahti.manu_type_id, f'{mo.name} at Lahti')
    raw = mo.move_raw_ids
    check(len(raw) == 3 and set(raw.product_id.mapped('default_code')) == COMPONENT_REFS, f'{mo.name} three component moves')
    feeders = []
    for move in raw.sorted(lambda m: m.product_id.default_code):
        ref = fed_by[(mo.name, move.product_id.default_code)]
        check(move.product_uom_qty == sets * PER_SET and move.procure_method == 'make_to_order',
              f'{mo.name} needs {sets * PER_SET} {move.product_id.default_code} made to order')
        check(move.created_purchase_line_ids == case_lines[ref], f'{mo.name} {move.product_id.default_code} fed by {ref} alone')
        received = case_rows[ref][1].get('received_qty', 0)
        check(move.state == 'waiting' if not received else move.state in ('waiting', 'partially_available', 'assigned'),
              f'{mo.name} {move.product_id.default_code} waits on its line, found {move.state}')
        feeders.append(f'{move.product_id.default_code} {ref}')
    out(f'{mo.name:<14} {sets:>4}  {day(mo.date_start)}  {mo.state:<9}  {", ".join(feeders)}')

for po in case_po_rows:
    for bill_row in po.get('bills') or []:
        bill = case_bills[bill_row['vendor_reference']]
        check(bill.move_type == 'in_invoice' and bill.state == bill_row['state'], f'bill {bill.ref} {bill_row["state"]} vendor bill')
        check(bill.ref == bill_row['vendor_reference'] and bill.invoice_date == iso(bill_row['date']), f'bill {bill.ref} reference and date')
        check(bill.partner_id == case_orders[po['name']].partner_id and bill.currency_id == eur, f'bill {bill.ref} supplier in EUR')
        check(bill.x_dispute == 'none' and not bill.x_dispute_note, f'bill {bill.ref} not disputed')
        bill_lines = bill.invoice_line_ids.sorted('sequence')
        check([(l.purchase_line_id, l.quantity, l.price_unit) for l in bill_lines]
              == [(case_lines[f'{po["name"]}/{e["line"]}'], e['qty'], e['price_unit']) for e in bill_row['lines']],
              f'bill {bill.ref} lines from the order lines')
        total = round(sum(e['qty'] * e['price_unit'] for e in bill_row['lines']), 2)
        check(bill.amount_total == total and not bill.amount_tax, f'bill {bill.ref} {total:.2f} EUR, no tax')
        out(f'bill {bill.ref} id {bill.id} {bill.name or "(no number)"} {bill.state} on {po["name"]} date {bill.invoice_date} '
            f'{bill.amount_total:.2f} {bill.currency_id.name} dispute {bill.x_dispute}')

for row in case_suppliers:
    partner = partner_by_name[row['name']]
    out(f'supplier {partner.name}: {partner.city}, {partner.country_id.name}, lead time {lead_days[row["name"]]} d')
for ref in sorted({r['ref'] for r in case_products} | {r['product'] for r in case_vendor_codes}):
    sellers = product_by_ref[ref].product_tmpl_id.seller_ids
    if ref not in base_refs:
        tmpl = product_by_ref[ref].product_tmpl_id
        check(tmpl.is_storable and tmpl.route_ids == buy_route and tmpl.purchase_method == 'purchase', f'{ref} storable, bought')
    out(f'product {ref}: ' + ', '.join(
        f'{s.partner_id.name.split()[0]} {s.product_code or "-"} "{s.product_name or ""}" {s.price:.2f} {s.delay} d' for s in sellers))
for row in case_vendor_codes:
    check(product_by_ref[row['product']].product_tmpl_id.seller_ids.filtered(
        lambda s: s.partner_id == partner_by_name[row['supplier']] and s.product_code == row['code'] and s.product_name == row['name']),
        f'vendor code {row["code"]} on {row["product"]}')

case_map = {
    'case': case_id,
    'lines': {ref: line.id for ref, line in case_lines.items()},
    'receipts': {f'{ref}/receipt': (receipt.id or None) for ref, receipt in receipt_of.items()},
    'bills': {f'bill:{ref}': bill.id for ref, bill in case_bills.items()},
    'orders': {name: order.id for name, order in case_orders.items()},
    'productions': {name: mo.id for name, mo in case_mos.items()},
    'partners': {name: partner_by_name[name].id for name in sorted(
        {r['name'] for r in case_suppliers} | {po['supplier'] for po in case_po_rows}
        | {r['supplier'] for r in case_products} | {r['supplier'] for r in case_vendor_codes})},
    'products': {ref: product_by_ref[ref].id for ref in sorted(
        {r['ref'] for r in case_products} | {r['product'] for r in case_vendor_codes}
        | {line['product'] for po in case_po_rows for line in po['lines']} | ({SET_CODE} if case_mo_rows else set()))},
}
check(len(case_map['receipts']) == len(case_lines), 'one receipt entry per line')
print_counts('after case', count_snapshot())

env.cr.commit()
out('case committed')
out('case-map', json.dumps(case_map, sort_keys=True))
