#!/usr/bin/env python3
"""Scoped Plesk scheduling update; never print cron contents or credentials."""
import argparse
from pathlib import Path
import re
import subprocess

BASE = '/var/www/vhosts/perigallo.com'
STAFF = BASE + '/staff.perigallo.com'
LAB = BASE + '/lab.perigallo.com'
MONITOR = BASE + '/perigallo.com/api/cron/database-monitor.php'

def plan(raw):
    lines = raw.splitlines()
    staff = [i for i, line in enumerate(lines) if not line.lstrip().startswith('#') and STAFF in line and ('scripts/sync-suite-events.mjs' in line or 'scripts/plesk-sync-suite-events.sh' in line)]
    lab = [i for i, line in enumerate(lines) if not line.lstrip().startswith('#') and LAB in line and 'scripts/poll-purchase-invoices.mjs' in line]
    if len(staff) != 1 or len(lab) != 1:
        raise RuntimeError('Expected one Staff sync and one Lab invoice task; inspect schedules.')
    i = staff[0]
    if 'scripts/plesk-sync-suite-events.sh' not in lines[i]:
        updated, count = re.subn(r'/opt/plesk/node/\d+/bin/node scripts/sync-suite-events\.mjs', '/bin/bash scripts/plesk-sync-suite-events.sh', lines[i])
        if count != 1: raise RuntimeError('Unexpected Staff command; no changes applied.')
        lines[i] = updated
    i = lab[0]
    if not re.match(r'^2-59/5\s+', lines[i]):
        updated, count = re.subn(r'^\*/5\s+\*\s+\*\s+\*\s+\*\s+', '2-59/5 * * * * ', lines[i])
        if count != 1: raise RuntimeError('Unexpected Lab cadence; no changes applied.')
        lines[i] = updated
    monitors = [line for line in lines if not line.lstrip().startswith('#') and MONITOR in line]
    if len(monitors) > 1: raise RuntimeError('Duplicate database monitor tasks; inspect schedules.')
    if not monitors:
        lines += ['# Database pressure: private local warnings; no external notifications.', '* * * * * /opt/plesk/php/8.4/bin/php ' + MONITOR + ' > ' + BASE + '/private/database-pressure/last-run.log 2>&1 # perigallo-database-pressure']
    return '\n'.join(lines) + '\n'

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--apply', action='store_true')
    parser.add_argument('--backup-dir')
    args = parser.parse_args()
    original = subprocess.check_output(['crontab', '-l'], text=True)
    updated = plan(original)
    if args.apply and original != updated:
        if not args.backup_dir: raise RuntimeError('Private backup directory required.')
        backup = Path(args.backup_dir).resolve()
        if not str(backup).startswith(BASE + '/private/'):
            raise RuntimeError('Backup must be in the private subscription directory.')
        for required in [STAFF + '/scripts/plesk-sync-suite-events.sh', MONITOR]:
            if not Path(required).is_file(): raise RuntimeError('Deploy scheduled scripts first.')
        backup.mkdir(parents=True, exist_ok=True, mode=0o700)
        with (backup / 'crontab.before').open('x') as stream:
            stream.write(original)
        (backup / 'crontab.before').chmod(0o600)
        subprocess.run(['crontab', '-'], input=updated, text=True, check=True)
    print({'ok': True, 'changed': original != updated, 'applied': args.apply, 'staff': 'locked and delayed 12s', 'lab': 'same cadence, shifted two minutes', 'monitor': 'local private log each minute'})
