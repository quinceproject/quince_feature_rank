<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\FeatureRank\Facets;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Db;
use Module;
use Quince\FeatureRank\Rank\Overrides;
use Quince\FeatureRank\Rank\Storage;
use Tools;

/**
 * Cechy z rankingu jako filtry na listingu: jeden szablon ps_facetedsearch.
 *
 * Moduł prowadzi JEDEN szablon, wskazany przez sprzedawcę, przypisany do
 * wszystkich kategorii sklepu. Cechy w nim to suma rankingów wszystkich
 * kategorii. Działa to, bo ps_facetedsearch nie pokazuje filtra cechy, której
 * nie ma żaden produkt oglądanej kategorii.
 *
 * Specyfikacja: `quince/docs/32-feature-rank-filtry-facet.md`.
 */
final class FacetTemplate
{
    /** Kopia szablonu sprzed ostatniego zapisu, jeden wiersz na szablon. */
    public const TABELA_KOPII = 'quince_feature_rank_facets_backup';

    /** Prefiks kluczy filtrów cech w zserializowanym szablonie modułu filtrów. */
    private const CECHA = 'layered_selection_feat_';

    /** Czy moduł filtrów jest w sklepie i działa. */
    public function dostepny(): bool
    {
        return Module::isInstalled('ps_facetedsearch') && Module::isEnabled('ps_facetedsearch');
    }

