<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\FeatureRank\Rank;

use Category;
use Context;
use Db;
use Quince\FeatureRank\Settings\RankSettings;
use Tools;

/**
 * Odpowiada na pytanie: które cechy tego produktu pokazać i w jakiej kolejności.
 *
 * Kolejność bierze się z rankingu kategorii, W KTÓREJ PRODUKT JEST OGLĄDANY —
 * nie z domyślnej. Ten sam akumulator w dziale „Akumulatory" ma pokazać pojemność,
 * a w „Zestawach naprawczych" długość przewodu. Gdy kategorii nie da się ustalić
 * (wejście z wyszukiwarki, bezpośredni odnośnik), schodzimy do kategorii domyślnej
 * produktu — bo lepsza kolejność ustalona dla jakiegoś działu niż kolejność
 * przypadkowa z katalogu.
 */
final class Provider
{
    public function __construct(
        private readonly Storage $storage = new Storage(),
        private readonly RankSettings $ustawienia = new RankSettings()
    ) {
    }

    /**
     * Ustawia cechy produktu w kolejności rankingu i przycina do limitu.
     *
     * Cechy spoza rankingu nie znikają — lądują za nim, w swojej kolejności
     * z katalogu. Bez tego produkt z cechą, której kategoria jeszcze nie policzyła,
     * pokazywałby mniej wierszy niż sąsiedni, bez żadnego widocznego powodu.
     *
     * @param array<int,array<string,mixed>> $cechy `$product.grouped_features` z rdzenia
     *
     * @return array<int,array<string,mixed>>
     */
    public function ustaw(array $cechy, int $idProduct, int $idShop, int $limit): array
    {
        $kolejnosc = $this->kolejnosc($idProduct, $idShop);

        if ($kolejnosc !== []) {
            $nazwyCech = $this->nazwyCech($idProduct);
            $pozycja = array_flip($kolejnosc);
            $koniec = count($kolejnosc);

            usort($cechy, static function (array $a, array $b) use ($nazwyCech, $pozycja, $koniec): int {
                $ida = $nazwyCech[$a['name'] ?? ''] ?? 0;
                $idb = $nazwyCech[$b['name'] ?? ''] ?? 0;

                return ($pozycja[$ida] ?? $koniec) <=> ($pozycja[$idb] ?? $koniec);
            });
        }

        return $limit > 0 ? array_slice($cechy, 0, $limit) : $cechy;
    }

    /**
     * Nazwy cech produktu w kolejności rankingu.
     *
     * Cechy spoza rankingu nie znikają — idą za nim, w kolejności katalogu.
     * Bez tego produkt z cechą, której kategoria jeszcze nie policzyła, pokazywałby
     * mniej wierszy niż sąsiedni, bez widocznego powodu.
     *
     * @return string[]
     */
    public function nazwy(int $idProduct, int $idShop, int $idLang): array
    {
        $ranking = $this->kolejnosc($idProduct, $idShop);

        if ($ranking === []) {
            return [];
        }

        $nazwy = [];

        foreach ($this->nazwyCech($idProduct, $idLang) as $nazwa => $idFeature) {
            $pozycja = array_search($idFeature, $ranking, true);

            if ($pozycja !== false) {
                $nazwy[$pozycja] = $nazwa;
            }
        }

        ksort($nazwy);

        return array_values($nazwy);
    }

    /**
     * Kolejność cech dla kategorii, w której produkt jest oglądany.
     *
     * @return int[]
     */
    public function kolejnosc(int $idProduct, int $idShop): array
    {
        foreach ($this->kategorieDoSprawdzenia($idProduct, $idShop) as $idCategory) {
            $this->przeliczJesliTrzeba($idCategory, $idShop);

            $ranking = $this->storage->dlaKategorii($idCategory, $idShop);

            if ($ranking !== []) {
                return $ranking;
            }
        }

        return [];
    }

    /**
     * Przelicza kategorię zaznaczoną po zmianie w katalogu.
     *
     * Leniwie, przy pierwszym odczycie, a nie przy zapisie produktu. Import
     * pięciu tysięcy towarów zaznacza kilkadziesiąt kategorii i nie liczy nic;
     * koszt płaci pierwszy klient, który wejdzie na produkt z takiej kategorii,
     * i płaci go raz.
     *
     * Zaznaczenie zdejmujemy PRZED liczeniem: gdyby liczenie się wywróciło,
     * kolejne żądania próbowałyby w kółko i zamieniłyby jedną awarię w awarię
     * przy każdym wejściu na sklep.
     */
    private function przeliczJesliTrzeba(int $idCategory, int $idShop): void
    {
        if (!in_array($idCategory, $this->ustawienia->doPrzeliczenia(), true)) {
            return;
        }

        $this->ustawienia->odznacz($idCategory);
        $this->storage->zapisz($idCategory, $idShop, (new Calculator())->dlaKategorii($idCategory, $idShop));
    }

