<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

use Quince\FeatureRank\Facets\FacetTemplate;

/**
 * 0.2.0: filtry na listingu z rankingu cech. Tabela na kopię szablonu
 * filtrów sprzed ostatniego zapisu modułu.
 *
 * @param Module $module
 */
function upgrade_module_0_2_0($module): bool
{
    return FacetTemplate::installTable();
}
