import concurrent.futures, datetime, importlib.util, json, pathlib, sqlite3, tempfile, unittest
from zoneinfo import ZoneInfo

spec=importlib.util.spec_from_file_location('guard',pathlib.Path(__file__).with_name('guard.py'))
guard=importlib.util.module_from_spec(spec);spec.loader.exec_module(guard)
NOW=datetime.datetime(2026,11,15,12,tzinfo=ZoneInfo('Europe/Madrid'))
def value(app='suite',a=5000,b=1000):
    return dict(app=app,model='gpt-4.1-mini',input_tokens_bound=a,output_tokens_max=b)

class LedgerTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.db=self.temp.name+'/ledger.sqlite';guard.initialize(self.db)
    def tearDown(self):
        self.temp.cleanup()
    def test_four_applications_share_exactly_ten_cents(self):
        for app in ['suite','lab','instagram','assistant','suite']:
            guard.reserve(self.db,value(app),NOW)
        with self.assertRaises(ValueError):guard.reserve(self.db,value(),NOW)
        with sqlite3.connect(self.db) as db:
            self.assertEqual(db.execute('SELECT SUM(eur_micros) FROM reservations').fetchone()[0],100000)
    def test_concurrent_process_connections_do_not_overspend(self):
        def attempt(i):
            try:guard.reserve(self.db,value(['suite','lab','instagram','assistant'][i%4]),NOW);return True
            except ValueError:return False
        with concurrent.futures.ThreadPoolExecutor(max_workers=16) as pool:
            self.assertEqual(sum(pool.map(attempt,range(60))),5)
    def test_restart_and_ambiguous_requests_do_not_refund(self):
        for _ in range(5):guard.reserve(self.db,value(),NOW)
        guard.initialize(self.db)
        with self.assertRaises(ValueError):guard.reserve(self.db,value(),NOW)
    def test_reset_is_midnight_madrid_not_utc(self):
        for _ in range(5):guard.reserve(self.db,value(),NOW)
        with self.assertRaises(ValueError):guard.reserve(self.db,value(),datetime.datetime.fromisoformat('2026-11-15T22:59:59+00:00'))
        self.assertEqual(guard.reserve(self.db,value(),datetime.datetime.fromisoformat('2026-11-15T23:00:00+00:00'))['day'],'2026-11-16')
    def test_unknown_model_expired_price_and_bad_numbers_fail_closed(self):
        for invalid in [dict(value(),model='gpt-6-astra'),dict(value(),input_tokens_bound=-1),dict(value(),output_tokens_max=True),dict(value(),app='arbitrary'),dict(value(),extra=1)]:
            with self.assertRaises(ValueError):guard.reserve(self.db,invalid,NOW)
        with self.assertRaises(ValueError):guard.reserve(self.db,value(),NOW.replace(month=12,day=1))
    def test_oversized_file_does_not_consume_budget_or_send_it(self):
        with self.assertRaises(ValueError):guard.reserve(self.db,value(a=1200000,b=4096),NOW)
        self.assertEqual(guard.reserve(self.db,value(),NOW)['remaining_eur_micros'],80000)
    def test_corrupted_ledger_never_silently_resets(self):
        pathlib.Path(self.db).write_bytes(b'corrupted database')
        with self.assertRaises(sqlite3.Error):guard.reserve(self.db,value(),NOW)

if __name__=='__main__':unittest.main()
