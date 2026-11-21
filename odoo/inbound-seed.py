# Seed data for the inbound exceptions spike: Vuoksi Golf Oy on Odoo 20 Community.
#
# Run with bin/odoo seed, which pipes this file into an odoo shell inside the odoo container (seed.sh):
#   odoo shell -d inbound --no-http < inbound-seed.py
#
# The script is idempotent. It first deletes everything an earlier run or a case load
# (inbound-case.py) created, then makes sure the company, the warehouses, the custom fields
# and the assistant's access restriction exist, recreates the people, suppliers, products, stock, manufacturing
# orders, purchase orders, the counted receipt and the draft bill, writes
# data/inbound-users.json with an API key per gateway user and data/inbound-admin-key.txt with an
# admin key for the e2e, verifies the planted facts and commits. Any failed assertion
# raises, and the shell then exits without committing.
#
# Summary lines carry the prefix "SEED" so they can be filtered out of the shell log.

import json
import os
from datetime import date, datetime, time

from odoo.exceptions import AccessError

PREFIX = 'SEED'


def out(*parts):
    print(PREFIX, *parts, flush=True)


def check(condition, message):
    if not condition:
        raise AssertionError(f'seed check failed: {message}')


# ---------------------------------------------------------------------------
# Static data
# ---------------------------------------------------------------------------

COMPANY_NAME = 'Vuoksi Golf Oy'

USERS = [
    # login, name, job title, group xmlids, whether the user gets an API key for the gateway
    ('riku', 'Riku Lindgren', 'Supply chain lead', [
        'base.group_user',
        'purchase.group_purchase_manager',
        'stock.group_stock_user',
        'mrp.group_mrp_user',
        'account.group_account_invoice',
    ], True),
    # Inventory user is the lowest Inventory group and also reads manufacturing orders;
    # Purchase user reads and writes vendor bills. The assistant group adds the restriction.
    ('elina', 'Elina Koski', 'Purchasing assistant', [
        'base.group_user',
        'purchase.group_purchase_user',
        'stock.group_stock_user',
        'inbound_seed.group_purchase_assistant',
    ], True),
    # The owners of open items who never call the gateway. They work in Odoo, where an open
    # item is an activity on the purchase order assigned to them, so each needs to open a
    # purchase order (Purchase user) and the records of their own work.
    ('mikko', 'Mikko Saarinen', 'Buyer', [
        'base.group_user',
        'purchase.group_purchase_user',
    ], False),
    ('johanna', 'Johanna Rautio', 'Finance', [
        'base.group_user',
        'purchase.group_purchase_user',
        'account.group_account_invoice',
    ], False),
    ('tuomas', 'Tuomas Laine', 'Production planner', [
        'base.group_user',
        'purchase.group_purchase_user',
        'mrp.group_mrp_user',
    ], False),
    ('pekka', 'Pekka Hyvönen', 'Warehouse', [
        'base.group_user',
        'purchase.group_purchase_user',
        'stock.group_stock_user',
    ], False),
]
LOGINS = [login for login, *_rest in USERS]
GATEWAY_LOGINS = [login for login, *_rest, api_key in USERS if api_key]
OWNER_LOGINS = [login for login, *_rest, api_key in USERS if not api_key]

# Odoo 20 counts a partner as a company only when it has a tax ID, and it validates
# the format for both countries: the Taiwanese UBN check digit and the Vietnamese
# 10-digit tax code (stdnum checksum).
TAIYUAN = 'Tai-Yuan Forge & Shaft Co., Ltd.'
SAIGON = 'Saigon Grip Works Co., Ltd.'
SUPPLIERS = [
    # name, city, country xmlid, tax id, supplier lead time in days
    (TAIYUAN, 'Kaohsiung', 'base.tw', '84149738', 42),
    (SAIGON, 'Ho Chi Minh City', 'base.vn', '0315829464', 35),
]

HEAD = 'V1 forged iron head'
SHAFT = 'Steel iron shaft, stiff'
GRIP = 'Rubber grip, standard'
SET = 'Vuoksi V1 iron set 5-PW, steel stiff'
COMPONENTS = [
    # name, internal reference, cost (EUR), supplier
    (HEAD, 'VG-HEAD-V1', 38.00, TAIYUAN),
    (SHAFT, 'VG-SHAFT-STL-S', 12.50, TAIYUAN),
    (GRIP, 'VG-GRIP-STD', 4.50, SAIGON),
]
SET_CODE = 'VG-SET-V1-5PW-S'
PRODUCT_NAMES = [c[0] for c in COMPONENTS] + [SET]
PER_SET = 6

LAHTI = ('Lahti', 'LAH')
RENO = ('Reno', 'RNO')
RENO_ADDRESS = 'Vuoksi Golf Oy, Reno warehouse'

STOCK = [
    # warehouse code, product, quantity
    ('LAH', SET, 24),
    ('LAH', HEAD, 30),
    ('LAH', SHAFT, 45),
    ('LAH', GRIP, 300),
    ('RNO', SET, 60),
]

# The story's today is 2026-10-10. Every datetime is stored at 10:00 UTC, which is the
# same calendar day in UTC and in Europe/Helsinki.
AT = time(10, 0)


def at(d):
    return datetime.combine(d, AT)


MOS = [
    # key, sets, planned start
    ('MO-1', 10, date(2026, 11, 4)),
    ('MO-2', 10, date(2026, 11, 9)),
    ('MO-3', 10, date(2026, 11, 18)),
]

# The table of purchase order lines. One line per order.
# key, supplier, product, qty, requested date, planned date, confirmed qty, confirmed date,
# waiting MOs, receiving warehouse
LINES = [
    ('A1', TAIYUAN, SHAFT, 120, date(2026, 11, 2), date(2026, 11, 2), None, None, ('MO-1', 'MO-2'), 'LAH'),
    ('A2', TAIYUAN, HEAD, 120, date(2026, 11, 2), date(2026, 11, 2), None, None, ('MO-1', 'MO-2'), 'LAH'),
    ('A3', TAIYUAN, SHAFT, 60, date(2026, 10, 26), date(2026, 10, 28), 60, date(2026, 10, 28), (), 'LAH'),
    ('A4', TAIYUAN, HEAD, 40, date(2026, 11, 9), date(2026, 11, 9), None, None, (), 'LAH'),
    ('A5', TAIYUAN, SHAFT, 60, date(2026, 11, 16), date(2026, 11, 16), None, None, ('MO-3',), 'LAH'),
    ('A6', TAIYUAN, HEAD, 60, date(2026, 11, 16), date(2026, 11, 16), None, None, ('MO-3',), 'LAH'),
    ('B1', SAIGON, GRIP, 100, date(2026, 10, 5), date(2026, 10, 5), 100, date(2026, 10, 5), (), 'LAH'),
    ('B2', SAIGON, GRIP, 120, date(2026, 11, 2), date(2026, 11, 2), None, None, ('MO-1', 'MO-2'), 'LAH'),
    ('B3', SAIGON, GRIP, 60, date(2026, 11, 16), date(2026, 11, 16), None, None, ('MO-3',), 'LAH'),
    ('B4', SAIGON, GRIP, 200, date(2026, 11, 23), date(2026, 11, 23), None, None, (), 'RNO'),
]
LINE = {row[0]: row for row in LINES}

