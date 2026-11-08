"""Build only the visual integration delta; excludes runtime and internal files."""
from pathlib import Path
import subprocess,tarfile,hashlib,json,sys
root=Path(__file__).resolve().parents[2]
out=Path(sys.argv[1]).resolve();out.mkdir(parents=True,exist_ok=True)
base='b183590'
files=subprocess.check_output(['git','diff','--name-only','--diff-filter=ACMR',base,'HEAD'],cwd=root).decode().splitlines()
allow=[]
for name in files:
 p=Path(name)
 if name in ('.htaccess','api/src/TicketDeliveryQueue.php','api/scripts/render-ticket-pdf.mjs'):
  allow.append(name);continue
 if p.parts[0] in ('docs','scripts','tests','api','database') or any(x.startswith('.') for x in p.parts) or name.startswith('assets/uploads/'):continue
 if p.suffix not in ('.html','.css','.js','.svg','.webp','.jpg','.png','.mp4','.woff2','.ttf'):raise ValueError('Review file type: '+name)
 allow.append(name)
archive=out/'perigallo-candidata-visual.tar.gz'
with tarfile.open(archive,'w:gz') as t:
 for name in allow:t.add(root/name,arcname=name)
manifest={'base_commit':base,'candidate_commit':subprocess.check_output(['git','rev-parse','HEAD'],cwd=root).decode().strip(),'files':{name:hashlib.sha256((root/name).read_bytes()).hexdigest() for name in allow},'archive_sha256':hashlib.sha256(archive.read_bytes()).hexdigest(),'production_env_included':False,'production_data_included':False,'approved_to_deploy':False}
(out/'candidata-manifiesto.json').write_text(json.dumps(manifest,indent=2));print('Packaged',len(allow),'files;',archive.stat().st_size,'bytes')