    /**
     * Szablony modułu filtrów do wyboru na ekranie.
     *
     * @return array<int,array{id:int,name:string,categories:int}>
     */
    public function szablony(): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT id_layered_filter, name, n_categories FROM ' . _DB_PREFIX_ . 'layered_filter ORDER BY date_add DESC'
        );

        return array_map(static fn (array $w): array => [
            'id' => (int) $w['id_layered_filter'],
            'name' => (string) $w['name'],
            'categories' => (int) $w['n_categories'],
        ], is_array($wiersze) ? $wiersze : []);
    }

    public function istnieje(int $idSzablonu): bool
    {
        foreach ($this->szablony() as $szablon) {
            if ($szablon['id'] === $idSzablonu) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cechy, które trafią do szablonu, w kolejności filtrów.
     *
     * Kolejność: cecha obecna w rankingu większej liczby kategorii wyżej, przy
     * remisie ta z niższą średnią pozycją, potem nazwa. Cecha pominięta we
     * wszystkich kategoriach odpada; pominięta w jednej kategorii zostaje, jeśli
     * weszła do rankingu innej, bo tam jej filtr ma sens.
     *
     * @return array<int,array{id:int,name:string,categories:int,position:float,values:int}>
     */
    public function cechy(int $idShop, int $idLang): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT r.id_feature, COUNT(*) AS kategorii, AVG(r.position) AS pozycja, fl.name,
                    (SELECT COUNT(DISTINCT fp.id_feature_value)
                       FROM ' . _DB_PREFIX_ . 'feature_product fp
                       INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps
                           ON ps.id_product = fp.id_product AND ps.id_shop = ' . $idShop . '
                      WHERE fp.id_feature = r.id_feature) AS wartosci
             FROM ' . _DB_PREFIX_ . Storage::TABELA . ' r
             LEFT JOIN ' . _DB_PREFIX_ . 'feature_lang fl
                 ON fl.id_feature = r.id_feature AND fl.id_lang = ' . $idLang . '
             WHERE r.id_shop = ' . $idShop . '
             GROUP BY r.id_feature, fl.name'
        );

        $pominiete = array_flip((new Overrides())->pominieteWszedzie($idShop));
        $cechy = [];

        foreach (is_array($wiersze) ? $wiersze : [] as $w) {
            $id = (int) $w['id_feature'];

            if (isset($pominiete[$id])) {
                continue;
            }

            $cechy[] = [
                'id' => $id,
                'name' => (string) $w['name'],
                'categories' => (int) $w['kategorii'],
                'position' => round((float) $w['pozycja'], 1),
                'values' => (int) $w['wartosci'],
            ];
        }

        usort($cechy, static fn (array $a, array $b): int => [$b['categories'], $a['position'], $a['name']]
            <=> [$a['categories'], $b['position'], $b['name']]);

        return $cechy;
    }

    /** Ile cech ma sklep w ogóle: do zestawienia „X z Y” na ekranie. */
    public function wszystkichCech(int $idShop): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'feature_shop WHERE id_shop = ' . $idShop
        );
    }

    /**
     * Zapisuje cechy z rankingu w szablonie i przebudowuje przypisania filtrów.
     *
     * Zmienia tylko dwie rzeczy: listę kategorii (wszystkie kategorie sklepu,
     * bo szablon ze starymi identyfikatorami kategorii daje pustą tabelę
     * przypisań i filtry znikają w całym sklepie) oraz filtry cech. Filtry
     * inne niż cechy zostają tak, jak ustawił je sprzedawca, w jego kolejności,
     * przed cechami.
     *
     * Przed zapisem kopia szablonu, żeby „Przywróć poprzedni szablon” cofało
     * jedno kliknięcie.
     *
     * @return int liczba cech w szablonie
     */
    public function zapisz(int $idSzablonu, int $idShop, int $idLang): int
    {
        $wiersz = $this->wiersz($idSzablonu);

        if ($wiersz === null) {
            throw new \RuntimeException('Filter template not found: ' . $idSzablonu);
        }

        $dane = Tools::unSerialize((string) $wiersz['filters']);
        $dane = is_array($dane) ? $dane : [];

        $nowe = [];

        foreach ($dane as $klucz => $wartosc) {
            if (str_starts_with((string) $klucz, self::CECHA)) {
                continue;
            }

            $nowe[$klucz] = $wartosc;
        }

        $nowe['categories'] = $this->kategorieSklepu($idShop);

        // Pole wyboru i bez limitu wartości: lista rozwijana pozwala zaznaczyć
        // tylko jedną wartość, a limit modułu filtrów chowa wartości ponad niego
        // na stałe. Długie listy zwija motyw przyciskiem „Pokaż więcej”.
        $cechy = $this->cechy($idShop, $idLang);

        foreach ($cechy as $cecha) {
            $nowe[self::CECHA . $cecha['id']] = ['filter_type' => 0, 'filter_show_limit' => 0];
        }

        $this->zrobKopie($idSzablonu, $wiersz);
        $this->zapiszWiersz($idSzablonu, $nowe);

        return count($cechy);
    }

    /** Czy jest kopia do przywrócenia. Zwraca jej datę albo null. */
    public function kopiaZDnia(int $idSzablonu): ?string
    {
        $wartosc = Db::getInstance()->getValue(
            'SELECT saved_at FROM ' . _DB_PREFIX_ . self::TABELA_KOPII . ' WHERE id_layered_filter = ' . $idSzablonu
        );

        return $wartosc === false || $wartosc === null || $wartosc === '' ? null : (string) $wartosc;
    }

    /**
     * Przywraca szablon sprzed ostatniego zapisu, znak w znak, i przebudowuje
     * przypisania. Kopia znika: drugie kliknięcie nie ma czego cofać.
     */
    public function przywroc(int $idSzablonu): bool
    {
        $kopia = Db::getInstance()->getRow(
            'SELECT filters, n_categories FROM ' . _DB_PREFIX_ . self::TABELA_KOPII . ' WHERE id_layered_filter = ' . $idSzablonu
        );

        if (!is_array($kopia) || $this->wiersz($idSzablonu) === null) {
            return false;
        }

        Db::getInstance()->execute(
            'UPDATE ' . _DB_PREFIX_ . 'layered_filter
             SET filters = "' . pSQL((string) $kopia['filters'], true) . '",
                 n_categories = ' . (int) $kopia['n_categories'] . '
             WHERE id_layered_filter = ' . $idSzablonu
        );

        Db::getInstance()->execute(
            'DELETE FROM ' . _DB_PREFIX_ . self::TABELA_KOPII . ' WHERE id_layered_filter = ' . $idSzablonu
        );

        $this->przebuduj();

        return true;
    }

    /** @return array<string,mixed>|null */
    private function wiersz(int $idSzablonu): ?array
    {
        $wiersz = Db::getInstance()->getRow(
            'SELECT filters, n_categories FROM ' . _DB_PREFIX_ . 'layered_filter WHERE id_layered_filter = ' . $idSzablonu
        );

        return is_array($wiersz) ? $wiersz : null;
    }

    /** @param array<string,mixed> $wiersz */
    private function zrobKopie(int $idSzablonu, array $wiersz): void
    {
        $db = Db::getInstance();
        $tabela = _DB_PREFIX_ . self::TABELA_KOPII;

        $db->execute('DELETE FROM `' . $tabela . '` WHERE id_layered_filter = ' . $idSzablonu);
        $db->execute(
            'INSERT INTO `' . $tabela . '` (id_layered_filter, filters, n_categories, saved_at)
             VALUES (' . $idSzablonu . ', "' . pSQL((string) $wiersz['filters'], true) . '", '
            . (int) $wiersz['n_categories'] . ', "' . pSQL(date('Y-m-d H:i:s')) . '")'
        );
    }

    /**
     * Zapis szablonu tak, jak robi to sam moduł filtrów: zserializowana tablica
     * w `filters` i liczba kategorii w `n_categories`.
     *
     * @param array<string,mixed> $dane
     */
    private function zapiszWiersz(int $idSzablonu, array $dane): void
    {
        Db::getInstance()->execute(
            'UPDATE ' . _DB_PREFIX_ . 'layered_filter
             SET filters = "' . pSQL(serialize($dane), true) . '",
                 n_categories = ' . count($dane['categories']) . '
             WHERE id_layered_filter = ' . $idSzablonu
        );

        $this->przebuduj();
    }

    /**
     * Tabela przypisań filtrów do kategorii liczona od nowa ze wszystkich
     * szablonów. Funkcja modułu filtrów sama czyści też jego pamięć podręczną.
     */
    private function przebuduj(): void
    {
        $modul = Module::getInstanceByName('ps_facetedsearch');

        if ($modul && method_exists($modul, 'buildLayeredCategories')) {
            $modul->buildLayeredCategories();
        }
    }

    /**
     * Wszystkie kategorie sklepu bez korzenia drzewa.
     *
     * @return int[]
     */
    private function kategorieSklepu(int $idShop): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT id_category FROM ' . _DB_PREFIX_ . 'category_shop
             WHERE id_shop = ' . $idShop . ' AND id_category >= 2
             ORDER BY id_category'
        );

        return array_map(static fn (array $w): int => (int) $w['id_category'], is_array($wiersze) ? $wiersze : []);
    }

    public static function installTable(): bool
    {
        return (bool) Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABELA_KOPII . '` (
                `id_layered_filter` INT(11) UNSIGNED NOT NULL,
                `filters` LONGTEXT NOT NULL,
                `n_categories` INT(11) UNSIGNED NOT NULL,
                `saved_at` DATETIME NOT NULL,
                PRIMARY KEY (`id_layered_filter`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;'
        );
    }

    public static function uninstallTable(): bool
    {
        return (bool) Db::getInstance()->execute(
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::TABELA_KOPII . '`'
        );
    }
}