# Order dates, fixed so that nothing depends on the real clock.
ORDER_DATES = {
    'A1': date(2026, 9, 21), 'A2': date(2026, 9, 21), 'B2': date(2026, 9, 21),
    'A5': date(2026, 9, 28), 'A6': date(2026, 9, 28), 'B3': date(2026, 9, 28),
    'A3': date(2026, 9, 7), 'A4': date(2026, 9, 14), 'B1': date(2026, 8, 24), 'B4': date(2026, 9, 28),
}

B1_COUNTED = 96
B1_BILL_REF = 'SGW-2026-1187'
B1_BILL_DATE = date(2026, 10, 8)

CUSTOM_FIELDS = [
    # model, name, type, label, extra values
    ('purchase.order.line', 'x_requested_date', 'date', 'Requested date', {}),
    ('purchase.order.line', 'x_confirmed_qty', 'float', 'Confirmed quantity', {}),
    ('purchase.order.line', 'x_confirmed_date', 'date', 'Confirmed date', {}),
    ('account.move', 'x_dispute', 'selection', 'Dispute', {'selection_ids': [
        (0, 0, {'value': 'none', 'name': 'No dispute', 'sequence': 1}),
        (0, 0, {'value': 'disputed', 'name': 'Disputed', 'sequence': 2}),
        (0, 0, {'value': 'settled', 'name': 'Settled', 'sequence': 3}),
    ]}),
    ('account.move', 'x_dispute_note', 'text', 'Dispute note', {}),
]

OPEN_STATES = ['draft', 'sent', 'to approve']

USERS_JSON = os.path.join(os.getcwd(), 'data', 'inbound-users.json')
ADMIN_KEY_FILE = os.path.join(os.getcwd(), 'data', 'inbound-admin-key.txt')
ADMIN_KEY_NAME = 'inbound-e2e'
CASE_MODULE = 'inbound_case'

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------


def everything(model):
    return env[model].with_context(active_test=False)


def count_snapshot():
    models = [
        'res.users', 'res.users.apikeys', 'res.partner', 'product.template', 'product.product',
        'product.supplierinfo', 'mrp.bom', 'mrp.bom.line', 'mrp.production', 'purchase.order',
        'purchase.order.line', 'stock.picking', 'stock.move', 'stock.move.line', 'stock.quant',
        'stock.reference', 'account.move', 'account.move.line', 'mail.message', 'mail.activity',
        'mail.followers',
    ]
    return {m: everything(m).sudo().search_count([]) for m in models}


def print_counts(label, counts):
    out(f'counts {label}: ' + ', '.join(f'{m}={n}' for m, n in counts.items()))


def ensure_xmlid(record, module, name):
    """Give a record the external id module.name, so later runs and access domains find it."""
    data = env['ir.model.data'].search([('module', '=', module), ('name', '=', name)])
    if data:
        data.write({'model': record._name, 'res_id': record.id})
    else:
        env['ir.model.data'].create({'module': module, 'name': name, 'model': record._name,
                                     'res_id': record.id, 'noupdate': True})


def reset_sequence(sequence):
    check(len(sequence) == 1, f'sequence not found: {sequence}')
    sequence.number_next_actual = 1


# ---------------------------------------------------------------------------
# 1. Reset
# ---------------------------------------------------------------------------

before = count_snapshot()
print_counts('before reset', before)

# A case load (inbound-case.py) gives the suppliers and products it adds an external id in the
# module inbound_case. Everything else it creates (orders, receipts, bills, manufacturing
# orders, vendor codes, activities and chatter on them) hangs off a supplier or a product, so
# adding those two to the base sets below deletes the whole case with the base records.
case_data = env['ir.model.data'].sudo().search([('module', '=', CASE_MODULE)])
case_partners = everything('res.partner').browse(
    case_data.filtered(lambda d: d.model == 'res.partner').mapped('res_id')).exists()
case_templates = everything('product.template').browse(
    case_data.filtered(lambda d: d.model == 'product.template').mapped('res_id')).exists()
suppliers_old = everything('res.partner').search([('name', 'in', [s[0] for s in SUPPLIERS]), ('parent_id', '=', False)]) | case_partners
supplier_family = (everything('res.partner').search([('id', 'child_of', suppliers_old.ids)])
                   if suppliers_old else env['res.partner'])
seed_users = everything('res.users').search([('login', 'in', LOGINS)])
seed_templates = everything('product.template').search([('name', 'in', PRODUCT_NAMES)]) | case_templates
seed_products = seed_templates.product_variant_ids

# Vendor bills of the suppliers, newest first, one at a time.
for move in everything('account.move').search([('partner_id', 'in', supplier_family.ids)], order='id desc'):
    if move.state in ('posted', 'cancel'):
        move.button_draft()
    move.with_context(force_delete=True).unlink()

# Manufacturing orders of the iron set. None is ever produced; a cancelled one can go.
productions = everything('mrp.production').search([('product_id', 'in', seed_products.ids)])
check(not productions.filtered(lambda p: p.state == 'done'), 'a seed manufacturing order is done')
productions.filtered(lambda p: p.state != 'cancel').action_cancel()
productions.unlink()

# Every stock move of the seed products: receipts (done ones too, since a take may have
# validated one), inventory adjustments and anything left from the orders. Odoo refuses
# to delete a done move line, so the moves are set back to draft first; their move lines
# follow the move's state. The quants are deleted as the superuser.
moves = everything('stock.move').search([('product_id', 'in', seed_products.ids)])
pickings = moves.picking_id
moves.write({'state': 'draft'})
moves.move_line_ids.unlink()
moves.unlink()
pickings.exists().unlink()
everything('stock.quant').sudo().search([('product_id', 'in', seed_products.ids)]).unlink()

