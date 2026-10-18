import importlib.util,unittest
from pathlib import Path
spec=importlib.util.spec_from_file_location('backup_cron',Path(__file__).resolve().parents[1]/'scripts/configure-reservas-backup-cron.py')
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
class BackupCron(unittest.TestCase):
 def test_only_backup_schedule_and_executable_change(self):
  other='MAILTO=owner@example.test\n* * * * * unrelated-task TOKEN=must-preserve\n'
  source=other+'17 3 * * * cd /var/www/vhosts/perigallo.com/reservas.perigallo.com && /opt/plesk/node/25/bin/node scripts/export-critical-data-backup-server.mjs --retention=20 >> logs/critical-backup-cron.log 2>&1\n'
  actual=module.transform(source)
  self.assertTrue(actual.startswith(other));self.assertIn('--if-stale --retention=20 >> logs/critical-backup-cron.log 2>&1',actual)
  self.assertEqual(module.transform(actual),actual);self.assertIn('43 * * * * cd ',actual)
 def test_plesk_whitespace_is_supported(self):
  source='17\t3  * * * cd app && /opt/plesk/node/25/bin/node scripts/export-critical-data-backup-server.mjs >> log 2>&1\n'
  self.assertIn('43 * * * * cd app && /bin/bash scripts/run-critical-backup-guarded.sh --if-stale >> log 2>&1',module.transform(source))
 def test_duplicates_and_unexpected_schedules_are_rejected(self):
  task='17 3 * * * /opt/plesk/node/25/bin/node scripts/export-critical-data-backup-server.mjs\n'
  for source in ['',task*2,task.replace('17 3','18 3')]:
   with self.assertRaises(ValueError):module.transform(source)
if __name__=='__main__':unittest.main()
