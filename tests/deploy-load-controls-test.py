import unittest,tempfile,subprocess,json
from pathlib import Path

SCRIPT=Path(__file__).resolve().parents[1]/'scripts/deploy-load-controls.py'
class DeploymentSafety(unittest.TestCase):
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
  self.root=Path(self.tmp.name);self.app=self.root/'app';self.work=self.root/'private/work';self.app.mkdir()
  for d in ['src','node_modules','public','.next/static']:(self.app/d).mkdir(parents=True,exist_ok=True)
  (self.app/'src/component.ts').write_text('existing UI\nold polling\n')
  (self.app/'src/other.ts').write_text('keep this operational feature\n')
  (self.app/'src/lib/data').mkdir(parents=True);(self.app/'src/lib/data/admin.ts').write_text('source module')
  (self.app/'node_modules/test.js').write_text('module.exports = true')
  (self.app/'package.json').write_text('{}');(self.app/'server.js').write_text('existing safeguards')
  (self.app/'public/image.txt').write_text('unchanged')
  (self.app/'.next/BUILD_ID').write_text('before');(self.app/'.next/static/old.js').write_text('old asset')
  (self.app/'.env').write_text('DB_PASSWORD=private-secret\nNEXT_PUBLIC_APP_URL=https://example.test\n')
  self.changes=self.root/'changes.json';self.changes.write_text(json.dumps([{'file':'src/component.ts','old':'old polling\n','new':'controlled polling\n'},{'file':'src/nested/new.ts','old':'','new':'new helper\n'}]))
 def call(self,mode,layout="standard"):
  return subprocess.run(['python3',str(SCRIPT),mode,'--app',str(self.app),'--work',str(self.work),'--changes',str(self.changes),'--expected-build','before','--layout',layout],capture_output=True,text=True)
 def prepare(self):self.assertEqual(self.call('prepare').returncode,0)
 def build(self):
  p=self.work/'build/.next/static';p.mkdir(parents=True);(p/'new.js').write_text('new asset');(p.parent/'BUILD_ID').write_text('after')
 def test_preparation_preserves_private_settings_and_all_unrelated_code(self):
  self.prepare();stage=self.work/'build'
  self.assertEqual((stage/'src/other.ts').read_text(),'keep this operational feature\n')
  self.assertEqual((self.app/'src/component.ts').read_text(),'existing UI\nold polling\n')
  self.assertNotIn('private-secret',(stage/'.env.production.local').read_text())
  self.assertEqual((stage/'src/lib/data/admin.ts').read_text(),'source module')
  self.assertFalse((stage/'.env').exists());self.assertEqual((self.app/'server.js').read_text(),'existing safeguards')
 def test_activation_retains_old_assets_and_can_restore_the_complete_previous_build(self):
  self.prepare();self.build();self.assertEqual(self.call('activate').returncode,0)
  self.assertTrue((self.app/'.next/static/old.js').exists());self.assertTrue((self.app/'.next/static/new.js').exists())
  self.assertEqual((self.app/'src/component.ts').read_text(),'existing UI\ncontrolled polling\n')
  self.assertEqual(self.call('rollback').returncode,0)
  self.assertEqual((self.app/'.next/BUILD_ID').read_text(),'before');self.assertFalse((self.app/'src/nested/new.ts').exists())
  self.assertEqual((self.app/'src/component.ts').read_text(),'existing UI\nold polling\n')
 def test_live_source_changes_block_activation(self):
  self.prepare();self.build();(self.app/'src/other.ts').write_text('another deployment')
  self.assertNotEqual(self.call('activate').returncode,0);self.assertEqual((self.app/'.next/BUILD_ID').read_text(),'before')
 def test_missing_or_ambiguous_anchors_never_modify_live_files(self):
  (self.app/'src/component.ts').write_text('old polling\nold polling\n')
  self.assertNotEqual(self.call('prepare').returncode,0);self.assertFalse(self.work.exists())
 def test_no_activation_without_successful_build(self):
  self.prepare();self.assertNotEqual(self.call('activate').returncode,0);self.assertEqual((self.app/'.next/BUILD_ID').read_text(),'before')

 def test_lab_layout_keeps_private_env_out_and_checks_lib_for_concurrent_changes(self):
  (self.app/'lib').mkdir();(self.app/'lib/read.mjs').write_text('old read')
  (self.app/'staff').mkdir();(self.app/'staff/.env.local').write_text('PRIVATE=secret')
  self.changes.write_text(json.dumps([{'file':'lib/read.mjs','old':'old read','new':'shared read'}]))
  self.assertNotEqual(self.call('prepare').returncode,0)
  self.assertEqual(self.call('prepare','lab').returncode,0)
  self.assertFalse((self.work/'build/staff/.env.local').exists())
  self.build();(self.app/'lib/read.mjs').write_text('someone else changed it')
  self.assertNotEqual(self.call('activate','lab').returncode,0)
 def test_standalone_runtime_keeps_both_old_and_new_browser_assets(self):
  self.prepare();self.build();p=self.work/'build/.next/standalone';p.mkdir();(p/'server.js').write_text('standalone server')
  self.assertEqual(self.call('activate').returncode,0)
  self.assertTrue((self.app/'.next/standalone/.next/static/old.js').exists())
  self.assertTrue((self.app/'.next/standalone/.next/static/new.js').exists())
  self.assertTrue((self.app/'.next/standalone/public/image.txt').exists())

if __name__=='__main__':unittest.main()