orders = everything('purchase.order').search([('partner_id', 'in', supplier_family.ids)])
orders.write({'state': 'cancel', 'locked': False})
orders.unlink()

# Stock references (the procurement links of manufacturing orders and receipts) that
# nothing points at any more.
everything('stock.reference').search([]).filtered(
    lambda r: not r.move_ids and not r.production_ids and not r.purchase_ids).unlink()

everything('mrp.bom').search([('product_tmpl_id', 'in', seed_templates.ids)]).unlink()
everything('product.supplierinfo').search([('product_tmpl_id', 'in', seed_templates.ids)]).unlink()
seed_templates.with_context(active_test=False).product_variant_ids.unlink()
seed_templates.exists().unlink()
for model in ('product.product', 'product.template'):
    env['mail.message'].sudo().search([
        ('model', '=', model), ('res_id', 'not in', everything(model).search([]).ids)]).unlink()

user_partners = seed_users.partner_id
everything('res.users.apikeys').sudo().search([('user_id', 'in', seed_users.ids)]).unlink()
seed_users.unlink()
(supplier_family | user_partners).exists().filtered(lambda p: not p.user_ids).unlink()
case_data.exists().unlink()

# With every order gone, restart the numbering so each run yields the same names.
if not everything('purchase.order').search_count([]):
    reset_sequence(env['ir.sequence'].search([('code', '=', 'purchase.order'),
                                              ('company_id', 'in', (env.company.id, False))]))
for picking_type in everything('stock.picking.type').search([('sequence_id', '!=', False)]):
    used = (everything('stock.picking').search_count([('picking_type_id', '=', picking_type.id)])
            + everything('mrp.production').search_count([('picking_type_id', '=', picking_type.id)]))
    if not used:
        reset_sequence(picking_type.sequence_id)

env.flush_all()
env.invalidate_all()
print_counts('after reset', count_snapshot())
check(not everything('res.users').search_count([('login', 'in', LOGINS)]), 'users left after reset')
check(not everything('res.partner').search_count([('name', 'in', [s[0] for s in SUPPLIERS])]), 'suppliers left after reset')
check(not everything('product.template').search_count([('name', 'in', PRODUCT_NAMES)]), 'products left after reset')
check(not env['purchase.order'].search_count([]), 'purchase orders left after reset')
check(not env['mrp.production'].search_count([]), 'manufacturing orders left after reset')
check(not env['stock.picking'].search_count([]), 'transfers left after reset')
check(not env['account.move'].search_count([('move_type', 'in', ('in_invoice', 'in_refund'))]), 'bills left after reset')
check(not case_partners.exists() and not case_templates.exists()
      and not env['ir.model.data'].sudo().search_count([('module', '=', CASE_MODULE)]), 'case records left after reset')

# ---------------------------------------------------------------------------
# 2. Company, warehouses, routes, custom fields and the assistant's restriction
# ---------------------------------------------------------------------------

company = env.ref('base.main_company')
eur = env.ref('base.EUR')
finland = env.ref('base.fi')
usa = env.ref('base.us')
uom_unit = env.ref('uom.product_uom_unit')

if not eur.active:
    eur.active = True
company_vals = {}
if company.name != COMPANY_NAME:
    company_vals['name'] = COMPANY_NAME
if company.country_id != finland:
    company_vals['country_id'] = finland.id
if company.currency_id != eur:
    company_vals['currency_id'] = eur.id
if company_vals:
    company.write(company_vals)
company_address = {'street': False, 'zip': False, 'city': 'Lahti', 'country_id': finland.id}
if any(company.partner_id[f] != (v if f != 'country_id' else finland) for f, v in company_address.items()):
    company.partner_id.write(company_address)
env['product.pricelist'].search([('company_id', 'in', (company.id, False))]).filtered(
    lambda pl: pl.currency_id != eur).write({'currency_id': eur.id})

lahti = env['stock.warehouse'].search([('company_id', '=', company.id)], order='id', limit=1)
if (lahti.name, lahti.code) != LAHTI:
    lahti.write({'name': LAHTI[0], 'code': LAHTI[1]})
if not (lahti.manufacture_to_resupply and lahti.buy_to_resupply):
    lahti.write({'manufacture_to_resupply': True, 'buy_to_resupply': True})

reno_address_vals = {
    'name': RENO_ADDRESS,
    'parent_id': company.partner_id.id,
    'type': 'delivery',
    'street': False,
    'zip': False,
    'city': 'Reno',
    'state_id': env.ref('base.state_us_23').id,
    'country_id': usa.id,
}
reno_address = env['res.partner'].search([('name', '=', RENO_ADDRESS)], limit=1)
if not reno_address:
    reno_address = env['res.partner'].create(reno_address_vals)
elif reno_address.street or reno_address.zip:
    reno_address.write(reno_address_vals)
reno = everything('stock.warehouse').search([('code', '=', RENO[1])], limit=1)
if not reno:
    reno = env['stock.warehouse'].create({
        'name': RENO[0], 'code': RENO[1], 'partner_id': reno_address.id, 'company_id': company.id})
if not reno.active:
    reno.active = True
if reno.manufacture_to_resupply or not reno.buy_to_resupply:
    reno.write({'manufacture_to_resupply': False, 'buy_to_resupply': True})
warehouse = {'LAH': lahti, 'RNO': reno}
# Renaming the code renames the Inventory sequences but not the three Manufacturing
# ones, which keep "WH/" and the old company name. Apply Odoo's own sequence values to all.
for wh in (lahti, reno):
    for type_field, sequence_vals in wh._get_sequence_values().items():
        sequence = wh[type_field].sequence_id
        if sequence and (sequence.prefix, sequence.name) != (sequence_vals['prefix'], sequence_vals['name']):
            sequence.write(sequence_vals)

# The make-to-order route is archived on a fresh install.
mto_route = env.ref('stock.route_warehouse0_mto')
if not mto_route.active:
    mto_route.active = True
mto_route.rule_ids.filtered(lambda r: not r.active).write({'active': True})
buy_route = env.ref('purchase_stock.route_warehouse0_buy')
manufacture_route = env.ref('mrp.route_warehouse0_manufacture')
# Buy and Manufacture apply per warehouse on a fresh install and cannot be set on a
# product until they are selectable on products.
(buy_route | manufacture_route).filtered(lambda r: not r.product_selectable).write({'product_selectable': True})
check(all(r.active and r.product_selectable for r in (mto_route, buy_route, manufacture_route)),
      'MTO, Buy and Manufacture routes active and selectable on products')

