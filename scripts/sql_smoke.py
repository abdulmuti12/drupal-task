#!/usr/bin/env python3
"""Check SQL D1/D2 logic using SQLite fixtures (NOT MySQL performance testing)."""
from pathlib import Path
import sqlite3
import re

md = (Path(__file__).resolve().parents[1] / 'SQL_SOLUTIONS.md').read_text()
blocks = re.findall(r'```sql\n(.*?)\n```', md, flags=re.S)
assert len(blocks) >= 2, 'SQL solution blocks missing'
conn = sqlite3.connect(':memory:')
conn.execute('''CREATE TABLE historical_prices (
 symbol VARCHAR(20) NOT NULL,
 trade_date DATE NOT NULL,
 open NUMERIC, high NUMERIC, low NUMERIC, close NUMERIC, volume INTEGER,
 PRIMARY KEY (symbol, trade_date))''')
conn.executemany('INSERT INTO historical_prices(symbol,trade_date,close,volume) VALUES(?,?,?,?)', [
 ('AAPL','2026-05-29',100,1000),
 ('AAPL','2026-06-01',110,1200),
 ('AAPL','2026-06-03',121,1300),
 ('MSFT','2026-05-29',200,1000),
 ('MSFT','2026-06-01',210,1100),
 # MSFT has no row on June 3: a detected gap.
 ('MSFT','2026-06-04',220,1200),
 ('AAPL','2026-06-04',115,1300),
])

first = conn.execute(blocks[0], {'trade_date':'2026-06-01'}).fetchall()
assert {r[0]: (r[2], round(r[3],4)) for r in first} == {
 'AAPL': (100,10.0), 'MSFT': (200,5.0)
}, first
# Date - 1 would be a Sunday; prior available date is Friday 29 May.

missing = conn.execute(blocks[1]).fetchall()
assert ('MSFT','2026-06-03') in missing, missing
assert ('AAPL','2026-06-03') not in missing, missing
print('PASS: D1 previous-trading-row and D2 June gap semantics on SQLite fixtures.')