    /**
     * Kategorie od najbardziej konkretnej do najogólniejszej.
     *
     * Kolejność sprawdzania: kategoria z kontekstu wejścia, potem domyślna
     * produktu, potem ich przodkowie. Zejście do przodka jest tu, bo kategoria
     * z kilkoma produktami nie daje statystyki — a jej rodzic zwykle daje.
     *
     * @return int[]
     */
    private function kategorieDoSprawdzenia(int $idProduct, int $idShop): array
    {
        $sciezka = [];

        foreach ([$this->zKontekstu($idProduct), $this->domyslna($idProduct, $idShop)] as $idCategory) {
            if ($idCategory === null) {
                continue;
            }

            foreach ($this->zPrzodkami($idCategory) as $id) {
                $sciezka[$id] = $id;
            }
        }

        return array_values($sciezka);
    }

    /**
     * Kategoria, z której klient wszedł na produkt.
     *
     * PrestaShop podaje ją w parametrze `id_category` przy przejściu z listingu.
     * Sprawdzamy, czy produkt naprawdę do niej należy: parametr przychodzi
     * z adresu, więc bez tego dowolna liczba w URL-u decydowałaby o tym, co widać
     * na karcie.
     */
    private function zKontekstu(int $idProduct): ?int
    {
        $idCategory = (int) Tools::getValue('id_category');

        if ($idCategory <= 0) {
            return null;
        }

        $sql = sprintf(
            'SELECT 1 FROM `%scategory_product` WHERE id_product = %d AND id_category = %d',
            _DB_PREFIX_,
            $idProduct,
            $idCategory
        );

        return Db::getInstance()->getValue($sql) ? $idCategory : null;
    }

    private function domyslna(int $idProduct, int $idShop): ?int
    {
        $sql = sprintf(
            'SELECT id_category_default FROM `%sproduct_shop` WHERE id_product = %d AND id_shop = %d',
            _DB_PREFIX_,
            $idProduct,
            $idShop
        );

        $id = (int) Db::getInstance()->getValue($sql);

        return $id > 0 ? $id : null;
    }

    /**
     * Kategoria i jej przodkowie, od niej w górę, bez korzenia i katalogu głównego.
     *
     * @return int[]
     */
    private function zPrzodkami(int $idCategory): array
    {
        $idLang = (int) Context::getContext()->language->id;
        $sciezka = [$idCategory];

        foreach ((new Category($idCategory, $idLang))->getParentsCategories($idLang) as $rodzic) {
            $id = (int) $rodzic['id_category'];

            if ($id <= (int) Category::getRootCategory()->id || $id === $idCategory) {
                continue;
            }

            $sciezka[] = $id;
        }

        return $sciezka;
    }

    /**
     * Nazwa cechy → jej identyfikator, dla cech tego produktu.
     *
     * Rdzeń podaje szablonowi `grouped_features` bez `id_feature` — zostaje nazwa.
     * Mapowanie robimy więc raz, po nazwach cech tego jednego produktu, zamiast
     * przepisywać całe `grouped_features` w rdzeniu.
     *
     * @return array<string,int>
     */
    private function nazwyCech(int $idProduct, ?int $idLang = null): array
    {
        $idLang = $idLang ?? (int) Context::getContext()->language->id;

        $sql = sprintf(
            'SELECT DISTINCT fp.id_feature, fl.name
             FROM `%1$sfeature_product` fp
             JOIN `%1$sfeature_lang` fl ON fl.id_feature = fp.id_feature AND fl.id_lang = %2$d
             WHERE fp.id_product = %3$d',
            _DB_PREFIX_,
            $idLang,
            $idProduct
        );

        $wynik = Db::getInstance()->executeS($sql);
        $mapa = [];

        if (is_array($wynik)) {
            foreach ($wynik as $wiersz) {
                $mapa[(string) $wiersz['name']] = (int) $wiersz['id_feature'];
            }
        }

        return $mapa;
    }
}