for model_name, name, ttype, label, extra in CUSTOM_FIELDS:
    if name not in env[model_name]._fields:
        env['ir.model.fields'].create({
            'model_id': env['ir.model']._get(model_name).id,
            'name': name,
            'ttype': ttype,
            'field_description': label,
            'state': 'manual',
            **extra,
        })
for model_name, name, ttype, _label, _extra in CUSTOM_FIELDS:
    check(name in env[model_name]._fields and env[model_name]._fields[name].type == ttype,
          f'custom field {model_name}.{name}')
if env['ir.default']._get('account.move', 'x_dispute') != 'none':
    env['ir.default'].set('account.move', 'x_dispute', 'none')

# The custom fields on the screens a person opens: the requested and confirmed values as
# columns of the purchase order's lines, with the planned date shown, and the dispute on the
# vendor bill's form and in the bills list. Each view is an extension of Odoo's own, kept
# under an external id so a later run updates it in place.
SEED_VIEWS = [
    ('view_po_form_confirmed', 'purchase.purchase_order_form', 'purchase.order', """
<data>
  <xpath expr="//field[@name='order_line']/list/column[@name='product_and_description']" position="attributes">
    <attribute name="width">180px</attribute>
  </xpath>
  <xpath expr="//field[@name='order_line']/list/field[@name='date_planned']" position="attributes">
    <attribute name="optional">show</attribute>
    <attribute name="string">Planned</attribute>
    <attribute name="options">{'show_time': false}</attribute>
    <attribute name="width">100px</attribute>
  </xpath>
  <xpath expr="//field[@name='order_line']/list/field[@name='tax_ids']" position="attributes">
    <attribute name="optional">hide</attribute>
  </xpath>
  <xpath expr="//field[@name='order_line']/list/field[@name='product_qty']" position="after">
    <field name="x_confirmed_qty" string="Confirmed" optional="show" readonly="1" width="70px" invisible="not x_confirmed_qty"/>
    <field name="x_confirmed_date" string="Confirmed for" optional="show" readonly="1" width="100px"/>
    <field name="x_requested_date" string="Requested" optional="show" readonly="1" width="90px"/>
  </xpath>
</data>"""),
    ('view_bill_form_dispute', 'account.view_move_form', 'account.move', """
<data>
  <xpath expr="(//field[@name='partner_bank_id'])[1]" position="after">
    <field name="x_dispute" invisible="move_type not in ('in_invoice', 'in_refund', 'in_receipt')"
           decoration-danger="x_dispute == 'disputed'" widget="badge"/>
    <field name="x_dispute_note" invisible="x_dispute == 'none'"/>
  </xpath>
</data>"""),
    ('view_bill_list_dispute', 'account.view_in_invoice_bill_tree', 'account.move', """
<data>
  <xpath expr="//field[@name='status_in_payment']" position="after">
    <field name="x_dispute" string="Dispute" optional="show" widget="badge"
           decoration-danger="x_dispute == 'disputed'" invisible="x_dispute == 'none'"/>
  </xpath>
</data>"""),
]
for xmlid, parent, model_name, arch in SEED_VIEWS:
    vals = {'name': f'inbound_seed.{xmlid}', 'type': 'list' if 'tree' in parent else 'form',
            'model': model_name, 'inherit_id': env.ref(parent).id, 'mode': 'extension',
            'arch_db': arch.strip(), 'active': True}
    view = env.ref(f'inbound_seed.{xmlid}', raise_if_not_found=False)
    if view:
        view.write(vals)
    else:
        view = env['ir.ui.view'].create(vals)
        ensure_xmlid(view, 'inbound_seed', xmlid)
po_arch = env['purchase.order'].get_views([(env.ref('purchase.purchase_order_form').id, 'form')])['views']['form']['arch']
check(all(f'name="{f}"' in po_arch for f in ('x_confirmed_qty', 'x_confirmed_date', 'x_requested_date')),
      'the purchase order form shows the confirmed and requested columns')
bill_form = env['account.move'].get_views([(env.ref('account.view_move_form').id, 'form')])['views']['form']['arch']
bill_list = env['account.move'].get_views([(env.ref('account.view_in_invoice_bill_tree').id, 'list')])['views']['list']['arch']
check('name="x_dispute"' in bill_form and 'name="x_dispute_note"' in bill_form and 'name="x_dispute"' in bill_list,
      'the vendor bill form and list show the dispute')

# The purchasing assistant may not change a confirmed order. In Odoo 20 there is no
# ir.rule: access is ir.access, where rows with a group are permissions OR'd across a
# user's groups and rows without a group are restrictions AND'd for everyone. Purchase
# user already grants every operation on every order line, so no group row can narrow
# that; the limit has to be a restriction, and its domain applies only to members of the
# assistant group.
assistant_group = env.ref('inbound_seed.group_purchase_assistant', raise_if_not_found=False)
if not assistant_group:
    assistant_group = env['res.groups'].create({
        'name': 'Purchasing assistant (no changes to confirmed orders)',
        'comment': 'May prepare requests for quotation but not change a confirmed purchase order line.',
    })
    ensure_xmlid(assistant_group, 'inbound_seed', 'group_purchase_assistant')
ASSISTANT_DOMAIN = (
    f"[('order_id.state', 'in', {OPEN_STATES!r})] "
    "if user.has_group('inbound_seed.group_purchase_assistant') else []"
)
assistant_access_vals = {
    'name': 'Purchasing assistant: no changes to confirmed purchase order lines',
    'model_id': env['ir.model']._get('purchase.order.line').id,
    'group_id': False,
    'operation': 'cud',
    'domain': ASSISTANT_DOMAIN,
    'active': True,
}
assistant_access = env.ref('inbound_seed.access_po_line_assistant', raise_if_not_found=False)
if not assistant_access:
    assistant_access = env['ir.access'].create(assistant_access_vals)
    ensure_xmlid(assistant_access, 'inbound_seed', 'access_po_line_assistant')
elif (assistant_access.domain, assistant_access.operation, assistant_access.group_id, assistant_access.active) \
        != (ASSISTANT_DOMAIN, 'cud', env['res.groups'], True):
    assistant_access.write(assistant_access_vals)

# ---------------------------------------------------------------------------
# 3. People, suppliers, products and stock
# ---------------------------------------------------------------------------

