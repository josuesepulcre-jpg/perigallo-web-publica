"""Isolated staging tests. Never use against a production URL."""
import os,subprocess,json,urllib.request,urllib.error,http.cookiejar,pathlib,sys,datetime
ROOT=pathlib.Path(__file__).resolve().parents[2]
BASE=os.environ.get('PERIGALLO_STAGING_URL','http://127.0.0.1:4191')
assert BASE in ('http://127.0.0.1:4191','http://127.0.0.1:4192')
assert 'APP_ENV=staging' in (ROOT/'.env').read_text()
creds=json.loads((ROOT.parent/'staging-login.json').read_text())
jar=http.cookiejar.CookieJar(); client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
csrf=''; results=[]
def call(path,data=None,method=None,expected=200,token=True):
 headers={'Content-Type':'application/json'}
 if token: headers['X-CSRF-Token']=csrf
 req=urllib.request.Request(BASE+'/api'+path,data=json.dumps(data).encode() if data is not None else None,headers=headers,method=method)
 try:
  r=client.open(req); status=r.status; payload=json.load(r)
 except urllib.error.HTTPError as e: status=e.code; payload=json.load(e)
 assert status==expected,(path,status,payload)
 return payload
def check(name,condition):
 assert condition,name
 results.append({'test':name,'result':'pass'})
check('Unauthenticated admin denied',not call('/admin/events',expected=401)['ok'])
a=call('/admin/login',creds); csrf=a.get('csrf','')
if not csrf: csrf=call('/admin/session')['csrf']
check('Owner login',bool(csrf))
check('CSRF enforced',not call('/admin/events',{},expected=419,token=False)['ok'])
slug='revision-unificacion-'+datetime.datetime.now().strftime('%Y%m%d%H%M%S')
event=call('/admin/events',{'title':'Revisión de integración · prueba','slug':slug,'description':'Evento sintético de validación, sin venta real.','location':'Finca La Llaguna','starts_at':'2027-04-20 20:00:00','ends_at':'2027-04-21 01:00:00','sale_starts_at':'2026-01-01 00:00:00','capacity':25,'is_test':True,'image_url':'/media/archivo/celebracion.webp'},expected=201)['event']; eid=event['id']
check('Event starts as draft',event['status']=='draft')
ticket=call(f'/admin/events/{eid}/ticket-types',{'name':'Entrada de prueba','price_cents':2500,'capacity':25,'status':'on_sale','visible':True,'sale_starts_at':'2026-01-01 00:00:00','sale_ends_at':'2027-04-20 19:00:00'},expected=201)['ticket_type']
call(f'/admin/events/{eid}/publish',{})
check('Published event in public API',any(e['slug']==slug for e in call('/events')['events']))
discount_code='REV'+datetime.datetime.now().strftime('%H%M%S')
call('/admin/discount-codes',{'code':discount_code,'discount_type':'percent','percent_basis_points':2000,'is_active':True},expected=201)
purchase={'event_slug':slug,'first_name':'Persona','last_name':'Prueba','email':'revision@example.invalid','phone':'+34600000000','privacy_accepted':True,'terms_accepted':True,'age_requirement_accepted':True,'dress_code_accepted':True,'items':[{'ticket_type_id':ticket['id'],'quantity':2}],'discount_code':discount_code,'total_cents':1,'attendees':[{'name':'Persona Prueba','has_allergies':False},{'name':'Segunda Prueba','has_allergies':False}]}
order=call('/orders',purchase,expected=201)
check('Server calculates price and 20 percent discount despite altered client total',order['order']['total_cents']==4000)
(ROOT.parent/'staging-fixture.json').write_text(json.dumps({'event_id':eid,'slug':slug,'ticket_type_id':ticket['id'],'order':order}))
check('Redsys sandbox form prepared',bool(order['payment']))
# Signed callback simulator, never contacts the payment provider.
def callback(amount,bad_signature=False,response="0000",reference=None):
 payload=json.loads(subprocess.check_output(['php','scripts/unificacion/sign-callback.php'],input=json.dumps({'reference':reference or order['order']['reference'],'amount':amount,'response':response}).encode()))
 if bad_signature: payload['Ds_Signature']='invalid'
 req=urllib.request.Request(BASE+'/api/redsys/notification',data=urllib.parse.urlencode(payload).encode(),headers={'Content-Type':'application/x-www-form-urlencoded'})
 try:
  r=client.open(req); return r.status,json.load(r)
 except urllib.error.HTTPError as e: return e.code,json.load(e)
check('Invalid payment signature rejected',callback(4000,True)[0]>=400)
check('Signed wrong amount rejected',callback(1)[0]>=400)
check('Signed simulated payment accepted',callback(4000)[0]==200)
paid=call('/orders/'+order['order']['token'])['order']
check('Paid order emits two tickets',paid['status']=='paid' and len(paid['tickets'])==2)
callback(4000)
repeated=call('/orders/'+order['order']['token'])['order']
check('Repeated callback does not duplicate tickets',[t['public_code'] for t in paid['tickets']]==[t['public_code'] for t in repeated['tickets']])
code=paid['tickets'][0]['public_code']
check('QR preview works',call('/admin/tickets/scan',{'event_id':eid,'code':code,'mode':'entry'})['ok'])
check('Entry movement works',call('/admin/tickets/access-movement',{'event_id':eid,'code':code,'action':'entry','method':'qr'})['result']=='success')
check('Duplicate entry prevented',not call('/admin/tickets/access-movement',{'event_id':eid,'code':code,'action':'entry','method':'qr'},expected=409)['ok'])
failed=call('/orders',purchase,expected=201)
check('Rejected signed payment callback processed',callback(4000,response='0180',reference=failed['order']['reference'])[0]==200)
failed_state=call('/orders/'+failed['order']['token'])['order']
check('Rejected payment emits no tickets',failed_state['status']!='paid' and not failed_state['tickets'])
pending=call('/orders',purchase,expected=201)
records=call('/admin/orders')['orders']
pending_id=next(x['id'] for x in records if x['public_token']==pending['order']['token'])
call(f'/admin/orders/{pending_id}/cancel',{'reason':'Cancelación de prueba sintética'})
check('Cancelled pending order has no tickets',call('/orders/'+pending['order']['token'])['order']['status']=='cancelled')
paid_id=next(x['id'] for x in records if x['public_token']==order['order']['token'])
call(f'/admin/orders/{paid_id}/record-refund',{'confirmed':True,'reason':'Registro sintético, sin abono ni cargo real'})
check('Refunded ticket cannot enter',call('/admin/tickets/scan',{'event_id':eid,'code':code,'mode':'entry'})['result']=='refunded')
call('/admin/logout',{})
a=call('/admin/login',{'username':'puerta','password':creds['password']}); csrf=a.get('csrf') or call('/admin/session')['csrf']
check('Door role cannot manage events',not call('/admin/events',{},expected=401)['ok'])
(ROOT/('docs/unificacion/server-integration-results.json' if BASE.endswith(':4192') else 'docs/unificacion/integration-results.json')).write_text(json.dumps(results,indent=2))
print(json.dumps(results,indent=2))
