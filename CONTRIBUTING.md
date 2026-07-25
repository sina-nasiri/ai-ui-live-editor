# Contributing

Thanks for wanting to help. This project is small on purpose — it should stay
something a designer can install in two minutes and a developer can read in an
afternoon.

## Getting set up

```bash
git clone https://github.com/sina-nasiri/ai-ui-live-editor.git
cd ai-ui-live-editor
composer setup      # install, create .env, generate a key
php artisan serve
```

That is the whole setup. There is **no build step and no Node dependency** —
the front end is plain ES modules served from `public/assets/`. If you find
yourself reaching for a bundler, open an issue first; keeping the install
trivial is a feature, not an oversight.

## Before you open a pull request

```bash
composer test    # PHPUnit
composer lint    # Laravel Pint
```

Both run in CI on every PR.

## How the code is laid out

```
app/
  Http/Controllers/
    EditorController.php    page + proxy
    AiController.php        the four AI endpoints
  Services/Ai/              one class per provider, behind AiProvider
  Support/
    UrlGuard.php            SSRF defence
    PageSnapshot.php        turns a fetched page into a safe static snapshot
    CssGuard.php            filters CSS the model returns
public/assets/js/
  store.js                  state + the patch stack (undo/redo lives here)
  frame.js                  loading and element selection
  ai.js                     provider requests, applying changes
  audit.js                  the accessibility checks
  inspector.js              the direct-manipulation panel
```

## Two ideas worth understanding before changing things

**Edits are data, not DOM mutations.** An AI edit produces a list of CSS
declarations keyed to element ids, pushed onto a stack in `store.js` and
rendered into one stylesheet. Undo is popping the stack. Export is serialising
it. If you are tempted to mutate the DOM directly, check whether a patch would
do — it almost always will, and you get undo and export for free.

**The model never sees raw HTML in the normal path.** It sees an outline
(`outline.js`) with element ids and the styles that matter. That is what keeps
a request cheap and makes it impossible for the model to delete someone's copy.

## Adding an AI provider

1. Add a class in `app/Services/Ai/` extending `BaseProvider`.
2. Register it in `ProviderFactory::PROVIDERS`.
3. Add its config block in `config/editor.php`.
4. Add a test in `tests/Feature/AiEditTest.php` — fake the vendor's response
   shape and assert the editor gets the same normalised result as every other
   provider. That test is what proves the abstraction holds.

Nothing in the front end should need to change.

## What gets merged easily

- Bug fixes with a test that fails without them
- New accessibility checks in `audit.js` (client-side, no API cost)
- Better handling of real-world markup in `PageSnapshot`
- New AI providers

## What to discuss first

- Anything that adds a build step or a runtime dependency
- Changes to the security posture in `UrlGuard` or `PageSnapshot`
- Large UI restructures

## Reporting bugs

Include the URL you loaded if it is public — most bugs in this project are
"this specific site renders wrong", and that is much easier to fix with the
actual page.

Security issues go through [SECURITY.md](SECURITY.md), not the issue tracker.
