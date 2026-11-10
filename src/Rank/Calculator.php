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
 * Liczy, które cechy najwięcej mówią o produkcie w danej kategorii.
 *
 * Pytanie brzmi: z kilkunastu cech wypełnionych przy produkcie, które pokazać pod
 * opisem? Odpowiedź nie może być stała dla całego sklepu, bo ta sama cecha znaczy
 * co innego w różnych działach. „Rodzaj: filtr oleju" w kategorii filtrów oleju
 * jest prawdą, która nie mówi nic — wszystkie tam takie są. Ta sama cecha
 * w kategorii „Filtry" dzieli towar na oleju, paliwa i powietrza i staje się
 * pierwszą rzeczą, na którą klient patrzy.
 *
 * Reguła jest prosta i da się ją powiedzieć jednym zdaniem: pokazujemy cechy
 * wypełnione u największej liczby produktów, pomijając te, które u wszystkich mają
 * tę samą wartość.
 *
 * Czego tu świadomie NIE MA: progu minimalnej wielkości kategorii. Kategoria
 * z czterema produktami, w której każdy ma wpisaną średnicę gwintu, jest doskonale
 * opisana — odbicie jej do kategorii nadrzędnej pokazywałoby cechy dobrane pod
 * inny, szerszy zbiór towaru. Do rodzica schodzimy wyłącznie wtedy, gdy kategoria
 * nie ma żadnych cech.
 */
final class Calculator
{
    public function __construct(
        private readonly RankSettings $ustawienia = new RankSettings(),
        private readonly Overrides $nadpisania = new Overrides()
    ) {
    }

    /**
     * Przelicza jedną kategorię i zwraca wiersze gotowe do zapisu.
     *
     * @return array<int,array{id_feature:int,pokrycie:float,rozdzielczosc:float,wynik:float}>
     */
    public function dlaKategorii(int $idCategory, int $idShop): array
    {
        $wKategorii = $this->produktowWKategorii($idCategory, $idShop);

        if ($wKategorii === 0) {
            return [];
        }

        $wiersze = [];
        $nadpisane = $this->nadpisania->dlaKategorii($idCategory, $idShop);

        foreach ($this->surowe($idCategory, $idShop) as $wiersz) {
            $idFeature = (int) $wiersz['id_feature'];
            $zCecha = (int) $wiersz['produktow_z_cecha'];
            $wartosci = (int) $wiersz['roznych_wartosci'];
            $pokrycie = $zCecha / $wKategorii;
            $stan = $nadpisane[$idFeature] ?? '';

            // Decyzja sklepu przebija warunki w obie strony: pominięta wypada
            // niezależnie od wyniku, przypięta wchodzi bez sprawdzania.
            if ($stan === Overrides::POMINIETA) {
                continue;
            }

            if ($stan !== Overrides::PRZYPIETA
                && ($pokrycie < $this->ustawienia->minPokrycie() || !$this->odrozniaProdukty($wartosci))) {
                continue;
            }

            $wiersze[] = [
                'id_feature' => (int) $wiersz['id_feature'],
                'pokrycie' => round($pokrycie, 4),
                'rozdzielczosc' => round($this->rozdzielczosc($wartosci, $zCecha), 4),
                // Kolejność stanowi wypełnienie — cecha wpisana u wszystkich jest
                // pewniejsza niż wpisana u dwóch trzecich. Liczba wartości rozstrzyga
                // remis, bo przy równym wypełnieniu więcej znaczy więcej do porównania.
                //
                // Przypięta idzie przed wszystkie wyliczone: sklep, który powiedział
                // „tę pokazuj zawsze", ma ją zobaczyć na górze, a nie na piątym
                // miejscu za cechami, które akurat wypadły lepiej w statystyce.
                'wynik' => round(($stan === Overrides::PRZYPIETA ? 100 : 0)
                    + $pokrycie + min($wartosci, 20) / 1000, 4),
            ];
        }

        usort($wiersze, static fn (array $a, array $b): int => $b['wynik'] <=> $a['wynik']);

        return $wiersze;
    }

    /**
     * Czy cecha w ogóle dzieli zbiór na cokolwiek.
     *
     * Warunek włącza sklep: cecha o tej samej wartości u wszystkich jest prawdziwa
     * i nic nie mówi o TYM produkcie, ale bywają katalogi, w których i taka informacja
     * ma stać na karcie — stąd przełącznik, a nie reguła na sztywno.
     *
     * Czego tu świadomie NIE MA: odrzucania cech o wartości unikatowej dla każdego
     * produktu. Taka reguła kasowałaby „Wymiary", „Wagę" i „Długość przewodu" —
     * czyli często najważniejsze, co klient chce zobaczyć — a jedyne, co miałaby
     * łapać, to dane, których w cechach się nie trzyma: numer produktu, EAN i kod
     * producenta mają w PrestaShopie własne pola.
     */
    private function odrozniaProdukty(int $wartosci): bool
    {
        return !$this->ustawienia->pomijajJednakowe() || $wartosci >= 2;
    }

    /**
     * Na ile cecha dzieli kategorię, w skali 0–1 — wyłącznie do pokazania na ekranie.
     *
     * Nie bierze udziału w kolejności; służy do wytłumaczenia sprzedawcy, czym się
     * różni cecha o dwóch wartościach od cechy o dwunastu.
     */
    private function rozdzielczosc(int $wartosci, int $produktow): float
    {
        if ($produktow < 2 || $wartosci < 2) {
            return 0.0;
        }

        return min(1.0, ($wartosci - 1) / max(1, $produktow - 1));
    }

    /** Ilu aktywnych produktów jest w kategorii. */
    private function produktowWKategorii(int $idCategory, int $idShop): int
    {
        $sql = 'SELECT COUNT(DISTINCT cp.id_product)
                FROM ' . _DB_PREFIX_ . 'category_product cp
                JOIN ' . _DB_PREFIX_ . 'product_shop ps
                  ON ps.id_product = cp.id_product AND ps.id_shop = ' . $idShop . '
                WHERE cp.id_category = ' . $idCategory . ' AND ps.active = 1';

        return (int) Db::getInstance()->getValue($sql);
    }

    /**
     * Wypełnienie i liczba różnych wartości każdej cechy w kategorii.
     *
     * Liczymy po `id_feature_value`, nie po tekście: ta sama wartość ma jeden
     * identyfikator we wszystkich językach, więc wynik nie zależy od tego, ile
     * języków sklep ma włączonych ani czy tłumaczenia są kompletne.
     *
     * @return array<int,array<string,string>>
     */
    private function surowe(int $idCategory, int $idShop): array
    {
        $sql = 'SELECT fp.id_feature,
                       COUNT(DISTINCT fp.id_product) AS produktow_z_cecha,
                       COUNT(DISTINCT fp.id_feature_value) AS roznych_wartosci
                FROM ' . _DB_PREFIX_ . 'category_product cp
                JOIN ' . _DB_PREFIX_ . 'product_shop ps
                  ON ps.id_product = cp.id_product AND ps.id_shop = ' . $idShop . '
                JOIN ' . _DB_PREFIX_ . 'feature_product fp
                  ON fp.id_product = cp.id_product
                WHERE cp.id_category = ' . $idCategory . ' AND ps.active = 1
                GROUP BY fp.id_feature';

        $wynik = Db::getInstance()->executeS($sql);

        return is_array($wynik) ? $wynik : [];
    }
}
