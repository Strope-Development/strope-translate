=== Strope Translate ===
Contributors: yariwp
Tags: multilingual, translation, language switcher, machine translation, bilingual
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Run your WordPress site in as many languages as you like, with private machine translation that runs in your own browser.

== Description ==

The most comprehensive free translation plugin for WordPress.

Each language gets its own version of every post, page, menu and template, linked together so visitors can switch between them and search engines know which page is which. You keep using the WordPress editor you know; Strope Translate adds a language to everything and shows you what still needs translating. Add two languages or twenty: languages are stored the way WordPress stores categories, so your site stays fast.

= Translate everything on your site =

* Posts, pages, media, categories, tags, and any custom post type or taxonomy, including right-to-left languages.
* Template parts, patterns and navigation menus in the site editor, and classic menus and widgets in older themes.
* Texts that come from your theme and plugins: Strope Translate scans for them, so you can translate or reword any of them without touching a .po file. Untouched texts cost nothing at runtime.
* URL slugs, either translated per language or shared by all of them, and the bases of categories, authors and custom post types.
* WooCommerce products, categories, tags and attributes. Prices, stock, SKUs and variations stay in sync across languages, and the shop, cart, checkout and customer emails follow the visitor's language.

= See at a glance what needs work =

* Every post list shows, per language, whether a translation exists, is out of date because the original changed, or still needs a review.
* A dashboard widget sums it up per language, with the number of words left to translate.
* Choose what visitors see when a translation is missing: the original content, with correct canonical links, hreflang tags and sitemaps, while they stay in their own language.

= Translate faster =

* Create a translation from the block editor sidebar, starting from a copy of the original, with its categories, tags and custom fields already filled in.
* Edit translations straight on the page: click a text on the front end and change it in place. Fields from ACF and Secure Custom Fields work too.
* Keep chosen parts of a post in sync across its translations, one post at a time or in bulk.
* Send work to a translation agency as XLIFF or PO files and import their files back.

= Machine translation with no external API =

Turn on machine translation and Strope Translate translates whole posts and pages for you, in bulk and in the background. The work is done by Bergamot, the engine behind Firefox Translations, inside the browsers of your editors. There is no account to create and no per-word bill, and your text never leaves your site.

A glossary keeps your own terms consistent, brand names can be left untranslated, and every result is saved as a draft marked for review. Translations written by a person are never overwritten.

= Work as a team =

* Give someone the Translator role and pick the languages they work in: they can only edit content and strings in those languages.
* Send posts out as translation jobs with word counts and due dates. Translators are notified by email and find their work in one list.

= Let visitors choose their language =

* Language switchers as a block, a navigation block, a classic widget, a menu item or the `[strope_language_switcher]` shortcode.
* An optional floating switcher, and a notice that tells visitors a page is also available in their language.
* Use a language folder in the URL (`/fr/`), or a subdomain or separate domain per language, with login that carries over between domains.
* Show or hide individual blocks per language in content that all languages share, like templates and synced patterns.
* Optional link correction: links between your own posts, pages and categories automatically point to the right language, and keep working when a page moves.

= Works with your other plugins =

Strope Translate supports WooCommerce, Yoast SEO, ACF, The Events Calendar, Custom Post Type UI, Beaver Builder, Divi, Admin Columns and more. Themes and plugins that ship a `wpml-config.xml` file or use the WPML API work as well.

= Coming from Polylang? =

Strope Translate started as a fork of Polylang. It can import an existing Polylang site (version 3.4 or later) in one click: languages, translations, string translations, settings, language switchers, widgets and user preferences. An optional compatibility layer keeps themes and plugins that call Polylang's functions (`pll_*()`, `PLL()`) working.

= Credits =

