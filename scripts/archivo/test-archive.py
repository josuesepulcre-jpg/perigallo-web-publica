"""Real HTTP + MariaDB checks, loopback archive environment only."""
from pathlib import Path
import urllib.request,urllib.error,urllib.parse,http.cookiejar,json,subprocess,datetime,uuid,struct,zlib
ROOT=Path(__file__).resolve().parents[2]; BASE='http://127.0.0.1:4195'
assert 'DB_DATABASE=perigallo_archivo_local' in (ROOT/'.env').read_text()
creds=json.loads((ROOT.parent/'staging-login.json').read_text()); jar=http.cookiejar.CookieJar();http=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar));csrf='';results=[];ids=[]
def check(name,ok):
 assert ok,name
 results.append({'test':name,'result':'pass'})
def call(path,data=None,method=None,status=200,token=True):
 headers={'Content-Type':'application/json'}
 if token:headers['X-CSRF-Token']=csrf
 req=urllib.request.Request(BASE+'/api'+path,data=json.dumps(data).encode() if data is not None else None,method=method,headers=headers)
 try:r=http.open(req);code=r.status;payload=json.load(r)
 except urllib.error.HTTPError as e:code=e.code;payload=json.load(e)
 assert code==status,(path,code,payload)
 return payload
def page(path,status=200):
 try:r=http.open(BASE+path);code=r.status;body=r.read().decode()
 except urllib.error.HTTPError as e:code=e.code;body=e.read().decode()
 assert code==status,(path,code)
 return body
check('Anonymous cannot read editorial drafts',not call('/admin/experience-archive',status=401)['ok'])
call('/admin/login',creds);csrf=call('/admin/session')['csrf']
check('CSRF required for archive mutations',not call('/admin/experience-archive',{},method='POST',status=419,token=False)['ok'])
seed=call('/admin/experience-archive')['experiences'];one=next(x for x in seed if x['slug']=='la-perigalla-01-noche-ibicenca');comedy=next(x for x in seed if x['slug']=='la-perigalla-comedy-ruben-serrano')
one=call('/admin/experience-archive/'+str(one['id']))['experience'];comedy=call('/admin/experience-archive/'+str(comedy['id']))['experience']
check('Original Perigalla keeps immersive story and 10 captions',one['document']['legacy_url']=='/la-perigalla-01/' and len(one['document']['blocks'])==13)
check('Comedy is a private draft with no invented photographs',comedy['status']=='draft' and not comedy['document']['cover_image'] and not any(b['media'] for b in comedy['document']['blocks']))
public=call('/experience-archive')['experiences'];check('Only explicit published snapshots are public',any(x['id']==one['id'] for x in public) and not any(x['id']==comedy['id'] for x in public))
check('Comedy public route returns 404','404' in page('/experiencias/vividas/'+comedy['slug']+'/',404))
check('Authenticated Comedy preview works','Rubén Serrano' in page('/experiencias/vividas/'+comedy['slug']+'/?preview=1'))
slug='archivo-test-'+uuid.uuid4().hex[:12];doc=dict(one['document']);doc['title']='Prueba editorial';doc['blocks']=doc['blocks'][:2]
e=call('/admin/experience-archive',{'slug':slug,'document':doc},method='POST',status=201)['experience'];ids.append(e['id'])
check('New chapter starts as unpublished draft',e['status']=='draft')
check('Duplicate stable URL is rejected',not call('/admin/experience-archive',{'slug':slug,'document':doc},method='POST',status=409)['ok'])
for bad in ['javascript:alert(1)','//example.com/a.jpg','/media/../a.jpg','/media/%2e%2e/a.jpg','https://example.com/a.jpg','/media/payload.svg']:
 altered=dict(doc,cover_image=bad);call('/admin/experience-archive/'+str(e['id']),{'document':altered,'revision':e['revision']},method='PUT',status=422)
