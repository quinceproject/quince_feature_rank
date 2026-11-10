# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the versioning follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-11-10

First public release.

### Added

- Ranking of product features per category: features filled in for the most
  products come first, features with one value for the whole category are left out.
- The product page shows features in the ranking of the category the product is
  viewed in, falling back to the product's default category and then to parent
  categories.
- Settings for the minimum fill rate, for leaving out features with one value and
  for recomputing on its own after catalogue changes.
- Per category decisions: always show a feature or leave it out.
- Back office screen with a preview of what a customer sees, the reasons behind the
  ranking and a "Recompute all categories" button.
- Polish translation.
- Declared dependency on Quince Engine.
