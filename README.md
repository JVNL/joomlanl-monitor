# joomlanl-monitor

*[Lees dit in het Nederlands](README.nl.md)*

Monitoring tool for Joomla websites: checks online status, installed extensions, and known exploits.

## Installing or updating

Every [release](../../releases) provides two kinds of files:

- **Source code (zip)** — a complete snapshot of the entire tool as it was at that release. Use this for a fresh install, or if you'd rather replace everything at once instead of updating step by step.
- **`update-vX.zip`** — contains *only* the files that changed since the previous release. Use this if you already have the previous version running and just want to update it.

### Important: updates are cumulative, not skippable

Each `update-vX.zip` only contains what changed compared to the *immediately preceding* version — not compared to whatever version you happen to be running.

If you're several versions behind, you need to apply each update in order:

- v1.18 -> apply update-v1.19.zip -> now on v1.19
- v1.19 -> apply update-v1.20.zip -> now on v1.20
- v1.20 -> apply update-v1.21.zip -> now on v1.21

Grabbing only the latest `update-vX.zip` while running an older version will leave out changes from the versions in between.

**Alternative:** instead of applying multiple updates in sequence, you can download the **Source code (zip)** of the latest release and use it as a fresh install/overwrite instead. This gives the same end result in one step. Note: if any release included a database migration (a script like `auto_migratie.php`), make sure to still run that after overwriting the files — copying files alone won't apply database changes.

### What's excluded

`config.php`, `geheime_sleutel.php` and `installatie.voltooid` are never part of these packages — they're specific to your own installation and are created by the installer (`installeer.php`).
