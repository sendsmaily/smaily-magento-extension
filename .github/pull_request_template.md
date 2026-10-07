**What changes and why**

The problem this solves and what the change does about it. Pull requests are
squash-merged: this title and description become the commit on `master`.

**Checklist**

- [ ] CI is green (unit, integration, phpcs, phpstan, PHP 8.1 syntax, JS harnesses, release ZIP)
- [ ] `CHANGELOG.md` has an entry under the unreleased version (merchant-visible changes)
- [ ] `docs/USER_GUIDE.md` and the other docs describe the new behaviour
- [ ] New settings have `etc/config.xml` defaults (and a `LegacyConfigMapper` mapping when they replace a 2.8.x option)

**Version cut only**

- [ ] `composer.json` `version`, `Model/ModuleInfo.php` and the `CHANGELOG.md` heading name the new version
- [ ] After the merge: publish a GitHub release whose tag is the plain version (`3.0.0`, no `v`); a release candidate is published as a pre-release. The release workflow builds and attaches the ZIP and its `.sha256`.