check('Unsafe media URLs rejected',True)
future=dict(doc,date='2028-02-29');e=call('/admin/experience-archive/'+str(e['id']),{'document':future,'revision':e['revision']},method='PUT')['experience']
call('/admin/experience-archive/'+str(e['id'])+'/publish',{'revision':e['revision']},method='POST',status=422);check('Future editions cannot publish as lived experiences',True)
bad_date=dict(doc,date='2026-02-30');call('/admin/experience-archive/'+str(e['id']),{'document':bad_date,'revision':e['revision']},method='PUT',status=422);check('Invalid calendar dates rejected',True)
e=call('/admin/experience-archive/'+str(e['id']),{'document':doc,'revision':e['revision']},method='PUT')['experience']
call('/admin/experience-archive/'+str(e['id']),{'document':doc,'revision':e['revision']-1},method='PUT',status=409);check('Concurrent edits protected with revision',True)
e=call('/admin/experience-archive/'+str(e['id'])+'/publish',{'revision':e['revision']},method='POST')['experience']
check('Explicit publication creates readable SSR page with metadata','Prueba editorial' in page('/experiencias/vividas/'+slug+'/') and 'og:image' in page('/experiencias/vividas/'+slug+'/'))
changed=dict(doc,title='Unpublished revision <script>alert(1)</script>');e=call('/admin/experience-archive/'+str(e['id']),{'document':changed,'revision':e['revision']},method='PUT')['experience']
check('Saving a draft leaves the public snapshot unchanged','Unpublished revision' not in page('/experiencias/vividas/'+slug+'/'))
preview=page('/experiencias/vividas/'+slug+'/?preview=1');check('SSR escapes stored text; preview is noindex','&lt;script&gt;' in preview and '<script>alert(1)</script>' not in preview and 'noindex,nofollow' in preview)
for action in ['unpublish','restore','publish','archive','restore']:
 e=call('/admin/experience-archive/'+str(e['id'])+'/'+action,{'revision':e['revision']},method='POST')['experience']
 if action in ['unpublish','archive','restore']:page('/experiencias/vividas/'+slug+'/',404)
check('Withdraw, archive and restore preserve content but remove public access',e['document']['title']==changed['title'] and e['status']=='draft')
# Multipart upload goes through real PHP file handling and GD optimizer.
def upload(filename,content,kind='image',mime='image/png',expected=201):
 boundary='ArchiveBoundary'+uuid.uuid4().hex
 body=(f'--{boundary}\r\nContent-Disposition: form-data; name="kind"\r\n\r\n{kind}\r\n--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{filename}"\r\nContent-Type: {mime}\r\n\r\n').encode()+content+f'\r\n--{boundary}--\r\n'.encode()
 req=urllib.request.Request(BASE+'/api/admin/experience-archive/media',data=body,headers={'X-CSRF-Token':csrf,'Content-Type':'multipart/form-data; boundary='+boundary})
 try:r=http.open(req);code=r.status;payload=json.load(r)
 except urllib.error.HTTPError as err:code=err.code;payload=json.load(err)
 assert code==expected,(code,payload)
 return payload
def png(w,h):
 def chunk(kind,data):return struct.pack('!I',len(data))+kind+data+struct.pack('!I',zlib.crc32(kind+data)&0xffffffff)
 return b'\x89PNG\r\n\x1a\n'+chunk(b'IHDR',struct.pack('!2I5B',w,h,8,2,0,0,0))+chunk(b'IDAT',zlib.compress((b'\x00'+b'\x66\x88\x66'*w)*h))+chunk(b'IEND',b'')
media=upload('real-upload-test.png',png(2400,1200))['media'];check('Uploaded image optimized to 2000px WebP; original preserved',media['width']==2000 and media['height']==1000 and media['url'].endswith('.webp') and (ROOT/media['original_url'].lstrip('/')).exists())
check('Optimized image is served successfully',http.open(BASE+media['url']).status==200)

