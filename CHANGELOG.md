# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the versioning follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.0] - 2026-11-23

### Added

- **Filters on product lists**: the features from the ranking of all categories become
  filters in one template of the Faceted search module (ps_facetedsearch), chosen from
  the existing templates. Each feature is a checkbox with no limit of values; long
  lists are folded by the Quince theme ("Show more", theme 0.1.17). Order: features
  ranked in more categories first, then by average position, then by name. A feature
  left out in all categories is not added.
- The module sets the template for all categories of the shop and changes only its
  features. Subcategories, brand, price, availability and the other filters stay as
  set, in their order, before the features.
- "Recompute all categories" also updates the filters when **Set the filters from the
  ranking** is on. **Update the filters** does it without recomputing. Recomputing
  a single category does not touch the filters; the screen warns when the ranking is
  newer than the filters.
- **Restore the previous template** brings back the template from before the last
  update.

## [0.1.4] - 2026-11-17

### Added

- **Leave out in all categories** next to "Leave out": one click keeps a feature out
  of the product page and the product cards in every category, also categories added
  later. A decision made in a single category ("Always show" or "Leave out") still
  wins there. Saving recomputes the ranking of all categories.

## [0.1.3] - 2026-11-16

### Changed

- New module logo: the letter Q in the project gradient.
- Files in `src/` stop when opened directly instead of through PrestaShop.

## [0.1.2] - 2026-11-15

### Security

- Every back-office screen of the module checks the employee's permissions. Before,
  any logged-in employee, whatever their profile, could open and save the module's
  settings. The screens now belong to the module's own menu tab
  (`AdminQuinceFeatureRank`): grant it to a profile under **Advanced Parameters → Team →
  Permissions**. SuperAdmin needs nothing. A profile that had access through the hidden
  `AdminQuince` tab needs access to this tab now.

## [0.1.1] - 2026-11-10

### Changed

- Polish translation completed: the texts added since the previous translation are now translated.

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
