import json, sys
from fixture_guard import client_root
root=client_root(sys.argv[1]); f=json.loads((root/'fixture.json').read_text())
for client in ['codex','claude']:
    base="This is an authorized integration test on disposable VoxelBooking data. Use only the configured voxelbooking MCP server, no shell, files or other servers. Stop on tool errors. "
    (root/(client+'-read-prompt.txt')).write_text(base+f"Call bookings_list for business_id {f['business_id']}. Call staff_availability_search with that business_id, service_id {f['service_id']}, date {f['date']}. Report count, timezone and two available times. Do not change data.")
    time='10:00' if client=='codex' else '11:00'
    (root/(client+'-preview-prompt.txt')).write_text(base+f"Call booking_create_preview for business_id {f['business_id']}, service_id {f['service_id']}, date {f['date']}, time {time}, customer name {client} test customer and email {client}-test@example.test. Do not commit. Return exact preview_handle, review_url and expires_at plus review summary.")
    (root/(client+'-revoked-prompt.txt')).write_text(base+f"The VoxelBooking connection was just revoked. Attempt bookings_list for business_id {f['business_id']}. Report connection or tool failure without using alternatives.")