video=upload('video-real-fixture.mp4',(ROOT/'media/hero-stream-mobile.mp4').read_bytes(),kind='video',mime='video/mp4')['media']
check('Real MP4 upload is accepted without changing its contents',video['media_type']=='video' and (ROOT/video['url'].lstrip('/')).read_bytes()==(ROOT/'media/hero-stream-mobile.mp4').read_bytes())
video_doc=dict(doc,cover_video=video['url']);e=call('/admin/experience-archive/'+str(e['id']),{'document':video_doc,'revision':e['revision']},method='PUT')['experience']
check('Cover and summary videos render with mobile controls and playback toggle','data-archive-hero-video' in page('/experiencias/vividas/'+slug+'/?preview=1') and 'playsinline' in page('/experiencias/vividas/'+slug+'/?preview=1'))
external=dict(doc,cover_video='https://www.youtube.com/watch?v=example');e=call('/admin/experience-archive/'+str(e['id']),{'document':external,'revision':e['revision']},method='PUT')['experience']
check('External video opens with safe HTTPS link and no unsolicited iframe','Ver vídeo en YouTube' in page('/experiencias/vividas/'+slug+'/?preview=1') and '<iframe' not in page('/experiencias/vividas/'+slug+'/?preview=1'))
(ROOT/video['url'].lstrip('/')).unlink()

upload('payload.svg',b'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',mime='image/svg+xml',expected=422);check('SVG/script upload rejected',True)
# Automation must not change events nor publish empty stories.
local_sql=['/opt/homebrew/bin/mariadb','--socket='+str(ROOT.parent/'staging-db/mysql.sock'),'-u','root','perigallo_archivo_local','-N','-B']
fixture=call('/admin/events',{'title':'Prueba autoarchivo','slug':slug+'-auto','starts_at':'2026-01-01 20:00:00','ends_at':'2026-01-02 01:00:00','description':'Prueba local de automatización','location':'Local','capacity':2,'is_test':False,'image_url':'/media/archivo/escena.webp'},method='POST',status=201)['event'];event_id=fixture['id']
subprocess.run(local_sql+['-e',f"UPDATE events SET status='finished' WHERE id={event_id}"],check=True)
e=call('/admin/experience-archive/'+str(e['id']),{'document':doc,'revision':e['revision'],'event_id':event_id},method='PUT')['experience']
call('/admin/experience-archive/sync',{},method='POST')
linked=[x for x in call('/admin/experience-archive')['experiences'] if str(x['event_id'])==str(event_id)]
check('Linked editorial history prevents automatic duplicate',len(linked)==1 and linked[0]['id']==e['id'])
call('/admin/experience-archive',{'slug':slug+'-duplicate-event','document':doc,'event_id':event_id},method='POST',status=409)
check('An event cannot be linked to two histories',True)
call('/admin/experience-archive/'+str(e['id']),{'document':doc,'revision':e['revision'],'event_id':99999999},method='PUT',status=422)
check('Nonexistent event association is rejected',True)
e=call('/admin/experience-archive/'+str(e['id']),{'document':doc,'revision':e['revision'],'event_id':None},method='PUT')['experience']
first=call('/admin/experience-archive/sync',{},method='POST');second=call('/admin/experience-archive/sync',{},method='POST');auto=next(x for x in call('/admin/experience-archive')['experiences'] if x['event_id']==event_id or str(x['event_id'])==str(event_id));ids.append(auto['id'])
check('Completed events generate draft once, without public content',first['created']>=1 and second['created']==0 and auto['status']=='draft' and not any(x['id']==auto['id'] for x in call('/experience-archive')['experiences']))
check('Automation preserves original event status',subprocess.check_output(local_sql+['-e',f'SELECT status FROM events WHERE id={event_id}'],text=True).strip()=='finished')
call('/admin/logout',{},method='POST');call('/admin/login',{'username':'puerta','password':creds['password']});csrf=call('/admin/session')['csrf'];call('/admin/experience-archive',status=401);call('/admin/experience-archive/sync',{},method='POST',status=401);check('Door role cannot read or mutate editorial drafts',True)
# Test-created rows only, in the isolated local DB. The original two chapters remain.
subprocess.run(local_sql+['-e','DELETE FROM experience_archive WHERE id IN ('+','.join(map(str,ids))+');'+f'UPDATE events SET is_test=1 WHERE id={event_id}'],check=True)
for name in ['url','original_url']:(ROOT/media[name].lstrip('/')).unlink()
(ROOT/'docs/archivo/test-results.json').write_text(json.dumps(results,indent=2));print(json.dumps(results,indent=2))
