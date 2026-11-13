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

require_once __DIR__ . '/vendor/autoload.php';

use Quince\FeatureRank\Rank\Calculator;
use Quince\FeatureRank\Rank\Overrides;
use Quince\FeatureRank\Rank\Provider;
use Quince\FeatureRank\Rank\Storage;
use Quince\FeatureRank\Settings\RankSettings;

/**
 * Quince Feature Rank: które cechy produktu naprawdę coś mówią.
 *
 * Karta produktu pokazuje pod opisem kilka cech podstawowych. Bez tego modułu
 * bierze pierwsze w kolejności katalogu. Moduł zastępuje ją rankingiem
 * liczonym z tego, jak cechy rozkładają się w kategorii, w której produkt jest
 * oglądany: wybiera te, które w tym dziale faktycznie odróżniają jeden produkt
 * od drugiego.
 *
 * Ranking jest przeliczany w tle, bo na katalogu z dziesiątkami tysięcy produktów
 * to jest zadanie na sekundy, którego nie wolno liczyć przy otwieraniu karty.
 *
 * Moduł zgłasza się silnikowi hookiem `actionQuinceRegisterFeature`. Bez tego
 * modułu silnik pokazuje cechy podstawowe w kolejności katalogu.
 */
class Quince_Feature_Rank extends Module
{
    public function __construct()
    {
        $this->name = 'quince_feature_rank';
        $this->tab = 'front_office_features';
        $this->version = '0.1.1';
        $this->author = 'Quince Project';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];
        $this->dependencies = ['quince_engine'];

        // Jeden wpis w gałęzi „Quince project" w menu PrestaShopa, prowadzący
        // na ekran modułu. Podstron nie zgłaszamy: moduł ma je we własnym
        // bocznym pasku, a menu sklepu urosłoby wtedy o kilkanaście pozycji.
        // Kolejność wpisów w gałęzi ustala silnik.
        $this->tabs = [
            [
                'class_name' => 'AdminQuinceFeatureRank',
                'visible' => true,
                'name' => 'Feature rank',
                'parent_class_name' => 'AdminQuinceProject',
                'route_name' => 'quince_feature_rank_settings',
                'wording' => 'Feature rank',
                'wording_domain' => 'Modules.Quincefeaturerank.Admin',
            ],
        ];

        parent::__construct();

