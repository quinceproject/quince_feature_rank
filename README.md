# Quince Feature Rank

**Picks the product features worth showing on the product page, for PrestaShop 9.2.**

Under the short description the product page shows a few product features. Without
this module they come in catalogue order, which is the order in which someone typed
them in. Quince Feature Rank orders them by what actually tells products apart in the
category the customer is browsing: the same feature can be the first thing to show
in one category and say nothing in another.

> Quince Feature Rank is a free, open-source module (OSL-3.0). There is nothing to
> buy. If it saves you time, you can support development, see
> [Supporting the project](#supporting-the-project).

---

## Features

- **Ranking per category.** In every category, features filled in for the most
  products come first. A feature that has the same value for every product in the
  category is left out, because it does not tell this product apart from the next.
- **The category the customer is in.** The product page uses the ranking of the
  category the product is viewed in. When that cannot be known (a search result, a
  direct link), it uses the product's default category, and a category without any
  features hands over to its parent.
- **Nothing disappears.** Features outside the ranking are not removed. They follow
  the ranked ones, in catalogue order.
- **Two conditions.** A minimum share of products that must have a feature filled
  in (60% by default), and whether features with one value are left out (on by
  default). Changing either recomputes the ranking for the whole shop.
- **Your decisions win.** In each category, any feature can be set to always show or
  to be left out, whatever the ranking says.
- **Preview and reasons.** The back office screen shows what a customer sees for a
  product in the chosen category, and for every feature its values in the category,
  how many products have it, and whether it makes the page.
- **Recomputing without slowing the shop.** The ranking is stored, not computed on
  every page view. After catalogue changes a category can be marked to recompute on
  its own, on the next visit to one of its products (off by default, so an import
  does not trigger work for every product). "Recompute all categories" does it by
  hand.
- **Multilingual.** English base with a full Polish translation, on PrestaShop's
  standard translation system.

## Requirements

| Requirement | Version |
|-------------|---------|
| PrestaShop  | 9.2 (tested on 9.2.0) |
| PHP         | 8.4 (tested), 8.1 or newer required |
| Quince Engine | required |

Quince Engine builds the product page and asks this module for the feature order. It
also hosts the module's settings screen. The module declares it as a dependency, so
PrestaShop refuses to install Quince Feature Rank without it. Without this module,
Quince Engine shows features in catalogue order.

## Installation

The release ZIP already contains everything the module needs. Composer and the
command line are not required.

**From the back office**

1. Download the latest **`quince_feature_rank.zip`** from the
   [**Releases**](https://github.com/quinceproject/quince_feature_rank/releases) page.
2. In the back office go to **Modules → Module Manager → Upload a module** and
   choose the ZIP. PrestaShop installs it.

**By copying the files to the server**

1. Unpack the release ZIP. It contains a `quince_feature_rank` folder.
2. Upload that folder to the `modules/` directory of the shop, for example by FTP.
3. In **Modules → Module Manager** find Quince Feature Rank and click **Install**.

**From Quince Engine** *(coming soon)*

The module list in Quince Engine will install the module straight from its release,
without downloading anything by hand.

**With Composer** *(coming soon)*

Installing with `composer require quinceproject/feature-rank` will work once the
package is published on Packagist.

> ⚠️ **Do not** use the green **Code → Download ZIP** button on this page. That
> archive contains the source only and is **missing the `vendor/` folder**, so the
> module will not run. Always install from a
> [**Release**](https://github.com/quinceproject/quince_feature_rank/releases).

Uninstalling the module removes the computed ranking and your per category
decisions. Key features fall back to catalogue order.

## Configuration

Open **Quince project → Feature rank** in the back office menu, or click
**Configure** next to the module in the Module Manager.

1. Click **Recompute all categories** once after installing.
2. Set the minimum fill rate and whether features with one value are left out.
3. Pick a category to see what a customer sees there and why. Mark features to
   always show or to leave out, and save.
4. Switch on **Recompute on its own** if the catalogue changes often and you want
   rankings to follow without recomputing by hand.

## Architecture

- **`Calculator`** computes the ranking of one category from the features of its
  products.
- **`Storage`** keeps the ranking in its own table; **`Overrides`** keeps the per
  category decisions.
- **`Provider`** answers Quince Engine with the feature order for a product.
- The module announces itself to Quince Engine through the
  `actionQuinceRegisterFeature` hook and marks categories for recomputing on the
  product add, update, save and delete hooks.
- **Symfony controller and Twig** for the settings screen, inside the Quince Engine
  shell.
- **Settings** are stored in PrestaShop configuration under the `QUINCE_FRANK_`
  prefix.

## Development / building from source

This repository tracks **source only**. The `vendor/` directory is git-ignored, so a
`git clone` is **not** directly installable. **Start with `composer install`**: the
development environment mounts module directories straight from disk, and without
that step a fresh clone gives a broken back office.

```bash
git clone https://github.com/quinceproject/quince_feature_rank.git
cd quince_feature_rank
composer install
```

The folder must be called `quince_feature_rank`, the same as the module. A clone of
this repository already has that name.

To build an installable archive, zip the `quince_feature_rank/` folder **with its
`vendor/` directory included**, leaving out `.git/` and `.gitignore`.

## License

Code: [Open Software License 3.0 (OSL-3.0)](LICENSE). It is a reciprocal licence:
whoever modifies and distributes this code has to publish the changes under the same
licence and keep the original copyright and author notices.

**The name "Quince" is not covered by the code licence.** A fork may use the code; it
may not use the name.

© 2026 Marcin Gajewski

## Supporting the project

Quince modules are free downloads and there is nothing to buy. If the module is useful
to you, you can support its development by buying me a coffee:

**[buymeacoffee.com/marcingajewski](https://buymeacoffee.com/marcingajewski)**