Strope Translate is a fork of [Polylang](https://wordpress.org/plugins/polylang/) by WP SYNTEX and Frédéric Demarle, released under the same license (GPLv3 or later). Thanks to them and to everyone who contributed to Polylang. Strope Translate is not affiliated with or endorsed by WP SYNTEX.

Machine translation uses [Bergamot](https://github.com/mozilla/translations), the engine of Firefox Translations by Mozilla (MPL 2.0), and [fzstd](https://github.com/101arrowz/fzstd) (MIT). Most flags come from [famfamfam](http://famfamfam.com/) and are in the public domain. All third-party notices are in `THIRD-PARTY-NOTICES.txt`.

== Installation ==

1. You need WordPress 6.5 or newer and PHP 7.4 or newer.
1. Deactivate any other multilingual plugin first: two of them on one site will get in each other's way. Moving from Polylang? See the FAQ below.
1. Install Strope Translate from Plugins > Add New and activate it.
1. A short setup wizard opens. Pick your languages and you are ready to translate.

== Frequently Asked Questions ==

= I am switching from Polylang. What happens to my data? =

Back up your database, deactivate Polylang, then activate Strope Translate. It detects the existing data and stays inactive until you click "Import from Polylang" in the admin notice (or run `wp strope import-polylang`). Languages and translations are kept, and the other data are converted in place. The legacy API compatibility module is activated so that themes and plugins calling `pll_*()` functions keep working. Both plugins can't be active at the same time.

= Can it translate my content automatically? =

Yes, optionally, without any translation service. Activate Machine translation in Languages > Settings, install the language models in Languages > Machine translation (about 25 MB per direction, downloaded once from Mozilla), then select posts and use the "Machine translate" bulk action, or translate all the content missing a translation at once.

The translation is done in your browser (and the browsers of the other editors who keep an admin tab open) by Bergamot, the open source engine of Firefox Translations. Your server only hands out the work and saves the results: the translated posts are created as drafts, with a revision, and marked as needing a review in the translation status. Translations written or edited by a person are never replaced. Words like brand names can be excluded from translation, and the glossary (Languages > Machine translation) gives the translation to use for your own terms, e.g. "basket" rather than "cart". Languages other than English are translated through English.

Translations can of course still be written by you or your translators, in the editor, on the front end, or with XLIFF/PO files exchanged with a translation agency.

= How do translators work? =

Give a user the Translator role (or any role) and pick their translation languages on their profile: they can then only edit and translate content and strings in those languages. Send posts to translation with the "Send to translation" bulk action; translators find them in Languages > Translation jobs and are notified by email.

= Does it work with WooCommerce? =

Yes. Products, product categories, tags and attributes are translatable, product data (prices, stock, SKU, variations) is kept synchronized between translations, and the cart, checkout, WooCommerce pages and customer emails follow the language. Multi-currency is not included.

= Where is my data stored? =

Languages and translation links are stored as WordPress taxonomies. Settings are stored in the `strope_translate` option, theme and plugin strings translations in dedicated database tables. No content of your site is sent to a third party, see "External services" below. The machine translation only downloads the public index and the language model files of Firefox Translations from Mozilla, into `wp-content/uploads/strope-translate/models`; the translation engine itself is part of the plugin.

= How do I remove all data when uninstalling? =

Add `define( 'STROPE_REMOVE_ALL_DATA', true );` to your `wp-config.php` before deleting the plugin.

== External services ==

Strope Translate works without any external service. Two optional features connect to one:

= Firefox Translations language models (Mozilla) =

Only if you activate the optional machine translation. The plugin then downloads, from Mozilla's Firefox Remote Settings service, the public list of available language models (refreshed at most once a day while the feature is used) and the model files of the language pairs an administrator chooses to install. These requests come from your server and contain nothing but the request itself (your server's IP address and a WordPress user agent): no content of your site, no user data. The translation itself happens in the browsers of your editors; your texts are never sent to Mozilla or anyone else.

Service provided by Mozilla: [terms of use](https://www.mozilla.org/about/legal/terms/mozilla/), [privacy policy](https://www.mozilla.org/privacy/).

= WordPress.org language packs =

When you add a language, WordPress downloads its language pack from WordPress.org, as it does when you change the site language in Settings > General. See the [WordPress.org privacy policy](https://wordpress.org/about/privacy/).

== Screenshots ==

1. Each post shows its language and translations in the block editor sidebar.
2. The posts list shows which translations exist, per language.
3. Machine translation runs in the editors' browsers, with on-device models: no content is sent to an outside service.
4. Translate site strings, with empty fields filled by machine translation.
5. The front-end editor: translate a page while looking at it.
6. Translation jobs assign work to translators and track its progress.
7. The Translation status dashboard widget shows coverage, outdated translations and words left to translate.
8. Manage the languages of your site.
9. The language switcher block in the navigation, in the site editor.
10. The language switcher on the front end.
11. Strings found in the theme and plugins, ready to translate.
12. The media library, with a language for each item.
13. The setup wizard.

== Changelog ==

= 1.0.0 =

* First public release.
