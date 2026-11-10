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

use Db;
use Quince\FeatureRank\Settings\RankSettings;

/**
 * Co naprawdę siedzi w kategorii — dane dla ekranu, nie dla rankingu.
 *
 * `Calculator` liczy wynik i odrzuca, co nie przeszło progów. Do wyjaśnienia
 * sprzedawcy, DLACZEGO na karcie produktu widzi akurat te cechy, to nie
 * wystarcza: odrzucone są równie ważne jak przyjęte, a same procenty nic nie
 * mówią, dopóki nie widać, jakie wartości się za nimi kryją.
 *
 * Ta klasa pokazuje więc wszystko: każdą cechę obecną w kategorii, ilu produktom
 * jest wypełniona, jakie przyjmuje wartości i co z nią zrobiliśmy.
 */
final class Podglad
{
    public function __construct(private readonly RankSettings $ustawienia = new RankSettings())
    {
    }

    /** Ile przykładowych wartości pokazujemy przy cesze. */
    private const PRZYKLADOW = 4;

    /**
     * Wiersze do tabeli: cechy kategorii z werdyktem.
     *
     * @return array{produktow:int,cechy:array<int,array<string,mixed>>}
     */
    public function dlaKategorii(int $idCategory, int $idShop, int $idLang): array
    {
        $produktow = $this->produktow($idCategory, $idShop);
        $ranking = array_flip((new Storage())->dlaKategorii($idCategory, $idShop));
        $nadpisane = (new Overrides())->dlaKategorii($idCategory, $idShop);
        $cechy = [];

        foreach ($this->surowe($idCategory, $idShop, $idLang) as $w) {
            $idFeature = (int) $w['id_feature'];
            $zCecha = (int) $w['produktow_z_cecha'];
            $wartosci = (int) $w['roznych_wartosci'];

            $cechy[] = [
                'id' => $idFeature,
                'stan' => $nadpisane[$idFeature] ?? '',
                'nazwa' => (string) $w['nazwa'],
                'produktow' => $zCecha,
                'wartosci' => $wartosci,
                'przyklady' => $this->przyklady((string) $w['lista_wartosci']),
                'pozycja' => isset($ranking[$idFeature]) ? $ranking[$idFeature] + 1 : null,
                'powod' => $this->powod(
                    $produktow,
                    $zCecha,
                    $wartosci,
                    isset($ranking[$idFeature]),
                    $nadpisane[$idFeature] ?? ''
                ),
            ];
        }

        // Najpierw to, co pokazujemy, w kolejności rankingu; potem odrzucone.
        usort($cechy, static function (array $a, array $b): int {
            return ($a['pozycja'] ?? PHP_INT_MAX) <=> ($b['pozycja'] ?? PHP_INT_MAX);
        });

        return [
            'produktow' => $produktow,
            'cechy' => $cechy,
            'przyklad' => $this->przykladowyProdukt($idCategory, $idShop, $idLang, $cechy),
        ];
    }

    /**
     * Jak lista cech wyjdzie na karcie konkretnego produktu z tej kategorii.
     *
     * Sam ranking nie odpowiada na pytanie „co będę miał na stronie": pokazuje
     * nazwy cech, a nie wartości, i nie mówi, ile z nich ten konkretny produkt
     * naprawdę ma wypełnione. Bierzemy więc pierwszy produkt z kategorii i składamy
     * dokładnie to, co zobaczy klient.
     *
     * @param array<int,array<string,mixed>> $cechy cechy kategorii w kolejności rankingu
     *
     * @return array{nazwa:string,wiersze:array<int,array{nazwa:string,wartosc:string}>}|null
     */
    private function przykladowyProdukt(int $idCategory, int $idShop, int $idLang, array $cechy, int $limit = 5): ?array
    {
        // Bez własnego `LIMIT 1`: `getRow()` dokłada go sam i dwa razem to błąd
        // składni, który wychodzi dopiero na ekranie.
        $produkt = Db::getInstance()->getRow(
            'SELECT p.id_product, pl.name
             FROM ' . _DB_PREFIX_ . 'category_product cp
             JOIN ' . _DB_PREFIX_ . 'product p ON p.id_product = cp.id_product
             JOIN ' . _DB_PREFIX_ . 'product_shop ps
               ON ps.id_product = p.id_product AND ps.id_shop = ' . $idShop . ' AND ps.active = 1
             JOIN ' . _DB_PREFIX_ . 'product_lang pl
               ON pl.id_product = p.id_product AND pl.id_lang = ' . $idLang . '
              AND pl.id_shop = ' . $idShop . '
             JOIN ' . _DB_PREFIX_ . 'feature_product fp ON fp.id_product = p.id_product
             WHERE cp.id_category = ' . $idCategory
        );

        if (!$produkt) {
            return null;
        }

        $wartosci = $this->wartosciProduktu((int) $produkt['id_product'], $idLang);
        $wiersze = [];

        foreach ($cechy as $cecha) {
            if ($cecha['pozycja'] === null || !isset($wartosci[$cecha['nazwa']])) {
                continue;
            }

            $wiersze[] = ['nazwa' => $cecha['nazwa'], 'wartosc' => $wartosci[$cecha['nazwa']]];

            if (count($wiersze) >= $limit) {
                break;
            }
        }

        return ['nazwa' => (string) $produkt['name'], 'wiersze' => $wiersze];
    }

