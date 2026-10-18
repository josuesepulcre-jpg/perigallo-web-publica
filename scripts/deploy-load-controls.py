#!/usr/bin/env python3
"""Stage scoped source changes against the live app. No migrations or outbound jobs.
prepare builds separately; activate checks source fingerprints and retains rollback.
"""
import argparse, hashlib, json, os, shutil, subprocess
from pathlib import Path

def fingerprint(root, layout="standard"):
    paths=list(root.glob('*.css'))
    for name in ['src','app','lib','staff','scripts','prisma','db','package.json','package-lock.json','next.config.js','next.config.ts','next.config.mjs','.eslintrc.json','eslint.config.mjs','eslint.config.js','tsconfig.json','server.js']:
        p=root/name
        if p.is_dir(): paths.extend(x for x in p.rglob('*') if x.is_file())
        elif p.is_file(): paths.append(p)
    return {str(p.relative_to(root)): hashlib.sha256(p.read_bytes()).hexdigest() for p in sorted(paths)}

def run():
    parser=argparse.ArgumentParser()
    parser.add_argument('mode', choices=['prepare','activate','rollback'])
    parser.add_argument('--app',required=True);parser.add_argument('--work',required=True)
    parser.add_argument('--changes');parser.add_argument('--expected-build',required=True)
    parser.add_argument('--layout', choices=['standard','lab'], default='standard')
    a=parser.parse_args();app=Path(a.app).resolve();work=Path(a.work).resolve()
    if app == work or app in work.parents: raise SystemExit('Work folder must be outside the app.')
    stage=work/'build'; backup=work/'backup';manifest=work/'manifest.json'
    if a.mode=='prepare':
        if work.exists():raise SystemExit('Work folder already exists; use a fresh folder.')
        original_build=(app/'.next/BUILD_ID').read_text().strip()
        if original_build!=a.expected_build: raise SystemExit('Live build changed.')
        original=fingerprint(app)
        changes=json.loads(Path(a.changes).read_text())
        staged={}
        for change in changes:
            name=change['file']; target=(app/name).resolve()
            allowed = ('src/',) if a.layout == 'standard' else ('app/','lib/')
            if not name.startswith(allowed) or app not in target.parents:raise SystemExit('Only application source changes are permitted.')
            if name not in staged:staged[name]=(app/name).read_text() if (app/name).exists() else ''
            old=change['old'];new=change['new']
            if old:
                if staged[name].count(old)!=1:raise SystemExit('Source anchor is missing or ambiguous: '+name)
                staged[name]=staged[name].replace(old,new,1)
            else:
                if staged[name]:raise SystemExit('New source already exists: '+name)
                staged[name]=new
        work.mkdir(mode=0o700,parents=True);stage.mkdir();backup.mkdir()
        for name in ['src','app','lib','staff','scripts','prisma','db','tests']:
            if (app/name).is_dir():
                shutil.copytree(app/name,stage/name,ignore=shutil.ignore_patterns('node_modules','.next','.env*','storage','backups','__pycache__'))
        for css in app.glob('*.css'):shutil.copy2(css,stage/css.name)
        for name in ['package.json','package-lock.json','next.config.js','next.config.ts','next.config.mjs','.eslintrc.json','eslint.config.mjs','eslint.config.js','tsconfig.json','next-env.d.ts','postcss.config.js','postcss.config.mjs','jsconfig.json','tailwind.config.ts','tailwind.config.js','DEPLOYED_COMMIT']:
            if (app/name).is_file():shutil.copy2(app/name,stage/name)
        # Native dependencies stay identical. Hardlinks prevent a second install or updates.
        shutil.copytree(app/'node_modules',stage/'node_modules',copy_function=os.link,symlinks=True)
        if (app/'public').exists():shutil.copytree(app/'public',stage/'public')
        # Preserve build-time public settings without copying private credentials.
        public_lines=[]
        for name in ['.env','.env.production','.env.local','.env.production.local']:
            p=app/name
            if p.is_file():
                for line in p.read_text().splitlines():
                    if line.strip().startswith('NEXT_PUBLIC_'):public_lines.append(line)
        if public_lines:
            p=stage/'.env.production.local';p.write_text('\n'.join(public_lines)+'\n');p.chmod(0o600)
        for name,content in staged.items():
            p=stage/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_text(content)
            b=backup/name;b.parent.mkdir(parents=True,exist_ok=True)
            if (app/name).exists():shutil.copy2(app/name,b)
        manifest.write_text(json.dumps({'app':str(app),'build':original_build,'source':original,'changed':list(staged),'new':[name for name in staged if not (app/name).exists()]},indent=2))
        manifest.chmod(0o600)
        print(json.dumps({'prepared':True,'files':len(staged),'original_build':original_build}))
    elif a.mode=='activate':
        m=json.loads(manifest.read_text())
        if m['app']!=str(app) or fingerprint(app)!=m['source']:raise SystemExit('Live source changed since prepare.')
        if (app/'.next/BUILD_ID').read_text().strip()!=a.expected_build:raise SystemExit('Live build changed.')
        if not (stage/'.next/BUILD_ID').is_file():raise SystemExit('No successful staged build.')
        if (backup/'.next').exists():raise SystemExit('Already activated.')
        # Keep previous hashed assets available to tabs already open during rollout.
        shutil.copytree(app/'.next/static',stage/'.next/static',dirs_exist_ok=True)
        standalone=stage/'.next/standalone'
        if (standalone/'server.js').is_file():
            shutil.copytree(stage/'.next/static',standalone/'.next/static',dirs_exist_ok=True)
            if (stage/'public').is_dir():shutil.copytree(stage/'public',standalone/'public',dirs_exist_ok=True)
        for name in m['changed']:
            (app/name).parent.mkdir(parents=True,exist_ok=True)
            shutil.copy2(stage/name,app/name)
        (app/'.next').rename(backup/'.next')
        try:(stage/'.next').rename(app/'.next')
        except BaseException:
            (backup/'.next').rename(app/'.next');raise
        (app/'tmp').mkdir(exist_ok=True);(app/'tmp/restart.txt').touch()
        print(json.dumps({'activated':True,'build':(app/'.next/BUILD_ID').read_text().strip(),'rollback':str(backup)}))
    else:
        m=json.loads(manifest.read_text())
        if m['app']!=str(app) or not (backup/'.next').exists():raise SystemExit('No matching rollback.')
        if (work/'failed-next').exists():raise SystemExit('Rollback already used.')
        (app/'.next').rename(work/'failed-next');(backup/'.next').rename(app/'.next')
        for name in m['changed']:
            if name in m['new']:(app/name).unlink(missing_ok=True)
            else:shutil.copy2(backup/name,app/name)
        (app/'tmp/restart.txt').touch();print('{"rolled_back":true}')

if __name__=='__main__':run()
