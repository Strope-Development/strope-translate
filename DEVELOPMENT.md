# Strope Translate – development notes

## Requirements

- PHP 7.4+ and [Composer](https://getcomposer.org/) (autoloader only, no runtime dependencies).
- Node.js 18.18+ and npm (asset build only).

## Assets

Sources and their built files:

| Sources | Built to |
| --- | --- |
| `js/src/*.js` (admin scripts, may `import` from `js/src/lib/`) | `js/build/` |
| `js/src/editor/*.js` (block editor plugin, blocks, sidebar, widget editor) | `js/build/` |
| `modules/*/js/*.js`, `integrations/*/js/*.js`, `services/*/js/*.js` | `js/build/` |
| `css/src/*.css`, `modules/*/css/*.css`, `modules/*/assets/css/*.css`, `services/*/css/*.css` | `css/build/` |

```bash
npm install
npm run build    # one-off build
npm run watch    # rebuild on change
```

Each source produces a readable `name.js` (loaded when `SCRIPT_DEBUG` is true) and a minified `name.min.js`.
Sources containing `import`/`export` are bundled; other sources are classic scripts copied and minified without any wrapper, so their globals keep working.

The files in `js/src/editor/` are pre-compiled bundles (the original JSX sources were not available): edit them directly, they are plain JavaScript using the global `wp.*` packages.

Never edit `js/build/` or `css/build/`: they are overwritten by every build. Built files are committed so that the plugin works without a build step.

Third-party code: every library bundled into a built file or copied into the plugin must be listed in `THIRD-PARTY-NOTICES.txt` with its copyright line and license. The build keeps the license comments of the sources in the minified scripts (`legalComments: 'inline'` in `bin/build.mjs`): don't turn that off.

## Machine translation engine

- `modules/machine-translation/vendor/bergamot-translator/` holds Mozilla's JavaScript loader of the engine and its WebAssembly binary, unmodified (MPL-2.0); the loader is loaded by the worker with `importScripts()`. They are not built. See its `README.md` for their origin and how to update them together with the version constants of `Strope_MT_Engine_Files`.
- The WebAssembly binary of the engine is shipped in the same folder (`bergamot-translator.wasm.zst`, unmodified, MPL-2.0); its SHA-256 hashes are in `Strope_MT_Engine_Files::ENGINE_FILE`. Nothing executable is downloaded at run time.
- The language models are not shipped: the site downloads them from the Firefox Remote Settings collection `translations-models-v2`, checks their SHA-256 hashes, and serves them from the uploads directory.
- The files are zstd compressed. The worker (`modules/machine-translation/js/mt-worker.js`) bundles the [fzstd](https://github.com/101arrowz/fzstd) decoder (MIT, dev dependency).

## Machine translation queue (WASM worker kit)

The queue is the [WASM worker kit](https://github.com/yari-dev/wasm-worker-kit), copied into `modules/machine-translation/lib/wasm-worker/` under the namespace `Strope_Translate\MT\Worker` and the text domain `strope-translate`. The kit version is in that folder's `VERSION` file. Don't edit the folder: change the kit, then copy it again from a checkout of the kit next to this plugin (and run its tests there first):

```bash
node ../wasm-worker-kit/tools/vendor.mjs --to modules/machine-translation/lib/wasm-worker --namespace "Strope_Translate\MT\Worker" --text-domain strope-translate
composer dump-autoload --no-dev
npm run build
```

- `Strope_Machine_Translation::kit()` is the queue: tables `{prefix}ww_stropemt_186fbc_*`, REST routes under `strope-mt-worker/v1`, WP-CLI commands under `wp strope-mt-worker` (`jobs`, `units`, `retry`, `metrics`, `workers`, `recover`, `prune`...).
- `Strope_MT_Task` (`translate-post`) is one post into one language: the plan checks the post then extracts its segments (the translation memory gives the known ones), each segment is a unit checked by `validate()`, `finalize()` saves the post as the user who asked for it. The affinity is the language route, so a batch has one route and a browser keeps the model it has loaded.
- A run (`Strope_MT_Queue`, table `strope_mt_runs`) is the group `run-<id>` of the queue: pause, resume, cancel and retry act on the group.
- The pages translate single texts with `js/lib/texts.js` (server prepare, engine in a direct worker, server restore): the Translations page (`js/mt-strings.js`) and the front-end editor. The front-end editor does not depend on the module: `modules/frontend-editor` fires `strope_frontend_editor_enqueue_scripts`, the module then adds `js/mt-frontend.js`, which gives the editor `window.stropeMtFrontend` (`canTranslate`, `isText`, `translate`, `translatePost`, `watchRun`).
- `js/mt-worker.js` is the kit runner (`runBatch()`: one engine call per batch); the engine itself is `js/lib/engine.js`. The Translations page uses the same worker through a direct worker of the kit, outside of the queue. The worker bundle is a classic worker (`workerType: 'classic'`), because the engine glue is loaded with `importScripts()`.

## Translations of the plugin

`languages/` holds the template (`strope-translate.pot`) and the translations shipped with the plugin. After changing or adding a translatable string, in PHP or JavaScript:

```bash
npm run i18n
```

It builds the assets, regenerates the template from the PHP files and from `js/build/*.js` (WordPress names the script translations after the built files, not the sources), merges it into every `.po`, and compiles the `.mo`, `.l10n.php` and script `.json` files. Translate the new entries in the `.po` files, run `npm run i18n:compile`, and commit everything in `languages/`.

Requires WP-CLI (`wp i18n`) and GNU gettext. `bin/i18n-fix-paths.mjs` makes the file references relative (on Windows, `wp i18n make-pot` writes the plugin header ones as absolute paths). The shipped translations are registered in `strope-translate.php`; language packs installed by WordPress.org in `wp-content/languages/plugins/` take priority for the PHP strings.

## PHP autoloader

Classes are autoloaded with a Composer classmap. After adding, renaming or removing a class:

```bash
composer dump-autoload --no-dev
```

## Checks and tests

The development tools live in `tests/` with their own `composer.json`, so that the root `vendor/` keeps only the plugin's autoloader:

```bash
composer --working-dir=tests install
composer --working-dir=tests check   # lint + PHP 7.4 compatibility + unit tests
```

- `lint`: every PHP file parses.
- `compat`: PHPCompatibilityWP for PHP 7.4 and later (`tests/phpcs-compat.xml.dist`).
- `test`: PHPUnit tests in `tests/unit/`, run without WordPress: `tests/bootstrap.php` provides the few WordPress functions the tested classes need.

The same checks run on GitHub for every push to `main` and every pull request (`.github/workflows/ci.yml`).

## Packaging

Files listed in `.distignore` are not shipped. Run `npm run build` and `composer dump-autoload --no-dev` before packaging.

## Releasing

The plugin is distributed as a Composer package (`strope/strope-translate`, type `wordpress-plugin`) from its git repository. Composer reads the version from git tags, so `composer.json` has no `version` field.

1. Update the version in `strope-translate.php` (plugin header and `STROPE_TRANSLATE_VERSION`), `readme.txt` (`Stable tag` and changelog), `package.json` and `package-lock.json`, then run `npm run i18n` (the template header carries the version).
   `STROPE_TRANSLATE_DATA_VERSION` is a different number: the format of the settings stored in the `strope_translate` option, which continues Polylang's numbering (the settings come from Polylang, and the upgrade routines of `install/upgrade.php` compare with it). Leave it alone, unless a release changes the stored settings: then add an upgrade routine for the new data version and raise the constant to it. Setting it to the release version would make every site run the old Polylang upgrades and refuse to update.
2. `npm ci && npm run build` and `composer dump-autoload --no-dev`, then commit the built files (`js/build`, `css/build`, `vendor/`).
3. Tag and push: `git tag -a v1.0.1 -m "Version 1.0.1" && git push origin main --tags`.

`vendor/` only contains the plugin's own classmap autoloader and is committed, so the plugin also works when copied or zipped manually. Don't run `composer install` or `composer update` in this folder: the only dependency, `composer/installers`, is needed by the sites installing the plugin, not by the plugin itself.
