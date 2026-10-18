#!/usr/bin/env python3
"""Preserve the existing backup command's redirections; recover hourly if stale.
Default is a read-only plan. Back up the complete crontab privately before apply.
"""
import argparse,json,os,re,subprocess
from pathlib import Path

OLD=re.compile(r'/opt/plesk/node/25/bin/node\s+scripts/export-critical-data-backup-server\.mjs')
NEW='/bin/bash scripts/run-critical-backup-guarded.sh --if-stale'
def transform(source):
 lines=source.splitlines(keepends=True)
 matches=[i for i,line in enumerate(lines) if not line.lstrip().startswith('#') and ('scripts/export-critical-data-backup-server.mjs' in line or 'scripts/run-critical-backup-guarded.sh' in line)]
 if len(matches)!=1:raise ValueError('Expected exactly one reservation backup task.')
 i=matches[0];line=lines[i];parts=line.split(maxsplit=5)
 if len(parts)!=6:raise ValueError('Malformed cron task.')
 if NEW in line:
  if parts[:5]!=['43','*','*','*','*']:raise ValueError('Unexpected existing wrapper schedule.')
  return source
 if parts[:5]!=['17','3','*','*','*']:raise ValueError('Unexpected original backup schedule.')
 if len(OLD.findall(line))!=1:raise ValueError('Unexpected backup executable.')
 lines[i]='43 * * * * '+OLD.sub(NEW,parts[5],count=1)
 return ''.join(lines)

def main():
 p=argparse.ArgumentParser();p.add_argument('--apply',action='store_true');p.add_argument('--backup-dir');a=p.parse_args()
 result=subprocess.run(['crontab','-l'],capture_output=True,text=True,check=True)
 before=result.stdout;after=transform(before)
 if a.apply and after!=before:
  if not a.backup_dir:raise SystemExit('An external private backup directory is required.')
  directory=Path(a.backup_dir).resolve()
  app=Path('/var/www/vhosts/perigallo.com/reservas.perigallo.com')
  if directory==app or app in directory.parents:raise SystemExit('Backup must be outside the app.')
  directory.mkdir(parents=True,exist_ok=False,mode=0o700)
  file=directory/'crontab.before'
  fd=os.open(file,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
  with os.fdopen(fd,'w') as f:f.write(before)
  # Recheck immediately before replacing so concurrent edits are never overwritten.
  current=subprocess.run(['crontab','-l'],capture_output=True,text=True,check=True).stdout
  if current!=before:raise SystemExit('Crontab changed; refusing to overwrite it.')
  subprocess.run(['crontab','-'],input=after,text=True,check=True)
 print(json.dumps({'applied':a.apply,'changed':after!=before}))
if __name__=='__main__':main()
