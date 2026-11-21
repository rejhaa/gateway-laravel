# Hard cases

Seven development cases in the format of CASE-FORMAT.md that go past the spike cases: a forwarded message with a two-page PDF, a scan without a text layer, a phone photo, a confirmation that names only the supplier's order number, a misdirected mail, port dates without a delivery date, and a revised confirmation. `_tools/generate.py` writes them and `_tools/validate.py` checks them.

- `hard-01-forwarded-two-line-confirmation`: Mikko forwards Tai-Yuan's two-line confirmation as an attached message; heads in full on time by rule, 150 of 180 shafts 9 days late via the lead, 30 shafts and the MO gap open.
- `hard-02-scanned-confirmation-late`: Saigon answers a chase with a scanned confirmation without a text layer, 400 grips in full, 9 days late, nothing waiting; only through the lead.
- `hard-03-warehouse-photo-short-delivery`: Pekka writes in Finnish with a phone photo of the delivery note, 10 of 12 cartons arrived and 500 of 600 counted; receipt for 500 and bill dispute via the lead, claim to the buyer.
- `hard-04-supplier-order-number-only`: Tai-Yuan's confirmation names only its sales order number; two head orders of 120 each, the right one (P00101, MO waiting) found by the supplier reference and updated via the lead, the other untouched.
- `hard-05-misdirected-confirmation`: Tai-Yuan sends us a confirmation for another customer's PO 4500021877 (Kestrel Sports, driver and fairway heads); nothing written, the purchasing assistant tells the supplier.
- `hard-06-etd-eta-no-delivery-date`: A new Chinese supplier confirms 300 stand bags with only ETD Xiamen, ETA Helsinki port and about 3 days to Lahti; planned date stays, delivery date asked for, the lead rejects a computed date.
- `hard-07-revised-confirmation-over-cap`: Saigon confirms 180 grips on time with an MO waiting, and two days later moves the same line by 16 days; the revision governs via the lead, the lead rejects recording the superseded one.
