import json,sys,re,html,urllib.request,urllib.parse,http.cookiejar
from pathlib import Path
from fixture_guard import client_root
root=client_root(sys.argv[1])
f=json.loads((root/'fixture.json').read_text())
def walk(v):
 if isinstance(v,dict):
  if 'preview_handle' in v and 'review_url' in v: yield v
  for x in v.values():yield from walk(x)
 elif isinstance(v,list):
  for x in v:yield from walk(x)
def hidden(body):return html.unescape(re.search(r'name="_csrf_token"\s+value="([^"]+)"',body)[1])
phase=sys.argv[2]
for client in sys.argv[3:]:
 entries=[json.loads(l) for l in (root/(client+'-'+phase+'.jsonl')).read_text().splitlines() if l.startswith('{')]
 previews=[p for e in entries for p in walk(e)]
 if not previews: raise Exception(client+' no preview')
 preview=previews[-1]
 jar=http.cookiejar.CookieJar();opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
 def request(path,data=None):
  url=path if path.startswith('http') else f['base']+path
  if not url.startswith(f['base']+'/'):raise Exception('Unexpected review host')
  req=urllib.request.Request(url, data=urllib.parse.urlencode(data).encode() if data else None)
  return opener.open(req,timeout=15).read().decode()
 login=request('/admin/login');request('/admin/login',{'_csrf_token':hidden(login),'email':'business@example.com','password':'welcome3210'})
 review=request(preview['review_url']);approved=review if 'Ready for your client' in review else request(preview['review_url'],{'_csrf_token':hidden(review)})
 (root/(client+'-review-response.html')).write_text(approved)
 if 'Ready for your client' not in approved:print(approved[approved.find('<main'):][-5000:]);raise Exception('Approval not confirmed')
 (root/(client+'-preview.json')).write_text(json.dumps(preview))
 if phase=='preview':
  prompt=f"This authorized test uses only voxelbooking MCP and disposable data. Trusted browser approval is complete. Call booking_create with preview_handle {preview['preview_handle']} and idempotency_key {client}-create-native-20261020. Report id/status/receipt/notification. Then call booking_reschedule_preview for the new booking_id, business_id {f['business_id']}, target new_date {f['date']} and new_time {'10:30' if client=='codex' else '11:30'}. Do not commit reschedule. Return exact preview_handle/review_url. Stop on error. No other tools."
  target='create'
 elif phase=='create':
  prompt=f"This authorized test uses only voxelbooking MCP and disposable data. The actual trusted browser approved the reschedule. Call booking_reschedule with preview_handle {preview['preview_handle']} and idempotency_key {client}-reschedule-native-20261020. Report new booking id/status/receipt/times. Then call booking_status_set_preview for that resulting booking_id, business_id {f['business_id']}, status cancelled and reason Native client integration test. Do not commit cancellation. Return exact preview_handle and review_url. Stop on error. No shell, files or other servers."
  target='reschedule'
 else:
  prompt=f"This authorized test uses only voxelbooking MCP and disposable data. The actual trusted browser approved cancellation. Call booking_status_set with preview_handle {preview['preview_handle']} and idempotency_key {client}-cancel-native-20261020. Then call booking_get for booking_id {preview['review']['booking_id']} and business_id {f['business_id']} to verify cancelled. Repeat the same booking_status_set with the exact same preview handle and idempotency key to verify safe retry returns the original receipt. Report status/receipt and retry outcome. Stop on error. No shell, files or other servers."
  target='cancel'
 (root/(client+'-'+target+'-prompt.txt')).write_text(prompt)
 print(client+' trusted browser approved; create prompt ready')