users = {}
for login, name, job, group_xmlids, _api_key in USERS:
    groups = env['res.groups']
    for xmlid in group_xmlids:
        groups |= env.ref(xmlid)
    users[login] = env['res.users'].with_context(no_reset_password=True).create({
        'name': name,
        'login': login,
        'password': login,
        'email': f'{login}@vuoksigolf.example',
        'tz': 'Europe/Helsinki',
        'function': job,
        'group_ids': [(6, 0, groups.ids)],
    })
riku, elina = users['riku'], users['elina']

suppliers = {}
supplier_delay = {}
for name, city, country_xmlid, vat, delay in SUPPLIERS:
    # "Always" grouping: every replenishment need of the supplier lands on its open
    # request for quotation, so MO-1 and MO-2 share lines.
    suppliers[name] = env['res.partner'].create({
        'name': name,
        'city': city,
        'country_id': env.ref(country_xmlid).id,
        'vat': vat,
        'group_rfq': 'all',
        'buyer_id': riku.id,
    })
    supplier_delay[name] = delay
check(all(p.is_company for p in suppliers.values()), 'suppliers are companies')

products = {}
for name, code, cost, supplier in COMPONENTS:
    tmpl = env['product.template'].create({
        'name': name,
        'default_code': code,
        'type': 'consu',
        'is_storable': True,
        'uom_id': uom_unit.id,
        'purchase_method': 'purchase',
        'standard_price': cost,
        'list_price': 0.0,
        'sale_ok': False,
        'purchase_ok': True,
        'taxes_id': [(5, 0, 0)],
        'supplier_taxes_id': [(5, 0, 0)],
        'route_ids': [(6, 0, [buy_route.id, mto_route.id])],
        'seller_ids': [(0, 0, {
            'partner_id': suppliers[supplier].id,
            'price': cost,
            'currency_id': eur.id,
            'min_qty': 0,
            'delay': supplier_delay[supplier],
        })],
    })
    products[name] = tmpl.product_variant_id

set_tmpl = env['product.template'].create({
    'name': SET,
    'default_code': SET_CODE,
    'type': 'consu',
    'is_storable': True,
    'uom_id': uom_unit.id,
    'list_price': 0.0,
    'sale_ok': True,
    'purchase_ok': False,
    'taxes_id': [(5, 0, 0)],
    'supplier_taxes_id': [(5, 0, 0)],
    'route_ids': [(6, 0, [manufacture_route.id])],
})
products[SET] = set_tmpl.product_variant_id
bom = env['mrp.bom'].create({
    'product_tmpl_id': set_tmpl.id,
    'product_qty': 1,
    'type': 'normal',
    'bom_line_ids': [(0, 0, {'product_id': products[n].id, 'product_qty': PER_SET}) for n in (HEAD, SHAFT, GRIP)],
})

# Stock the way a user counts it: an inventory adjustment on each quant.
quants = env['stock.quant']
for wh_code, product_name, qty in STOCK:
    quants |= env['stock.quant'].with_context(inventory_mode=True).create({
        'product_id': products[product_name].id,
        'location_id': warehouse[wh_code].lot_stock_id.id,
        'inventory_quantity': qty,
    })
quants.action_apply_inventory()
env.flush_all()

# ---------------------------------------------------------------------------
# 4. Manufacturing orders and the purchase orders they create
# ---------------------------------------------------------------------------

mos = {}
for key, sets, start in MOS:
    mos[key] = env['mrp.production'].create({
        'product_id': products[SET].id,
        'product_qty': sets,
        'bom_id': bom.id,
        'picking_type_id': lahti.manu_type_id.id,
        'date_start': at(start),
        'user_id': riku.id,
    })

order_of = {}


def split_and_confirm(keys_by_product, mo_keys):
    """The per-supplier requests for quotation Odoo made for mo_keys: one order per line.

    keys_by_product maps (supplier, product) to the table key of the line.
    """
    created = env['purchase.order'].search([('state', '=', 'draft'), ('partner_id', 'in', [s.id for s in suppliers.values()])], order='id')
    check(created, f'no request for quotation from {mo_keys}')
    for order in created:
        # The line that comes first in the table keeps the order Odoo made.
        lines = order.order_line.sorted(lambda l: keys_by_product[(order.partner_id.name, l.product_id.name)])
        for i, line in enumerate(lines):
            key = keys_by_product[(order.partner_id.name, line.product_id.name)]
            if i == 0:
                order_of[key] = order
                continue
            dest_before = line.move_dest_ids
            new_order = env['purchase.order'].create({
                'partner_id': order.partner_id.id,
                'picking_type_id': order.picking_type_id.id,
                'currency_id': order.currency_id.id,
                'origin': order.origin,
                'user_id': order.user_id.id,
                'date_order': order.date_order,
            })
            line.order_id = new_order
            check(line.move_dest_ids == dest_before, f'{key} kept its make-to-order link when moved to its own order')
            order_of[key] = new_order


# MO-1 and MO-2 confirm together: their component needs land on one request for
# quotation per supplier, one line per component, 120 of each.
(mos['MO-1'] | mos['MO-2']).action_confirm()
split_and_confirm({(TAIYUAN, SHAFT): 'A1', (TAIYUAN, HEAD): 'A2', (SAIGON, GRIP): 'B2'}, 'MO-1, MO-2')


def set_dates_and_confirm(keys):
    for key in keys:
        _k, _s, _p, qty, requested, _planned, _cq, _cd, _w, wh_code = LINE[key]
        order = order_of[key]
        check(len(order.order_line) == 1, f'{key} is the only line of its order')
        check(order.picking_type_id == warehouse[wh_code].in_type_id, f'{key} received at {wh_code}')
        order.order_line.write({'date_planned': at(requested), 'x_requested_date': requested})
        order.date_order = at(ORDER_DATES[key])
    confirm = env['purchase.order'].browse([order_of[k].id for k in keys])
    confirm.button_confirm()
    for key in keys:
        order_of[key].write({'date_approve': at(ORDER_DATES[key])})
        check(order_of[key].state == 'purchase', f'{key} confirmed')


set_dates_and_confirm(['A1', 'A2', 'B2'])

# MO-3 after those orders are confirmed, so its needs make requests of their own.
mos['MO-3'].action_confirm()
split_and_confirm({(TAIYUAN, SHAFT): 'A5', (TAIYUAN, HEAD): 'A6', (SAIGON, GRIP): 'B3'}, 'MO-3')
set_dates_and_confirm(['A5', 'A6', 'B3'])

