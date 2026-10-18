import unittest,tempfile,json,subprocess,shutil
from pathlib import Path
SCRIPT=Path(__file__).resolve().parents[1]/'scripts/deploy-worker-guards.py'
class WorkerGuardRollout(unittest.TestCase):
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup);self.root=Path(self.tmp.name);self.app=self.root/'app';self.work=self.root/'private/work';(self.app/'scripts').mkdir(parents=True);(self.app/'.next').mkdir();(self.app/'.next/BUILD_ID').write_text('unchanged');(self.app/'scripts/worker.mjs').write_text('const budget=10;\n');(self.app/'server.js').write_text('existing runtime');self.patch=self.root/'patch.json';self.patch.write_text(json.dumps([{'file':'scripts/helper.mjs','old':'','new':'export const budget=2;\n'},{'file':'scripts/worker.mjs','old':'budget=10','new':'budget=2'}]));self.node=shutil.which('node')
 def call(self,mode):return subprocess.run(['python3',str(SCRIPT),mode,'--app',str(self.app),'--work',str(self.work),'--changes',str(self.patch),'--build','unchanged','--node',self.node,'--restart'],capture_output=True,text=True)
 def test_prepare_activate_and_rollback_preserve_runtime_and_bundle(self):
  self.assertEqual(self.call('prepare').returncode,0);self.assertEqual((self.app/'scripts/worker.mjs').read_text(),'const budget=10;\n');self.assertEqual(self.call('activate').returncode,0);self.assertTrue((self.app/'scripts/helper.mjs').is_file());self.assertTrue((self.app/'tmp/restart.txt').is_file());self.assertEqual((self.app/'.next/BUILD_ID').read_text(),'unchanged');self.assertEqual(self.call('rollback').returncode,0);self.assertFalse((self.app/'scripts/helper.mjs').exists());self.assertEqual((self.app/'scripts/worker.mjs').read_text(),'const budget=10;\n')
 def test_concurrent_edits_block_activation(self):
  self.assertEqual(self.call('prepare').returncode,0);(self.app/'scripts/worker.mjs').write_text('other update');self.assertNotEqual(self.call('activate').returncode,0);self.assertFalse((self.app/'scripts/helper.mjs').exists())
 def test_invalid_syntax_blocks_activation(self):
  self.patch.write_text(json.dumps([{'file':'scripts/helper.mjs','old':'','new':'const = broken'}]));self.assertNotEqual(self.call('prepare').returncode,0);self.assertFalse((self.app/'scripts/helper.mjs').exists())
 def test_staged_tampering_and_runtime_changes_are_rejected(self):
  self.assertEqual(self.call('prepare').returncode,0);(self.work/'stage/scripts/helper.mjs').write_text('modified');self.assertNotEqual(self.call('activate').returncode,0);self.assertFalse((self.app/'scripts/helper.mjs').exists())
 def test_paths_cannot_escape_source_scope(self):
  self.patch.write_text(json.dumps([{'file':'../elsewhere','old':'','new':'no'}]));self.assertNotEqual(self.call('prepare').returncode,0);self.assertFalse((self.root/'elsewhere').exists())
if __name__=='__main__':unittest.main()
