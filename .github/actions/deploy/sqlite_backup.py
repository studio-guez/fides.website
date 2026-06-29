"""Online-safe SQLite backup using the built-in sqlite3 module."""
import sqlite3
import sys

src, dst = sys.argv[1], sys.argv[2]
con = sqlite3.connect(src)
bak = sqlite3.connect(dst)
con.backup(bak)
bak.close()
con.close()