# Orders placed directly for stock.
for key in ('A3', 'A4', 'B1', 'B4'):
    _k, supplier, product_name, qty, requested, _planned, _cq, _cd, _w, wh_code = LINE[key]
    order_of[key] = env['purchase.order'].create({
        'partner_id': suppliers[supplier].id,
        'user_id': riku.id,
        'picking_type_id': warehouse[wh_code].in_type_id.id,
        'currency_id': eur.id,
        'order_line': [(0, 0, {
            'product_id': products[product_name].id,
            'product_qty': qty,
            'price_unit': products[product_name].standard_price,
            'tax_ids': [(5, 0, 0)],
        })],
    })
set_dates_and_confirm(['A3', 'A4', 'B1', 'B4'])

line_of = {key: order.order_line for key, order in order_of.items()}

# What the suppliers have confirmed so far. A3: the supplier confirmed 60 on 2026-10-28,
# two days after the requested date, and the planned date followed.
for key, row in LINE.items():
    _k, _s, _p, qty, requested, planned, confirmed_qty, confirmed_date, _w, _wh = row
    vals = {}
    if planned != requested:
        vals['date_planned'] = at(planned)
    if confirmed_qty is not None:
        vals['x_confirmed_qty'] = confirmed_qty
        vals['x_confirmed_date'] = confirmed_date
    if vals:
        line_of[key].write(vals)

# ---------------------------------------------------------------------------
# 5. B1: the counted receipt and the draft bill
# ---------------------------------------------------------------------------

b1_receipt = order_of['B1'].picking_ids
check(len(b1_receipt) == 1, 'B1 has one receipt')
b1_move = b1_receipt.move_ids
check(len(b1_move) == 1, 'B1 receipt has one move')
b1_move.write({'quantity': B1_COUNTED, 'picked': True})

order_of['B1'].action_create_invoice()
b1_bill = order_of['B1'].invoice_ids
check(len(b1_bill) == 1, 'B1 has one bill')
b1_bill.write({'ref': B1_BILL_REF, 'invoice_date': B1_BILL_DATE})

env.flush_all()
env.invalidate_all()

# ---------------------------------------------------------------------------
# 6. API keys, inbound-users.json and the admin key
# ---------------------------------------------------------------------------

users_out = {}
for login, name, _job, _groups, api_key in USERS:
    if not api_key:
        continue
    user = users[login]
    key = env['res.users.apikeys'].with_user(user).sudo()._generate('rpc', 'agent-gateway', None)
    users_out[login] = {'name': name, 'uid': user.id, 'api_key': key}
os.makedirs(os.path.dirname(USERS_JSON), exist_ok=True)
with open(USERS_JSON, 'w', encoding='utf-8') as fh:
    json.dump(users_out, fh, indent=2, ensure_ascii=False)
    fh.write('\n')
os.chmod(USERS_JSON, 0o600)
out(f'wrote {USERS_JSON}')

admin = env.ref('base.user_admin')
env['res.users.apikeys'].sudo().search([('user_id', '=', admin.id), ('name', '=', ADMIN_KEY_NAME)]).unlink()
admin_key = env['res.users.apikeys'].with_user(admin).sudo()._generate('rpc', ADMIN_KEY_NAME, None)
with open(ADMIN_KEY_FILE, 'w', encoding='utf-8') as fh:
    fh.write(admin_key + '\n')
os.chmod(ADMIN_KEY_FILE, 0o600)
out(f'wrote {ADMIN_KEY_FILE}')

# ---------------------------------------------------------------------------
# 7. Verify and summarise
# ---------------------------------------------------------------------------


def day(value):
    return value and value.date() if isinstance(value, datetime) else value


out('line  order   supplier    product  qty  requested   planned     promised    conf_qty  conf_date   rcvd  receipt         waiting MOs')
for key, row in LINE.items():
    _k, supplier, product_name, qty, requested, planned, confirmed_qty, confirmed_date, waiting, wh_code = row
    found = env['purchase.order.line'].search([('order_id', '=', order_of[key].id)])
    check(len(found) == 1 and found == line_of[key], f'{key} exists once')
    line = found
    check(line.order_id.state == 'purchase', f'{key} order confirmed')
    check(line.partner_id == suppliers[supplier] and line.product_id == products[product_name], f'{key} supplier and product')
    check(line.product_qty == qty, f'{key} quantity {qty}')
    check(line.price_unit == products[product_name].standard_price and not line.tax_ids, f'{key} price and no tax')
    check(line.date_planned == at(planned), f'{key} planned date {planned}')
    check(line.x_requested_date == requested, f'{key} requested date {requested}')
    check(line.date_promised == at(requested), f'{key} Odoo promised date is the date at confirmation')
    if confirmed_qty is None:
        check(not line.x_confirmed_qty and not line.x_confirmed_date, f'{key} not confirmed by the supplier')
    else:
        check(line.x_confirmed_qty == confirmed_qty and line.x_confirmed_date == confirmed_date,
              f'{key} confirmed {confirmed_qty} on {confirmed_date}')
    check(line.qty_received == 0, f'{key} nothing received')
    check(line.order_id.currency_id == eur, f'{key} in EUR')

    dest_mos = line.move_dest_ids.raw_material_production_id
    expected_mos = env['mrp.production'].browse([mos[m].id for m in waiting])
    check(dest_mos == expected_mos, f'{key} waiting MOs {waiting}, found {dest_mos.mapped("name")}')
    check(all(m.product_id == line.product_id for m in line.move_dest_ids), f'{key} links to raw moves of its product')

    receipt = line.move_ids.picking_id
    check(len(receipt) == 1 and receipt.picking_type_id == warehouse[wh_code].in_type_id, f'{key} one receipt at {wh_code}')
    if key == 'B1':
        check(receipt.state == 'assigned', 'B1 receipt ready, not validated')
    else:
        check(receipt.state in ('assigned', 'confirmed'), f'{key} receipt waiting, found {receipt.state}')
        # A ready receipt reserves its full demand as quantity; nothing is counted until
        # someone marks the move picked.
        check(not any(line.move_ids.mapped('picked')), f'{key} receipt not counted')
    check(line.move_ids.move_dest_ids == line.move_dest_ids, f'{key} receipt move feeds the same MO moves')
    out(f'{key:<5} {line.order_id.name:<7} {supplier.split()[0]:<11} {product_name.split()[-1][:7]:<8} {qty:>4}  '
        f'{requested}  {day(line.date_planned)}  {day(line.date_promised)}  {(f"{line.x_confirmed_qty:g}" if line.x_confirmed_qty else "-"):>8}  '
        f'{str(line.x_confirmed_date or "-"):<10}  {line.qty_received:>4g}  {receipt.name:<14}  {", ".join(dest_mos.mapped("name")) or "-"}')