    /**
     * Nazwa cechy => wartość, dla jednego produktu.
     *
     * @return array<string,string>
     */
    private function wartosciProduktu(int $idProduct, int $idLang): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT fl.name, fvl.value
             FROM ' . _DB_PREFIX_ . 'feature_product fp
             JOIN ' . _DB_PREFIX_ . 'feature_lang fl
               ON fl.id_feature = fp.id_feature AND fl.id_lang = ' . $idLang . '
             JOIN ' . _DB_PREFIX_ . 'feature_value_lang fvl
               ON fvl.id_feature_value = fp.id_feature_value AND fvl.id_lang = ' . $idLang . '
             WHERE fp.id_product = ' . $idProduct
        );

        $out = [];

        foreach (is_array($wiersze) ? $wiersze : [] as $w) {
            $out[(string) $w['name']] = (string) $w['value'];
        }

        return $out;
    }

    /**
     * Powód po ludzku — jedno zdanie, którym da się to komuś wytłumaczyć.
     *
     * Kolejność sprawdzania odpowiada kolejności odsiewania w `Calculator`, więc
     * sprzedawca widzi ten warunek, który naprawdę zadecydował, a nie pierwszy
     * z brzegu, który akurat też jest prawdziwy.
     */
    private function powod(int $produktow, int $zCecha, int $wartosci, bool $wRankingu, string $stan = ''): string
    {
        if ($wRankingu) {
            return 'shown';
        }

        if ($stan === Overrides::POMINIETA) {
            return 'skipped';
        }

        if ($produktow > 0 && $zCecha / $produktow < $this->ustawienia->minPokrycie()) {
            return 'rarely-filled';
        }

        if ($wartosci < 2) {
            return 'all-the-same';
        }

        return 'weak';
    }

    /**
     * Kilka pierwszych wartości z listy sklejonej przez bazę.
     *
     * @return string[]
     */
    private function przyklady(string $lista): array
    {
        if ($lista === '') {
            return [];
        }

        $wszystkie = array_map('trim', explode('|', $lista));

        return array_slice(array_filter($wszystkie), 0, self::PRZYKLADOW);
    }

    private function produktow(int $idCategory, int $idShop): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(DISTINCT cp.id_product)
             FROM ' . _DB_PREFIX_ . 'category_product cp
             JOIN ' . _DB_PREFIX_ . 'product_shop ps
               ON ps.id_product = cp.id_product AND ps.id_shop = ' . $idShop . '
             WHERE cp.id_category = ' . $idCategory . ' AND ps.active = 1'
        );
    }

    /**
     * Cechy kategorii wraz z listą wartości.
     *
     * Wartości sklejamy w bazie, a nie osobnym zapytaniem na cechę: kategoria
     * z trzydziestoma cechami dałaby trzydzieści zapytań na jedno otwarcie ekranu.
     *
     * @return array<int,array<string,string>>
     */
    private function surowe(int $idCategory, int $idShop, int $idLang): array
    {
        $sql = 'SELECT fp.id_feature,
                       fl.name AS nazwa,
                       COUNT(DISTINCT fp.id_product) AS produktow_z_cecha,
                       COUNT(DISTINCT fp.id_feature_value) AS roznych_wartosci,
                       SUBSTRING_INDEX(
                           GROUP_CONCAT(DISTINCT fvl.value ORDER BY fvl.value SEPARATOR "|"),
                           "|", ' . self::PRZYKLADOW . '
                       ) AS lista_wartosci
                FROM ' . _DB_PREFIX_ . 'category_product cp
                JOIN ' . _DB_PREFIX_ . 'product_shop ps
                  ON ps.id_product = cp.id_product AND ps.id_shop = ' . $idShop . '
                JOIN ' . _DB_PREFIX_ . 'feature_product fp
                  ON fp.id_product = cp.id_product
                JOIN ' . _DB_PREFIX_ . 'feature_lang fl
                  ON fl.id_feature = fp.id_feature AND fl.id_lang = ' . $idLang . '
                LEFT JOIN ' . _DB_PREFIX_ . 'feature_value_lang fvl
                  ON fvl.id_feature_value = fp.id_feature_value AND fvl.id_lang = ' . $idLang . '
                WHERE cp.id_category = ' . $idCategory . ' AND ps.active = 1
                GROUP BY fp.id_feature
                ORDER BY produktow_z_cecha DESC';

        $wynik = Db::getInstance()->executeS($sql);

        return is_array($wynik) ? $wynik : [];
    }
}
