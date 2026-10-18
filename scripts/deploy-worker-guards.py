#!/usr/bin/env python3
"""Guarded rollout of Node CLI and startup helpers; no bundles, env edits or jobs."""
from pathlib import Path
import argparse,json,hashlib,os,subprocess

def digest(p):return hashlib.sha256(p.read_bytes()).hexdigest() if p.is_file() else None

def run():
 a=argparse.ArgumentParser();a.add_argument('mode',choices=['prepare','activate','rollback']);a.add_argument('--app',required=True);a.add_argument('--work',required=True);a.add_argument('--changes');a.add_argument('--build',required=True);a.add_argument('--node',required=True);a.add_argument('--restart',action='store_true');o=a.parse_args()
 app=Path(o.app).resolve();work=Path(o.work).resolve();manifest=work/'manifest.json'
 if app==work or app in work.parents:raise SystemExit('Private work must be outside application')
 if o.mode=='prepare':
  if work.exists():raise SystemExit('Choose a fresh work directory')
  if (app/'.next/BUILD_ID').read_text().strip()!=o.build:raise SystemExit('Build changed')
  patches=json.loads(Path(o.changes).read_text());content={};before={}
  for patch in patches:
   name=patch['file'];p=(app/name).resolve()
   if app not in p.parents or not (name.startswith('scripts/') or name=='lib/server/db/worker-budget.mjs'):raise SystemExit('Unsupported path')
   if name not in content:content[name]=p.read_text() if p.exists() else '';before[name]=digest(p)
   old=patch['old'];new=patch['new']
   if old:
    if content[name].count(old)!=1:raise SystemExit('Missing/ambiguous anchor: '+name)
    content[name]=content[name].replace(old,new,1)
   else:
    if content[name]:raise SystemExit('Source already exists')
    content[name]=new
  work.mkdir(parents=True,mode=0o700)
  for name,text in content.items():
   p=work/'stage'/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_text(text)
   result=subprocess.run([o.node,'--check',str(p)],capture_output=True,text=True)
   if result.returncode:raise SystemExit('Syntax check failed: '+name)
   if before[name] is not None:
    b=work/'backup'/name;b.parent.mkdir(parents=True,exist_ok=True);b.write_bytes((app/name).read_bytes())
  data={'app':str(app),'build':o.build,'before':before,'after':{n:digest(work/'stage'/n) for n in content},'server':digest(app/'server.js'),'restart':o.restart,'activated':False}
  manifest.write_text(json.dumps(data,indent=2));manifest.chmod(0o600);print(json.dumps({'prepared':True,'files':len(content),'syntax':'passed'}));return
 m=json.loads(manifest.read_text())
 if m['app']!=str(app):raise SystemExit('Wrong app')
 if (app/'.next/BUILD_ID').read_text().strip()!=o.build or digest(app/'server.js')!=m['server']:raise SystemExit('Runtime changed')
 expected=m['before'] if o.mode=='activate' else m['after']
 if any(digest(app/n)!=v for n,v in expected.items()):raise SystemExit('Concurrent source change')
 if o.mode=='activate' and any(digest(work/'stage'/n)!=v for n,v in m['after'].items()):raise SystemExit('Staged source changed')
 if o.mode=='activate' and m['activated']:raise SystemExit('Already activated')
 if o.mode=='rollback' and not m['activated']:raise SystemExit('Not activated')
 # Add dependencies before entry points; every replacement is atomic.
 names=sorted(expected,key=lambda n:(m['before'][n] is not None,n))
 try:
  for name in names:
   p=app/name;p.parent.mkdir(parents=True,exist_ok=True)
   source=work/('stage' if o.mode=='activate' else 'backup')/name
   if o.mode=='rollback' and m['before'][name] is None:p.unlink(missing_ok=True);continue
   temporary=p.with_name(p.name+'.worker-guard-update');temporary.write_bytes(source.read_bytes());temporary.chmod(0o644);os.replace(temporary,p)
 except BaseException:
  if o.mode=='activate':
   for name,sha in m['before'].items():
    p=app/name
    if sha is None:p.unlink(missing_ok=True)
    else:
     t=p.with_name(p.name+'.worker-guard-restore');t.write_bytes((work/'backup'/name).read_bytes());os.replace(t,p)
  raise
 m['activated']=o.mode=='activate';manifest.write_text(json.dumps(m,indent=2))
 if m['restart']:(app/'tmp').mkdir(exist_ok=True);(app/'tmp/restart.txt').touch()
 print(json.dumps({'activated':m['activated'],'files':len(names),'restart':m['restart'],'bundles_unchanged':True}))
if __name__=='__main__':run()