out('mo    name           sets  start       state      components (state)')
for key, sets, start in MOS:
    mo = mos[key]
    check(mo.state == 'confirmed', f'{key} confirmed')
    check(mo.product_qty == sets and mo.date_start == at(start), f'{key} {sets} sets starting {start}')
    raw = mo.move_raw_ids
    check(len(raw) == 3 and set(raw.product_id.mapped('name')) == {HEAD, SHAFT, GRIP}, f'{key} three component moves')
    check(all(m.procure_method == 'make_to_order' for m in raw), f'{key} components are make to order')
    check(all(m.state == 'waiting' for m in raw), f'{key} components waiting, found {raw.mapped("state")}')
    check(all(m.product_uom_qty == sets * PER_SET for m in raw), f'{key} needs {sets * PER_SET} of each component')
    feeding = env['purchase.order.line'].search([('move_dest_ids', 'in', raw.ids)])
    out(f'{key:<5} {mo.name:<14} {sets:>4}  {day(mo.date_start)}  {mo.state:<9}  '
        + ', '.join(f'{m.product_id.default_code} {m.product_uom_qty:g} {m.state}' for m in raw.sorted('product_id'))
        + '  fed by ' + ', '.join(sorted(feeding.order_id.mapped('name'))))

check(b1_receipt.state == 'assigned', 'B1 receipt assigned')
check(b1_move.quantity == B1_COUNTED and b1_move.picked and b1_move.product_uom_qty == 100, 'B1 counted 96 of 100')
# Odoo keeps an open receipt's scheduled date and its line's planned date equal: writing
# either one writes the other. B1 keeps the table's planned date, so its receipt is
# scheduled on 2026-10-05 too.
check(day(b1_receipt.scheduled_date) == LINE['B1'][5], 'B1 receipt scheduled on the planned date')
check(day(line_of['B1'].date_planned) == date(2026, 10, 5), 'B1 planned date stays 2026-10-05')
check(b1_bill.move_type == 'in_invoice' and b1_bill.state == 'draft', 'B1 bill is a draft vendor bill')
check(b1_bill.amount_total == 450.0 and b1_bill.amount_tax == 0 and b1_bill.currency_id == eur, 'B1 bill 450.00 EUR')
check(b1_bill.ref == B1_BILL_REF and b1_bill.invoice_date == B1_BILL_DATE, 'B1 bill reference and date')
check(b1_bill.x_dispute == 'none' and not b1_bill.x_dispute_note, 'B1 bill not disputed')
check(b1_bill.invoice_line_ids.purchase_line_id == line_of['B1'] and b1_bill.invoice_line_ids.quantity == 100,
      'B1 bill line for 100 grips from the order line')
check(line_of['B1'].invoice_lines.move_id == b1_bill, 'B1 order line reaches the bill through invoice_lines')
out(f'B1 receipt {b1_receipt.name} {b1_receipt.state} scheduled {day(b1_receipt.scheduled_date)}, '
    f'counted {b1_move.quantity:g} of {b1_move.product_uom_qty:g}, picked {b1_move.picked}')
out(f'B1 bill id {b1_bill.id} {b1_bill.name or "(no number)"} {b1_bill.state} {b1_bill.move_type} ref {b1_bill.ref} '
    f'date {b1_bill.invoice_date} {b1_bill.amount_total:.2f} {b1_bill.currency_id.name} dispute {b1_bill.x_dispute}')

stock_lines = []
for wh_code, product_name, qty in STOCK:
    on_hand = products[product_name].with_context(location=warehouse[wh_code].lot_stock_id.id).qty_available
    check(on_hand == qty, f'{wh_code} holds {qty} {product_name}, found {on_hand}')
    stock_lines.append(f'{wh_code} {products[product_name].default_code} {on_hand:g}')
out('stock: ' + ', '.join(stock_lines))
out('warehouses: ' + ', '.join(f'{w.name} ({w.code})' for w in (lahti, reno)))

# Per-user visibility, evaluated with each user's own access rights.
seed_po_lines = env['purchase.order.line'].browse([line_of[k].id for k in LINE])
seed_receipts = env['stock.picking'].search([('picking_type_code', '=', 'incoming')])
seed_mos = env['mrp.production'].search([])
seed_bills = env['account.move'].search([('move_type', '=', 'in_invoice')])
expected_counts = (len(seed_po_lines), len(seed_receipts), len(seed_mos), len(seed_bills))
check(expected_counts == (10, 10, 3, 1), f'records in the database {expected_counts}')
out('user   po_lines  receipts  mos  bills  (expected)')
for login in GATEWAY_LOGINS:
    user = users[login]
    as_user = env(user=user)
    seen = (
        as_user['purchase.order.line'].search_count([]),
        as_user['stock.picking'].search_count([('picking_type_code', '=', 'incoming')]),
        as_user['mrp.production'].search_count([]),
        as_user['account.move'].search_count([('move_type', '=', 'in_invoice')]),
    )
    out(f'{login:<6} {seen[0]:>8}  {seen[1]:>8}  {seen[2]:>3}  {seen[3]:>5}  {expected_counts}')
    check(seen == expected_counts, f'{login} sees every line, receipt, MO and bill')
    as_user['purchase.order.line'].browse(seed_po_lines.ids).check_access('read')
    as_user['stock.picking'].browse(seed_receipts.ids).check_access('read')
    as_user['stock.move'].browse(seed_receipts.move_ids.ids).check_access('read')
    as_user['mrp.production'].browse(seed_mos.ids).check_access('read')
    as_user['stock.move'].browse(seed_mos.move_raw_ids.ids).check_access('read')
    as_user['account.move'].browse(seed_bills.ids).check_access('read')
    as_user['account.move.line'].browse(seed_bills.invoice_line_ids.ids).check_access('read')

# The assistant's write on a confirmed line fails with Odoo's AccessError; the lead's
# succeeds. Both run in a savepoint that is rolled back, so nothing changes.


class Undo(Exception):
    pass


probe = line_of['A4']
probe_date = at(date(2026, 11, 14))
try:
    with env.cr.savepoint():
        probe.with_user(elina).write({'date_planned': probe_date})
        env.flush_all()
    raise AssertionError('seed check failed: elina wrote a confirmed purchase order line')
except AccessError as error:
    elina_error = str(error).splitlines()[0]
