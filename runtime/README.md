# PHP runtime (optional)

This folder is where the **portable PHP runtime** goes so the project runs
without a system-wide PHP install.

For the app to use it, the file `runtime/php/php.exe` must exist. This is
**not committed to git** (it is large). To set it up:

1. Download PHP 8 (x64) from <https://windows.php.net/download>.
2. Extract it so this path exists: `runtime/php/php.exe`.
3. Ensure `runtime/php/php.ini` enables: `pdo_sqlite`, `sqlite3`, `mbstring`.

Alternatively, install PHP and add it to your `PATH`; `start.bat` will then
find it automatically. Run `setup_check.bat` to confirm your setup.
