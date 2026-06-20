# Contributing a translation

PizzaVote's interface is fully translatable. Adding a new language takes **one new JSON
file and no PHP knowledge** — probably the easiest PR you can make to this repo.

## Currently supported

- 🇩🇪 German (`lang/de.json`) — default/fallback
- 🇬🇧 English (`lang/en.json`)

Missing your language? Pick it up below.

## How it works

- `i18n.php` defines `t(string $key, array $vars = []): string`, which looks up `$key`
  in `lang/<APP_LANG>.json` (the language set in `config.php`) and falls back to
  `lang/de.json` if a key or file is missing.
- Every visible label, button, message, and placeholder in the app goes through `t()` —
  there are **no hardcoded strings** left in `index.php` or `backend/index.php`.
- **Not translated**: pizza names, descriptions, and order comments. Those come from
  the database (entered by the admin/participants) and stay exactly as typed, in
  whatever language they were written.

## Steps

1. **Copy the reference file:**
   ```bash
   cd www
   cp lang/en.json lang/<your-language-code>.json
   ```
   Use a short, lowercase code (`fr`, `es`, `it`, `pl`, …) — ideally an
   [ISO 639-1](https://en.wikipedia.org/wiki/List_of_ISO_639_language_codes) code.

2. **Translate every value, not the keys.** Keys stay exactly as-is (e.g.
   `"front.welcome.title"`), only the right-hand string changes:
   ```json
   "front.welcome.title": "Welcome!"
   ```
   becomes, for example:
   ```json
   "front.welcome.title": "Bienvenue !"
   ```

3. **Keep `{placeholders}` intact.** Some strings contain `{name}`, `{n}`, `{deadline}`,
   `{ip}`, `{error}`, or `{max}` — these get substituted with real values at runtime.
   Don't translate or remove the braces/word inside them:
   ```json
   "front.order.question": "What'll it be, {name}?"
   ```

4. **Check the file is complete and valid.** Every key from `lang/en.json` must exist in
   your new file (130 keys as of this writing). Quick check with PHP:
   ```bash
   php -r '
   $ref = json_decode(file_get_contents("lang/en.json"), true);
   $new = json_decode(file_get_contents("lang/<your-code>.json"), true);
   if ($new === null) exit("Invalid JSON\n");
   $missing = array_diff(array_keys($ref), array_keys($new));
   echo $missing ? "Missing keys: " . implode(", ", $missing) . "\n" : "All keys present \xe2\x9c\x93\n";
   '
   ```
   Any plain JSON validator works too if you don't have PHP installed — the key-parity
   check above is the only project-specific part.

5. **Test it locally.** Temporarily set
   ```php
   define('APP_LANG', '<your-code>');
   ```
   in `config.php`, then run
   ```bash
   php -d extension=pdo_sqlite -S 0.0.0.0:8000
   ```
   from inside `www/` and click through the frontend (`/`) and the admin panel
   (`/backend/`) to make sure nothing looks cut off or untranslated. Revert the
   `APP_LANG` change before committing — `de` stays the project default.

6. **Open a pull request** with just the new `lang/<code>.json` file. That's it — no
   other files need to change.

## A note on longer/shorter translations

Some buttons and badges are tight on space (sidebar nav, table headers, the order
status badge). If your language tends to run longer than English/German, that's fine —
just resize the browser window down to mobile width while testing in step 5 to make
sure nothing visibly breaks.

## Add yourself as a translator

Feel free to add a line to the "Currently supported" list above in the same PR, e.g.:

```
- 🇫🇷 French (`lang/fr.json`) — by @yourhandle
```