env.invalidate_all()
try:
    with env.cr.savepoint():
        probe.with_user(elina).write({'x_confirmed_qty': 40})
        env.flush_all()
    raise AssertionError('seed check failed: elina wrote x_confirmed_qty on a confirmed line')
except AccessError:
    pass
env.invalidate_all()
check(not env['purchase.order.line'].with_user(elina).browse(seed_po_lines.ids).has_access('write'),
      'elina may write no confirmed line')
check(not elina.has_group('purchase.group_purchase_manager'), 'elina is not a purchase manager')
check(not riku.has_group('inbound_seed.group_purchase_assistant'), 'riku is not in the assistant group')
# The OR trap: the restriction is the only thing between Elina and the lines; check
# that her write domain is exactly the open-order states and Riku's is unrestricted.
elina_domain = env(user=elina)['purchase.order.line']._access_domain('write')
riku_domain = env(user=riku)['purchase.order.line']._access_domain('write')
check('order_id.state' in str(elina_domain), f'elina write domain {elina_domain}')
check('order_id.state' not in str(riku_domain), f'riku write domain {riku_domain}')
try:
    with env.cr.savepoint():
        probe.with_user(riku).write({'date_planned': probe_date})
        env.flush_all()
        check(probe.date_planned == probe_date, 'riku wrote the planned date')
        raise Undo()
except Undo:
    pass
env.invalidate_all()
check(probe.date_planned == at(LINE['A4'][5]), 'A4 planned date unchanged after the access checks')
out(f'access: elina write on confirmed {probe.order_id.name} refused ({elina_error}); riku write allowed')
out(f'access: elina write domain {elina_domain}; riku write domain {riku_domain}')

# The owners of open items: the groups they were given and nothing more, no API key, and
# the two things the gateway relies on. They open every seeded purchase order, and an
# activity that the lead schedules on a confirmed order for them, as the gateway's commit
# does through activity_schedule, is theirs to read. The activity is rolled back.
seed_orders = env['purchase.order'].browse([order_of[k].id for k in LINE])
out('owner    name             orders  activity  groups')
for login, name, _job, group_xmlids, _api_key in USERS:
    if login not in OWNER_LOGINS:
        continue
    user = users[login]
    expected_groups = env['res.groups'].browse([env.ref(x).id for x in group_xmlids])
    check(user.group_ids == expected_groups, f'{login} has exactly the groups {group_xmlids}')
    check(user.tz == 'Europe/Helsinki', f'{login} timezone Europe/Helsinki')
    check(not everything('res.users.apikeys').sudo().search_count([('user_id', '=', user.id)]), f'{login} has no API key')
    as_owner = env(user=user)
    seen_orders = as_owner['purchase.order'].search_count([('id', 'in', seed_orders.ids)])
    check(seen_orders == len(seed_orders) == 10, f'{login} sees the 10 seeded purchase orders, found {seen_orders}')
    as_owner['purchase.order'].browse(seed_orders.ids).check_access('read')
    try:
        with env.cr.savepoint():
            activity = order_of['A2'].with_user(riku).activity_schedule(
                'mail.mail_activity_data_todo', date_deadline=date(2026, 10, 17),
                summary='Seed check', note='Seed check', user_id=user.id)
            env.flush_all()
            check(activity.user_id == user, f'the activity for {login} is assigned to {login}')
            mine = as_owner['mail.activity'].search([('user_id', '=', user.id), ('res_model', '=', 'purchase.order')])
            check(activity in mine, f'{login} finds the activity assigned to them')
            check(as_owner['mail.activity'].browse(activity.ids).read(['res_name'])[0]['res_name'] == order_of['A2'].name,
                  f'{login} reads the activity on {order_of["A2"].name}')
            raise Undo()
    except Undo:
        pass
    env.invalidate_all()
    out(f'{login:<8} {name:<16} {seen_orders:>6}  readable  {", ".join(x.split(".")[-1] for x in group_xmlids)}')
check(not env['mail.activity'].search_count([('res_model', '=', 'purchase.order'), ('res_id', 'in', seed_orders.ids)]),
      'no activity left on the purchase orders after the owner checks')

# The figures the scorer needs, computed from the records and the dates on the supplier
# and forwarder documents that the fake inbox delivers.
DOCUMENT_DATES = {
    'A2': date(2026, 11, 16),  # order confirmation: 100 of 120 heads
    'A3': date(2026, 11, 1),   # forwarder delivery notice: arrival at the Lahti warehouse
    'A4': date(2026, 11, 14),  # order confirmation, nothing waiting
    'A5': date(2026, 11, 26),  # order confirmation, MO-3 waiting
}
figures = {}
for key, doc_date in DOCUMENT_DATES.items():
    line = line_of[key]
    slip_now = (line.date_planned.date() - line.x_requested_date).days
    slip_new = (doc_date - line.x_requested_date).days
    move = (doc_date - line.date_planned.date()).days
    gaps = [(m.name, (doc_date - m.date_start.date()).days)
            for m in line.move_dest_ids.raw_material_production_id.sorted('date_start')]
    figures[key] = (slip_now, slip_new, move, gaps)
    out(f'{key} {line.order_id.name}: document date {doc_date}, slip now {slip_now} d, slip after {slip_new} d, '
        f'planned date moves {move} d, waiting ' + (', '.join(f'{n} starts {g} d before' for n, g in gaps) or 'none'))
check(figures['A2'] == (0, 14, 14, [(mos['MO-1'].name, 12), (mos['MO-2'].name, 7)]), f'A2 figures {figures["A2"]}')
check(figures['A3'] == (2, 6, 4, []), f'A3 figures {figures["A3"]}')
check(figures['A4'] == (0, 5, 5, []), f'A4 figures {figures["A4"]}')
check(figures['A5'] == (0, 10, 10, [(mos['MO-3'].name, 8)]), f'A5 figures {figures["A5"]}')
b1_line = line_of['B1']
missing = b1_line.product_qty - b1_move.quantity
disputed = round(missing * b1_line.price_unit, 2)
undisputed = round(b1_bill.amount_untaxed - disputed, 2)
out(f'B1 {b1_line.order_id.name}: billed {b1_bill.invoice_line_ids.quantity:g} x {b1_line.price_unit:.2f} = {b1_bill.amount_untaxed:.2f}, '
    f'counted {b1_move.quantity:g}, missing {missing:g}, disputed {disputed:.2f}, undisputed {undisputed:.2f}')
check((missing, disputed, undisputed) == (4, 18.0, 432.0), 'B1 dispute figures')

env.flush_all()
print_counts('after seed', count_snapshot())

env.cr.commit()
out('committed')
