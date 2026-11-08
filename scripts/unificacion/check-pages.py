from pathlib import Path
from html.parser import HTMLParser
from urllib.parse import urlsplit,unquote
import json,hashlib
root=Path(__file__).resolve().parents[2]
manifest=json.loads((root/'docs/unificacion/integration-manifest.json').read_text())
class Links(HTMLParser):
 def __init__(self):super().__init__();self.urls=[]
 def handle_starttag(self,tag,attrs):
  for key,value in attrs:
   if key in ('src','href','poster') and value and value.startswith('/') and not value.startswith('//'):self.urls.append(value)
errors=[];n=0
for p in root.rglob('*.html'):
 if any(x in p.parts for x in ['la-perigalla-01','.git']):continue
 parser=Links();parser.feed(p.read_text());n+=1
 for url in parser.urls:
  path=unquote(urlsplit(url).path)
  if path.startswith(('/api/','/experiencias/revision-','/admin/eventos/')) or path=='/admin/acceso/':continue
  target=root/path.lstrip('/')
  if target.exists():continue
  errors.append({'page':str(p.relative_to(root)),'missing':path})
baseline=json.loads((root/'docs/unificacion/backend-baseline.json').read_text())
changed=[p for p,h in baseline.items() if not (root/p).exists() or hashlib.sha256((root/p).read_bytes()).hexdigest()!=h]
report={'html_pages_checked':n,'missing_links_or_resources':errors,'backend_changes_from_live_baseline':changed}
(root/'docs/unificacion/page-results.json').write_text(json.dumps(report,indent=2));print(json.dumps(report,indent=2));assert not errors and not changed
