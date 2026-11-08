"""Version changed operational assets by content to avoid stale deployment caches."""
from pathlib import Path
import subprocess,re,hashlib
root=Path(__file__).resolve().parents[2]
modified=set(subprocess.check_output(['git','diff','--name-only','b183590'],cwd=root).decode().splitlines())
modified.add('assets/js/live-agenda.js')
assets={p:'unif-'+hashlib.sha256((root/p).read_bytes()).hexdigest()[:10] for p in modified if p.startswith(('assets/js/','assets/css/')) and (root/p).is_file()}
for p in root.rglob('*.html'):
 if 'public-build' in p.parts:continue
 s=p.read_text()
 def replace(m):
  name=m[2].lstrip('/').split('?',1)[0]
  return m[1]+m[2].split('?',1)[0]+'?v='+assets[name]+m[3] if name in assets else m[0]
 new=re.sub(r'''((?:href|src)=["'])(/assets/(?:js|css)/[^"']+)(["'])''',replace,s)
 if new!=s:p.write_text(new)
print('Content versions updated:',len(assets),'assets')
