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

/**
 * Zapis i odczyt wyliczonego rankingu.
 *
 * Ranking jest liczony w tle i czytany przy każdej karcie produktu, więc siedzi
 * w tabeli, a nie w cache'u aplikacji: cache bywa czyszczony przy każdym wdrożeniu
 * i pierwszy klient po czyszczeniu płaciłby przeliczeniem całej kategorii za to,
 * że ktoś wgrał nową wersję motywu.
 */
final class Storage
{
    public const TABELA = 'quince_feature_rank';

    /**
     * Podmienia ranking jednej kategorii.
     *
     * Kasujemy i wstawiamy w jednej transakcji, bo między kasowaniem a wstawieniem
     * karta produktu czytająca tę kategorię zobaczyłaby pustkę i pokazała cechy
     * w kolejności katalogu.
     *
     * @param array<int,array{id_feature:int,pokrycie:float,rozdzielczosc:float,wynik:float}> $wiersze
     */
    public function zapisz(int $idCategory, int $idShop, array $wiersze): void
    {
        $db = Db::getInstance();
        $tabela = _DB_PREFIX_ . self::TABELA;

        $db->execute('START TRANSACTION');

        try {
            $db->execute(sprintf(
                'DELETE FROM `%s` WHERE id_category = %d AND id_shop = %d',
                $tabela,
                $idCategory,
                $idShop
            ));

            $pozycja = 0;

            foreach ($wiersze as $wiersz) {
                ++$pozycja;

                $db->insert(self::TABELA, [
                    'id_category' => $idCategory,
                    'id_shop' => $idShop,
                    'id_feature' => $wiersz['id_feature'],
                    'coverage' => $wiersz['pokrycie'],
                    'resolution' => $wiersz['rozdzielczosc'],
                    'score' => $wiersz['wynik'],
                    'position' => $pozycja,
                    'computed_at' => date('Y-m-d H:i:s'),
                ]);
            }

            $db->execute('COMMIT');
        } catch (\Throwable $e) {
            $db->execute('ROLLBACK');

            throw $e;
        }
    }

    /**
     * Identyfikatory cech w kolejności rankingu. Pusta tablica = nic policzonego.
     *
     * @return int[]
     */
    public function dlaKategorii(int $idCategory, int $idShop): array
    {
        $sql = sprintf(
            'SELECT id_feature FROM `%s` WHERE id_category = %d AND id_shop = %d ORDER BY position ASC',
            _DB_PREFIX_ . self::TABELA,
            $idCategory,
            $idShop
        );

        $wynik = Db::getInstance()->executeS($sql);

        if (!is_array($wynik)) {
            return [];
        }

        return array_map(static fn (array $w): int => (int) $w['id_feature'], $wynik);
    }

    /** Kiedy kategoria była liczona ostatni raz; null = nigdy. */
    public function policzonaKiedy(int $idCategory, int $idShop): ?string
    {
        $sql = sprintf(
            'SELECT MAX(computed_at) FROM `%s` WHERE id_category = %d AND id_shop = %d',
            _DB_PREFIX_ . self::TABELA,
            $idCategory,
            $idShop
        );

        $wartosc = Db::getInstance()->getValue($sql);

        return $wartosc === false || $wartosc === null || $wartosc === '' ? null : (string) $wartosc;
    }
}
