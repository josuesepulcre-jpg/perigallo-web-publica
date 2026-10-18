import importlib.util
from pathlib import Path
spec=importlib.util.spec_from_file_location('cron',Path(__file__).resolve().parents[1]/'scripts/configure-database-pressure-cron.py')
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
base=module.BASE
original='MAILTO="fixture"\n* * * * * cd '+base+'/staff.perigallo.com && /opt/plesk/node/25/bin/node scripts/sync-suite-events.mjs > tmp/sync.log 2>&1\n*/5 * * * * cd '+base+'/lab.perigallo.com && node scripts/poll-purchase-invoices.mjs\n17 3 * * * unchanged-command\n'
updated=module.plan(original)
assert '/bin/bash scripts/plesk-sync-suite-events.sh' in updated
assert '2-59/5 * * * *' in updated
assert '17 3 * * * unchanged-command' in updated
assert 'MAILTO="fixture"' in updated
assert module.plan(updated)==updated
try:module.plan(original+original)
except RuntimeError:pass
else:raise AssertionError('duplicate tasks must stop deployment')
print('Scoped cron changes, idempotence, preservation and duplicate protection passed.')
