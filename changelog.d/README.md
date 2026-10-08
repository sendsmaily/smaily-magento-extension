# Changelog fragments

A pull request with a change a merchant can see adds **one file** here,
`changelog.d/<ISSUE>.md` — the issue key it fixes, for example
`changelog.d/PRO-1234.md`. A pull request without an issue key names the
fragment after its branch (`changelog.d/fix-import-card-label.md`). The
pull request does not edit `CHANGELOG.md`.

## Why

Every pull request used to add its bullet at the top of `CHANGELOG.md`'s
"Changes since …" list, so any two pull requests open at the same time
conflicted on the same lines, and the second one to merge had to be rebased
by hand. A fragment is a file no other pull request touches.

## Format

The file holds exactly the bullet the pull request would have added to
`CHANGELOG.md`: one Markdown bullet, `- ` on its first line, nothing else —
no heading, no second bullet. It follows the rules every CHANGELOG bullet
follows:

- Write for the merchant: what they see or can do now, with admin labels in
  bold exactly as the admin shows them (`i18n/en_US.csv`), not how the code
  does it.
- A change that adds a catalog field, or corrects what one holds, says that
  the products already sent change only through a catalog import, and tells
  the merchant to start one.
- A change a merchant cannot see (tests, tooling, refactors, internal docs)
  adds no fragment.

Example, `changelog.d/PRO-1234.md`:

```markdown
- The contacts import card (**Settings > Contacts**) states before you start it how many contacts the import sends from the website.
```

## The version cut

The version-cut pull request moves every fragment into `CHANGELOG.md`'s
"Changes since <previous version>" list, in the order the fragments landed on
`master`, oldest first, and deletes the fragments (this README stays).
`bin/collect-changelog.sh` prints the bullets in that order;
`bin/collect-changelog.sh -l` lists the files with their landing dates. The
cut may still reword a bullet by hand, and it merges or rewords a bullet that
a later change made untrue, as before.

This folder is not part of the package (`.gitattributes` export-ignores it).