        $this->displayName = $this->trans('Quince Feature Rank', [], 'Modules.Quincefeaturerank.Admin');
        $this->description = $this->trans(
            'Picks the product features worth showing on the product page, based on how they are spread across the category being browsed.',
            [],
            'Modules.Quincefeaturerank.Admin'
        );
        $this->confirmUninstall = $this->trans(
            'Uninstalling removes the computed ranking. Key features will fall back to catalogue order.',
            [],
            'Modules.Quincefeaturerank.Admin'
        );
    }

    public function install(): bool
    {
        return parent::install()
            && $this->installDb()
            && $this->registerHook([
                'actionQuinceRegisterFeature',
                // Zmiany w katalogu unieważniają ranking kategorii, do której
                // produkt należy. Same przeliczenie robi się później, leniwie.
                'actionProductAdd',
                'actionProductUpdate',
                'actionProductDelete',
                'actionProductSave',
            ]);
    }

    public function uninstall(): bool
    {
        return $this->uninstallDb() && parent::uninstall();
    }

    /**
     * Zgłoszenie do silnika.
     *
     * @return array<int,array<string,string>>
     */
    public function hookActionQuinceRegisterFeature(): array
    {
        return [[
            // Typ jest tu potrzebny, bo silnik z tej możliwości KORZYSTA: pyta
            // o kolejność cech przy składaniu strony produktu. Sama pozycja
            // w menu typu nie wymaga.
            'type' => \Quince\Engine\Feature\FeatureRegistry::TYPE_FEATURE_RANK,
            'id' => $this->name,
            'label' => $this->trans('Feature ranking', [], 'Modules.Quincefeaturerank.Admin'),
            'icon' => 'puzzle',
            'route' => 'quince_feature_rank_settings',
        ]];
    }

    /**
     * Nazwy cech produktu w kolejności rankingu: usługa dla silnika.
     *
     * Nazwy, nie identyfikatory: rdzeń podaje szablonowi `grouped_features`
     * bez `id_feature`, więc nazwa jest tam jedynym kluczem, po którym da się
     * cechy ustawić.
     *
     * @return string[]
     */
    public function nazwyCechWKolejnosci(int $idProduct, int $idShop, int $idLang): array
    {
        return (new Provider())->nazwy($idProduct, $idShop, $idLang);
    }

    /**
     * Zmiana produktu unieważnia ranking kategorii, w których on stoi.
     *
     * Cztery hooki, bo rdzeń woła je w różnych sytuacjach i żaden nie łapie
     * wszystkiego: zapis z panelu, zapis przez API, dodanie, skasowanie.
     * Powtórzone zaznaczenie tej samej kategorii nic nie kosztuje.
     *
     * @param array<string,mixed> $params
     */
    public function hookActionProductSave(array $params): void
    {
        $ustawienia = new RankSettings();

        if (!$ustawienia->samoczynnie()) {
            return;
        }

        $idProduct = (int) ($params['id_product'] ?? (is_object($params['product'] ?? null) ? $params['product']->id : 0));

        if ($idProduct <= 0) {
            return;
        }

        $wiersze = Db::getInstance()->executeS(
            'SELECT id_category FROM ' . _DB_PREFIX_ . 'category_product WHERE id_product = ' . $idProduct
        );

        $ustawienia->zaznaczDoPrzeliczenia(
            array_map(static fn (array $w): int => (int) $w['id_category'], is_array($wiersze) ? $wiersze : [])
        );
    }

    /** @param array<string,mixed> $params */
    public function hookActionProductAdd(array $params): void
    {
        $this->hookActionProductSave($params);
    }

    /** @param array<string,mixed> $params */
    public function hookActionProductUpdate(array $params): void
    {
        $this->hookActionProductSave($params);
    }

    /**
     * Skasowany produkt też zmienia statystykę kategorii, w której stał.
     *
     * Powiązania w `category_product` znikają razem z produktem, więc kategorie
     * czytamy z parametrów hooka, a nie z bazy: po skasowaniu nie ma już czego
     * pytać.
     *
     * @param array<string,mixed> $params
     */
    public function hookActionProductDelete(array $params): void
    {
        $ustawienia = new RankSettings();
        $produkt = $params['product'] ?? null;

        if (!$ustawienia->samoczynnie() || !is_object($produkt)) {
            return;
        }

        $kategorie = method_exists($produkt, 'getCategories') ? $produkt->getCategories() : [];

        $ustawienia->zaznaczDoPrzeliczenia(array_map('intval', is_array($kategorie) ? $kategorie : []));
    }

    /** Przelicza ranking jednej kategorii. */
    public function przeliczKategorie(int $idCategory, int $idShop): void
    {
        (new Storage())->zapisz($idCategory, $idShop, (new Calculator())->dlaKategorii($idCategory, $idShop));
    }

    /**
     * Przelicza wszystkie kategorie sklepu.
     *
     * Kategorie bez wyniku (za mało produktów, żadna cecha nie przeszła progu
     * pokrycia) zostają w tabeli puste. To poprawny stan, bo `Provider`
     * schodzi wtedy do kategorii nadrzędnej.
     */
    public function przeliczSklep(int $idShop): int
    {
        $kalkulator = new Calculator();
        $storage = new Storage();
        $policzone = 0;

        foreach ($this->kategorieSklepu($idShop) as $idCategory) {
            $storage->zapisz($idCategory, $idShop, $kalkulator->dlaKategorii($idCategory, $idShop));
            ++$policzone;
        }

        return $policzone;
    }

    /**
     * Kategorie sklepu bez korzenia. Korzeń zawiera wszystko, więc jego ranking
     * jest rankingiem całego katalogu i nie odróżnia niczego od niczego.
     *
     * @return int[]
     */
    private function kategorieSklepu(int $idShop): array
    {
        $sql = 'SELECT c.id_category
                FROM ' . _DB_PREFIX_ . 'category c
                JOIN ' . _DB_PREFIX_ . 'category_shop cs
                  ON cs.id_category = c.id_category AND cs.id_shop = ' . $idShop . '
                WHERE c.active = 1 AND c.is_root_category = 0 AND c.id_parent > 0';

        $wynik = Db::getInstance()->executeS($sql);

        if (!is_array($wynik)) {
            return [];
        }

        return array_map(static fn (array $w): int => (int) $w['id_category'], $wynik);
    }

    private function installDb(): bool
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . Storage::TABELA . '` (
            `id_category` INT(11) UNSIGNED NOT NULL,
            `id_shop` INT(11) UNSIGNED NOT NULL,
            `id_feature` INT(11) UNSIGNED NOT NULL,
            `coverage` DECIMAL(6, 4) NOT NULL,
            `resolution` DECIMAL(6, 4) NOT NULL,
            `score` DECIMAL(6, 4) NOT NULL,
            `position` SMALLINT(5) UNSIGNED NOT NULL,
            `computed_at` DATETIME NOT NULL,
            PRIMARY KEY (`id_category`, `id_shop`, `id_feature`),
            INDEX `idx_odczyt` (`id_category`, `id_shop`, `position`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        return (bool) Db::getInstance()->execute($sql) && Overrides::installTable();
    }

    private function uninstallDb(): bool
    {
        return (bool) Db::getInstance()->execute(
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . Storage::TABELA . '`'
        ) && Overrides::uninstallTable();
    }

    /**
     * Przycisk „Konfiguruj" w menedżerze modułów.
     *
     * PrestaShop pokazuje go po samym istnieniu tej metody
     * (`ModuleRepository::__construct`, `method_exists($tmpModule, 'getContent')`),
     * a my przenosimy sprzedawcę na ekran modułu w panelu.
     */
    public function getContent(): void
    {
        \Tools::redirectAdmin($this->get('router')->generate('quince_feature_rank_settings'));
    }

}
